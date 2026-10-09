<?php

namespace App\Services\Coupon;

use App\Models\Coupon;
use App\Models\CouponCode;
use App\Models\EventRegistration;
use App\Models\ShopOrder;
use App\Services\AdminLogger;
use App\Support\CouponDiscount;
use App\Support\PaymentFigures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Claiming one use of a coupon, safely, while other people are submitting.
 *
 * The last remaining code is a contended resource. Two registrations submitted in the
 * same second must not both be given it, and a check done before the transaction is
 * worth nothing: by the time the write lands the answer it was based on is history.
 *
 * So every claim runs inside a transaction and takes a row lock on the code it is
 * about to spend, and the expiry and the remaining count are BOTH re-read inside that
 * transaction. The caller that loses the race gets CouponOutcome::RAN_OUT, which is a
 * value rather than an exception precisely so the public form can fall back to the
 * normal price without catching anything.
 *
 * WHAT IS CLAIMED
 *
 *   quantity > 0  the next unused minted code, locked with lockForUpdate and stamped.
 *                 Nobody else can take the same row, because the lock is held until
 *                 the commit.
 *   quantity = 0  a fresh row recording the use. Nothing is contended — the batch name
 *                 is a shared code with no limit — so there is nothing to lock beyond
 *                 the insert itself.
 *
 * Nothing in here works out what a discount is worth. CouponDiscount does that, and it
 * is handed the charge by the caller, so the figure on the registration and the figure
 * on screen come from one place.
 */
class CouponRedeemer
{
    /**
     * Claim one use of a batch and record what it gave.
     *
     * @param  float  $charge  what is owed before the discount
     * @param  int  $times  how many heads a fixed discount is owed for; see CouponDiscount
     */
    public function claim(
        Coupon $coupon,
        float $charge,
        int $times = 1,
        ?EventRegistration $registration = null,
        ?ShopOrder $order = null,
    ): CouponOutcome {
        if (round($charge, 2) <= 0) {
            return CouponOutcome::failed(CouponOutcome::NOTHING_TO_DISCOUNT);
        }

        $discount = CouponDiscount::on($coupon, $charge, $times);

        if ($discount <= 0) {
            return CouponOutcome::failed(CouponOutcome::NOTHING_TO_DISCOUNT);
        }

        $outcome = DB::transaction(function () use ($coupon, $discount, $registration, $order) {
            /*
             | The batch itself, re-read under a lock.
             |
             | The expiry is checked off this row rather than off the one the caller
             | handed in: a batch edited while a visitor had the form open must not be
             | spent on yesterday's terms.
             */
            $locked = Coupon::query()->whereKey($coupon->id)->lockForUpdate()->first();

            if ($locked === null) {
                return CouponOutcome::failed(CouponOutcome::NOT_FOUND);
            }

            if ($locked->isExpired()) {
                return CouponOutcome::failed(CouponOutcome::EXPIRED);
            }

            if ($locked->isUnlimited()) {
                /*
                 | Nothing to contend for. The batch name is the code, there is no
                 | remaining count, and the row is a record of the use rather than a
                 | claim on a finite thing.
                 */
                $code = CouponCode::create([
                    'coupon_id' => $locked->id,
                    'code' => null,
                    'redeemed_at' => now(),
                    'discount_amount' => $discount,
                    'event_registration_id' => $registration?->id,
                    'shop_order_id' => $order?->id,
                ]);

                return CouponOutcome::ok($code, $discount);
            }

            /*
             | The next unused code, locked so nobody else can take this row.
             |
             | skipLocked is deliberately NOT used. Two claims arriving together must
             | serialise rather than one of them skipping past to the next code: with
             | one code left there is nothing to skip to, and the second caller has to
             | see the stamped row and be told it ran out.
             */
            $claimed = CouponCode::query()
                ->where('coupon_id', $locked->id)
                ->whereNull('redeemed_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($claimed === null) {
                return CouponOutcome::failed(CouponOutcome::RAN_OUT);
            }

            $claimed->redeemed_at = now();
            $claimed->discount_amount = $discount;
            $claimed->event_registration_id = $registration?->id;
            $claimed->shop_order_id = $order?->id;
            $claimed->save();

            return CouponOutcome::ok($claimed, $discount);
        });

        if ($outcome->succeeded()) {
            $this->log($coupon, $outcome, $registration, $order);
        }

        return $outcome;
    }

    /**
     * Claim one use by the code somebody typed.
     *
     * Looks the string up in both namespaces, because a buyer types into one box: a
     * minted code for a limited batch, or the batch name itself for an unlimited one.
     *
     * @param  string  $kind  Coupon::KIND_EVENT or Coupon::KIND_SHOP
     */
    public function claimByCode(
        string $typed,
        string $kind,
        float $charge,
        int $times = 1,
        ?EventRegistration $registration = null,
        ?ShopOrder $order = null,
    ): CouponOutcome {
        $typed = Str::upper(trim($typed));

        if ($typed === '') {
            return CouponOutcome::failed(CouponOutcome::NOT_FOUND);
        }

        $minted = CouponCode::query()->with('coupon')->where('code', $typed)->first();

        if ($minted !== null) {
            $coupon = $minted->coupon;

            if ($coupon === null) {
                return CouponOutcome::failed(CouponOutcome::NOT_FOUND);
            }

            if ($coupon->kind !== $kind) {
                return CouponOutcome::failed(CouponOutcome::WRONG_KIND);
            }

            if ($minted->isRedeemed()) {
                return CouponOutcome::failed(CouponOutcome::ALREADY_USED);
            }

            return $this->claimMinted($minted, $coupon, $charge, $times, $registration, $order);
        }

        $batch = Coupon::query()->where('name', $typed)->first();

        if ($batch === null) {
            return CouponOutcome::failed(CouponOutcome::NOT_FOUND);
        }

        if ($batch->kind !== $kind) {
            return CouponOutcome::failed(CouponOutcome::WRONG_KIND);
        }

        /*
         | The batch name is only a usable code when the batch is unlimited. On a
         | limited batch it is a label for the minted codes, so typing it is the same
         | mistake as typing the event's name.
         */
        if (! $batch->isUnlimited()) {
            return CouponOutcome::failed(CouponOutcome::NOT_FOUND);
        }

        return $this->claim($batch, $charge, $times, $registration, $order);
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * Claim one named minted code, race-safe.
     *
     * Separate from claim() because the row is named rather than chosen: two people
     * who were both given ABC123 by mistake must not both get it, so the conditional
     * UPDATE is fenced on redeemed_at still being null and the loser is told the code
     * is already used rather than that the batch ran out.
     */
    private function claimMinted(
        CouponCode $minted,
        Coupon $coupon,
        float $charge,
        int $times,
        ?EventRegistration $registration,
        ?ShopOrder $order,
    ): CouponOutcome {
        if (round($charge, 2) <= 0) {
            return CouponOutcome::failed(CouponOutcome::NOTHING_TO_DISCOUNT);
        }

        $discount = CouponDiscount::on($coupon, $charge, $times);

        if ($discount <= 0) {
            return CouponOutcome::failed(CouponOutcome::NOTHING_TO_DISCOUNT);
        }

        $outcome = DB::transaction(function () use ($minted, $coupon, $discount, $registration, $order) {
            $locked = Coupon::query()->whereKey($coupon->id)->lockForUpdate()->first();

            if ($locked === null) {
                return CouponOutcome::failed(CouponOutcome::NOT_FOUND);
            }

            if ($locked->isExpired()) {
                return CouponOutcome::failed(CouponOutcome::EXPIRED);
            }

            $row = CouponCode::query()
                ->whereKey($minted->id)
                ->whereNull('redeemed_at')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return CouponOutcome::failed(CouponOutcome::ALREADY_USED);
            }

            $row->redeemed_at = now();
            $row->discount_amount = $discount;
            $row->event_registration_id = $registration?->id;
            $row->shop_order_id = $order?->id;
            $row->save();

            return CouponOutcome::ok($row, $discount);
        });

        if ($outcome->succeeded()) {
            $this->log($coupon, $outcome, $registration, $order);
        }

        return $outcome;
    }

    /**
     * Every redemption leaves a trail entry.
     *
     * Outside the transaction, so a logging failure cannot roll back a claim that has
     * already been committed, and so the lock is released as early as possible.
     */
    private function log(
        Coupon $coupon,
        CouponOutcome $outcome,
        ?EventRegistration $registration,
        ?ShopOrder $order,
    ): void {
        $target = $registration?->reference ?? $order?->reference ?? 'no record';

        AdminLogger::activity('coupons.redeem', sprintf(
            'Coupon %s redeemed on %s for %s.',
            $outcome->code?->codeLabel() ?? $coupon->name,
            $target,
            PaymentFigures::money($outcome->discount),
        ));

        AdminLogger::audit($coupon, 'coupon.redeemed', null, [
            'coupon' => $coupon->name,
            'code' => $outcome->code?->codeLabel(),
            'discount' => $outcome->discount,
            'used_on' => $target,
            'remaining' => $coupon->fresh()?->remaining(),
        ]);
    }
}
