<?php

namespace App\Services\Coupon;

use App\Models\Coupon;
use App\Models\CouponCode;
use App\Models\CouponIssuedCode;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\ShopOrder;
use App\Services\AdminLogger;
use App\Support\CouponDiscount;
use App\Support\PaymentFigures;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Claiming uses of a coupon, safely, while other people are submitting.
 *
 * The last remaining use is a contended resource. Two registrations submitted in the
 * same second must not both be given it, and a check done before the transaction is
 * worth nothing: by the time the write lands the answer it was based on is history.
 *
 * So every claim runs inside a transaction and takes a row lock on the BATCH, and the
 * expiry, the number of uses already spent and — in unique mode — the holder's unused
 * balance are ALL re-read inside that transaction. The caller that loses the race gets
 * CouponOutcome::RAN_OUT, which is a value rather than an exception precisely so the
 * public form can fall back to the normal price without catching anything.
 *
 * A USE IS A PARTICIPANT
 *
 * On an event that charges add-ons per participant the discount already applies per
 * head, so the cap has to count the same way or the cap and the money disagree. A
 * group of ten entering one code once charges TEN uses and writes TEN ledger rows,
 * each naming the person it covered and holding that person's share of the discount.
 * On an event that does not charge per participant, one registration is one use.
 *
 * That is what makes the sponsor's arithmetic come out: a thousand codes fund a hundred
 * groups of ten, which at RM7.50 a head is exactly RM7,500 — the committed figure, with
 * no blow-out.
 *
 * A PARTIAL APPLICATION IS REFUSED
 *
 * Ten participants against five remaining uses is refused outright, with a message
 * naming the five. It does NOT discount five and charge five, and the reason is not
 * squeamishness: the registration stores ONE discount figure with no record of which
 * participants it covered, so a later Recheck Totals or a removed participant would
 * have nothing to recompute against. That is the phantom-money shape this project has
 * already chased twice.
 *
 * WHAT IS CLAIMED
 *
 *   shared mode  ledger rows, counted against `quantity`. Nothing is pre-minted, so
 *                the batch lock is what serialises the count and the insert.
 *   unique mode  the same ledger rows, PLUS that many codes marked used inside the
 *                allocation the typed code belongs to. The typed code is always one of
 *                them. The batch lock covers both, so the stock and the ledger cannot
 *                drift apart.
 *
 * Nothing in here works out what a discount is worth. CouponDiscount does that, and it
 * is handed the charge by the caller, so the figure on the registration and the figure
 * on screen come from one place.
 */
class CouponRedeemer
{
    /**
     * Claim a batch's uses and record what they gave.
     *
     * @param  float  $charge  what is owed before the discount
     * @param  int  $times  the head count. Both the multiplier for a fixed discount
     *                      (see CouponDiscount) and the number of uses charged — they
     *                      are deliberately the same number, so the cap and the money
     *                      agree about how many people were covered.
     * @param  CouponIssuedCode|null  $issued  in unique mode, the individual code that
     *                                         was typed. It says which allocation the
     *                                         uses come out of.
     * @param  string|null  $buyerName  who placed the shop order, by NAME, recorded on
     *                                  the ledger row for the same reason the
     *                                  participant's name is. Passed in rather than
     *                                  read off the order because at checkout there is
     *                                  no order yet: the discount is part of the total
     *                                  the row is created with.
     */
    public function claim(
        Coupon $coupon,
        float $charge,
        int $times = 1,
        ?EventRegistration $registration = null,
        ?ShopOrder $order = null,
        ?CouponIssuedCode $issued = null,
        ?string $buyerName = null,
    ): CouponOutcome {
        if (round($charge, 2) <= 0) {
            return CouponOutcome::failed(CouponOutcome::NOTHING_TO_DISCOUNT);
        }

        $discount = CouponDiscount::on($coupon, $charge, $times);

        if ($discount <= 0) {
            return CouponOutcome::failed(CouponOutcome::NOTHING_TO_DISCOUNT);
        }

        $uses = max(1, $times);
        $people = $this->peopleCovered($registration, $uses);

        $buyerName = trim((string) $buyerName) === '' ? null : trim((string) $buyerName);

        $outcome = DB::transaction(function () use ($coupon, $discount, $uses, $people, $registration, $order, $issued, $buyerName) {
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
             | WHICH CODES ARE BEING SPENT, re-read under the same lock.
             |
             | In unique mode this is the allocation check: the typed code identifies
             | the block, and the uses come out of that block and no other. A group of
             | ten against a block with five left is refused and told whose block it
             | was, even when the batch as a whole has hundreds going spare.
             |
             | Held inside the lock for exactly the reason the cap is: two group
             | registrations racing for the last five codes of one representative must
             | not both win.
             */
            $spending = collect();

            if ($locked->isUnique()) {
                if ($issued === null) {
                    return CouponOutcome::failed(CouponOutcome::NEEDS_CODE);
                }

                $typed = CouponIssuedCode::query()
                    ->whereKey($issued->id)
                    ->where('coupon_id', $locked->id)
                    ->with('allocation.holder')
                    ->first();

                if ($typed === null) {
                    return CouponOutcome::failed(CouponOutcome::NOT_FOUND);
                }

                if ($typed->isUsed()) {
                    return CouponOutcome::failed(CouponOutcome::ALREADY_USED);
                }

                $available = CouponIssuedCode::query()
                    ->where('coupon_allocation_id', $typed->coupon_allocation_id)
                    ->unused()
                    ->count();

                if ($available < $uses) {
                    return CouponOutcome::shortOfUses(
                        remaining: $available,
                        needed: $uses,
                        holder: $typed->allocation?->holderLabel(),
                    );
                }

                $spending = $this->codesToSpend($typed, $uses);
            } elseif (! $locked->isUnlimited()) {
                /*
                 | The cap, counted under the same lock that is about to write the rows.
                 |
                 | This is the whole of the race protection for a shared batch. Two
                 | claims arriving together serialise on the batch row, so the second
                 | one counts the first one's committed ledger rows and is told how
                 | many are really left — rather than both reading "five left" and both
                 | writing ten.
                 |
                 | Counted rather than cached on the batch: a stored counter is a second
                 | source of truth for money, and the ledger is already the record
                 | Tracking and Report read.
                 */
                $spent = CouponCode::query()
                    ->where('coupon_id', $locked->id)
                    ->whereNotNull('redeemed_at')
                    ->count();

                $left = max(0, (int) $locked->quantity - $spent);

                if ($left < $uses) {
                    return CouponOutcome::shortOfUses(remaining: $left, needed: $uses);
                }
            }

            /*
             | ONE ROW PER PARTICIPANT. Every row points at the same registration and
             | carries the same typed string, which is the batch name for a shared
             | batch and the individual code for a unique one, so the trail survives a
             | later rename.
             */
            $typedLabel = $locked->isUnique()
                ? ($spending->first()?->code ?? $issued?->code ?? $locked->name)
                : $locked->name;

            $rows = $this->writeLedger($locked, $typedLabel, $discount, $uses, $people, $registration, $order, $buyerName);

            /*
             | The stock, marked spent in the same transaction and paired one to one
             | with the rows it paid for. A code therefore knows which person it
             | covered — by name only — which is what the sponsor's report reads.
             */
            $this->spendCodes($spending, $rows);

            return CouponOutcome::ok($rows[0], $discount, $rows, $uses);
        });

        if ($outcome->succeeded()) {
            $this->log($coupon, $outcome, $registration, $order);
        }

        return $outcome;
    }

    /**
     * Claim by the code somebody typed.
     *
     * TWO NAMESPACES, ONE BOX. A shared batch is reached by its NAME, which is its
     * code; a unique batch is reached only by an individual code that was issued to
     * somebody. Both arrive in the same Voucher Code field, so both are resolved here,
     * and Coupon::codeTaken() is what keeps one from shadowing the other.
     *
     * Matched case-insensitively with the whitespace trimmed, because the code is read
     * off a poster or a slip of paper and typed back in.
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
        ?string $buyerName = null,
    ): CouponOutcome {
        $typed = Str::upper(trim($typed));

        if ($typed === '') {
            return CouponOutcome::failed(CouponOutcome::NOT_FOUND);
        }

        [$batch, $issued] = self::resolve($typed);

        if ($batch === null) {
            return CouponOutcome::failed(CouponOutcome::NOT_FOUND);
        }

        if ($batch->kind !== $kind) {
            return CouponOutcome::failed(CouponOutcome::WRONG_KIND);
        }

        return $this->claim($batch, $charge, $times, $registration, $order, $issued, $buyerName);
    }

    /**
     * What a typed string refers to: the batch, and the individual code if it was one.
     *
     * The one place that resolution happens, so the advisory lookup the public form
     * does and the claim that actually spends a use can never disagree about what a
     * string means.
     *
     * A unique batch's NAME resolves to nothing. It is not a code: only an issued code
     * says whose allocation to draw from, so accepting the name would spend somebody's
     * block at random.
     *
     * @return array{0: Coupon|null, 1: CouponIssuedCode|null}
     */
    public static function resolve(string $typed): array
    {
        $typed = Str::upper(trim($typed));

        if ($typed === '') {
            return [null, null];
        }

        $batch = Coupon::query()->where('name', $typed)->first();

        if ($batch !== null) {
            return $batch->isUnique() ? [null, null] : [$batch, null];
        }

        $issued = CouponIssuedCode::query()
            ->where('code', $typed)
            ->with(['coupon', 'allocation.holder'])
            ->first();

        return $issued === null || $issued->coupon === null
            ? [null, null]
            : [$issued->coupon, $issued];
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * Who each use covered, in order, or an empty list when nobody is named.
     *
     * A row names a participant only when the uses and the heads line up exactly —
     * one use for one entrant, ten uses for ten entrants. A group counted as a SINGLE
     * use is not attributable to any one of them, so naming the first person would be
     * a guess with somebody's name on it.
     *
     * @return Collection<int, EventParticipant>
     */
    private function peopleCovered(?EventRegistration $registration, int $uses): Collection
    {
        if ($registration === null) {
            return collect();
        }

        $registration->loadMissing('participants');

        $participants = $registration->participants->sortBy('id')->values();

        return $participants->count() === $uses ? $participants : collect();
    }

    /**
     * The codes this claim will spend: the typed one first, then the rest of its block.
     *
     * The typed code is always included and always first, because whoever holds it
     * handed it over — spending somebody else's slip and leaving theirs unused would
     * make the printed codes and the report disagree.
     *
     * @return Collection<int, CouponIssuedCode>
     */
    private function codesToSpend(CouponIssuedCode $typed, int $uses): Collection
    {
        $spending = collect([$typed]);

        if ($uses <= 1) {
            return $spending;
        }

        return $spending->concat(
            CouponIssuedCode::query()
                ->where('coupon_allocation_id', $typed->coupon_allocation_id)
                ->unused()
                ->whereKeyNot($typed->id)
                ->orderBy('id')
                ->limit($uses - 1)
                ->get()
                ->all(),
        );
    }

    /**
     * One ledger row per use, with the discount split across them.
     *
     * @param  Collection<int, EventParticipant>  $people
     * @return array<int, CouponCode>
     */
    private function writeLedger(
        Coupon $coupon,
        string $typedLabel,
        float $discount,
        int $uses,
        Collection $people,
        ?EventRegistration $registration,
        ?ShopOrder $order,
        ?string $buyerName = null,
    ): array {
        $shares = $this->split($discount, $uses);
        $now = now();
        $rows = [];

        for ($i = 0; $i < $uses; $i++) {
            $person = $people->get($i);

            $rows[] = CouponCode::create([
                'coupon_id' => $coupon->id,
                'code' => $typedLabel,
                'redeemed_at' => $now,
                'discount_amount' => $shares[$i],
                'event_registration_id' => $registration?->id,

                // The REDEEMER, by name only. Never their IC or phone: a
                // sponsor-facing view reads these rows.
                'event_participant_id' => $person?->id,
                'participant_name' => $person?->full_name,

                'shop_order_id' => $order?->id,

                // The BUYER, by name only, for the same reason and under the same
                // rule: a sponsor-facing view reads these rows, and an order also
                // carries an address, a phone and a total that are none of its
                // business.
                'buyer_name' => $buyerName,
            ]);
        }

        return $rows;
    }

    /**
     * Mark the spent codes used and pair each one to the row it paid for.
     *
     * @param  Collection<int, CouponIssuedCode>  $spending
     * @param  array<int, CouponCode>  $rows
     */
    private function spendCodes(Collection $spending, array $rows): void
    {
        if ($spending->isEmpty()) {
            return;
        }

        $now = now();

        foreach ($spending->values() as $index => $code) {
            CouponIssuedCode::query()->whereKey($code->id)->update([
                'used_at' => $now,
                'coupon_code_id' => $rows[$index]->id,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * One discount split across N ledger rows, to the cent, adding back up exactly.
     *
     * Done in cents and the remainder handed to the earliest rows, because the
     * alternative — rounding each share — loses or invents money: a third of RM10 three
     * ways is 3.33 three times, which is RM9.99. The sum of these shares is the figure
     * on the registration, and Report totals this column, so a cent adrift here is a
     * cent adrift in the books.
     *
     * @return array<int, float>
     */
    private function split(float $discount, int $uses): array
    {
        $cents = (int) round($discount * 100);
        $base = intdiv($cents, $uses);
        $extra = $cents - ($base * $uses);

        $shares = [];

        for ($i = 0; $i < $uses; $i++) {
            $shares[] = round(($base + ($i < $extra ? 1 : 0)) / 100, 2);
        }

        return $shares;
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
            'Coupon %s redeemed on %s for %s%s.',
            $outcome->code?->codeLabel() ?? $coupon->name,
            $target,
            PaymentFigures::money($outcome->discount),
            $outcome->uses > 1 ? sprintf(' across %d participants', $outcome->uses) : '',
        ));

        AdminLogger::audit($coupon, 'coupon.redeemed', null, [
            'coupon' => $coupon->name,
            'code' => $outcome->code?->codeLabel(),
            'discount' => $outcome->discount,
            'uses' => $outcome->uses,
            'used_on' => $target,
            'remaining' => $coupon->fresh()?->remaining(),
        ]);
    }
}
