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
 * The last remaining use is a contended resource. Two registrations submitted in the
 * same second must not both be given it, and a check done before the transaction is
 * worth nothing: by the time the write lands the answer it was based on is history.
 *
 * So every claim runs inside a transaction and takes a row lock on the BATCH, and the
 * expiry and the number of uses already spent are BOTH re-read inside that
 * transaction. The caller that loses the race gets CouponOutcome::RAN_OUT, which is a
 * value rather than an exception precisely so the public form can fall back to the
 * normal price without catching anything.
 *
 * WHAT IS CLAIMED
 *
 * One ledger row, written at the moment of use. `quantity` is the cap on how many such
 * rows a batch may have; 0 means there is no cap. Nothing is pre-minted, so there is
 * no row to hunt for and no row to lock — the batch lock is what serialises the count
 * and the insert, and it is held until the commit.
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

            /*
             | The cap, counted under the same lock that is about to write the row.
             |
             | This is the whole of the race protection. Two claims arriving together
             | serialise on the batch row, so the second one counts the first one's
             | committed ledger row and is told it ran out — rather than both reading
             | "one left" and both writing.
             |
             | Counted rather than cached on the batch: a stored counter is a second
             | source of truth for money, and the ledger is already the record Tracking
             | and Report read.
             */
            if (! $locked->isUnlimited()) {
                $spent = CouponCode::query()
                    ->where('coupon_id', $locked->id)
                    ->whereNotNull('redeemed_at')
                    ->count();

                if ($spent >= (int) $locked->quantity) {
                    return CouponOutcome::failed(CouponOutcome::RAN_OUT);
                }
            }

            /*
             | The row records the use. `code` holds the string that was typed, which
             | is the batch name, so every ledger line still shows a code and the trail
             | survives a later rename.
             */
            $code = CouponCode::create([
                'coupon_id' => $locked->id,
                'code' => $locked->name,
                'redeemed_at' => now(),
                'discount_amount' => $discount,
                'event_registration_id' => $registration?->id,
                'shop_order_id' => $order?->id,
            ]);

            return CouponOutcome::ok($code, $discount);
        });

        if ($outcome->succeeded()) {
            $this->log($coupon, $outcome, $registration, $order);
        }

        return $outcome;
    }

    /**
     * Claim one use by the code somebody typed.
     *
     * The code is the batch name, matched case-insensitively with the whitespace
     * trimmed, because it is read off a poster and typed back in.
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

        $batch = Coupon::query()->where('name', $typed)->first();

        if ($batch === null) {
            return CouponOutcome::failed(CouponOutcome::NOT_FOUND);
        }

        if ($batch->kind !== $kind) {
            return CouponOutcome::failed(CouponOutcome::WRONG_KIND);
        }

        return $this->claim($batch, $charge, $times, $registration, $order);
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

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
