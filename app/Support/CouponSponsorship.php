<?php

namespace App\Support;

use App\Models\Coupon;
use App\Models\CouponCode;
use App\Models\CouponIssuedCode;
use App\Models\User;

/**
 * A sponsor's four figures, kept apart on purpose.
 *
 * The owner's position is that the system does not need to be exact: a sponsor commits
 * RM2,000, coupons are generated, and the remainder is absorbed. That is right. What is
 * NOT right is presenting an estimate as a fact — that is exactly how a sponsor's money
 * appears to vanish, and it is the mistake this class exists to make impossible.
 *
 * So there are four figures and they never collapse into each other:
 *
 *   COMMITTED   what the sponsor said they would give. Typed by hand, derived from
 *               nothing, optional. A promise is not a calculation.
 *
 *   ESTIMATED   codes issued x what one code is probably worth. An ESTIMATE, labelled
 *               as one everywhere it is shown, because what a code is worth is only
 *               knowable for a fixed-ringgit coupon. For a percentage it depends on
 *               what the person it is used on was being charged.
 *
 *   ACTUAL      the real discount recorded on the ledger rows. This is the only figure
 *               that is a fact: it is money that was taken off real registrations, and
 *               it is the same column Tracking and Report already total.
 *
 *   REMAINING   committed less ACTUAL, floored at zero. Computed from the actual and
 *               never from the estimate, because "how much of the sponsorship is still
 *               unspent" has to be answerable with real money or it is worthless.
 *
 * HOW A PERCENTAGE IS ESTIMATED, AND WHAT HAPPENS WITH SEVERAL EVENTS
 *
 * One code of a fixed-RM coupon is worth its amount, full stop. One code of a
 * percentage coupon is worth that percentage of the registration fee of the event the
 * batch is ticked on — so a 50% coupon on a RM15 event is RM7.50 a code.
 *
 * A batch ticked on SEVERAL events with different fees has no single answer. Rather
 * than pick one and present it as the figure, the LOWEST fee is used and the screen
 * says so: it is the conservative direction, because understating what was allocated
 * can only ever make the estimate smaller than reality. The alternative — the highest,
 * or an average — would show a sponsor more value allocated than their codes can
 * actually deliver, which is the failure that matters.
 *
 * Ticked on nothing, or on nothing with a price, and there is no estimate at all. Null
 * rather than zero: "we cannot say" and "nothing" are different answers.
 */
class CouponSponsorship
{
    /**
     * Every figure for one batch, ready to render.
     *
     * @return array{
     *     committed: float|null,
     *     estimated: float|null,
     *     actual: float,
     *     remaining: float|null,
     *     per_code: float|null,
     *     codes: int|null,
     *     basis: string,
     *     exact: bool,
     * }
     */
    public static function figures(Coupon $coupon): array
    {
        $committed = $coupon->committed_amount === null ? null : round((float) $coupon->committed_amount, 2);
        $actual = self::actual($coupon);

        [$perCode, $basis, $exact] = self::perCodeValue($coupon);
        $codes = self::codesIssued($coupon);

        return [
            'committed' => $committed,
            'estimated' => $perCode === null || $codes === null ? null : round($perCode * $codes, 2),
            'actual' => $actual,
            'remaining' => $committed === null ? null : round(max(0.0, $committed - $actual), 2),
            'per_code' => $perCode,
            'codes' => $codes,
            'basis' => $basis,
            'exact' => $exact,
        ];
    }

    /**
     * The same four figures for ONE SPONSORSHIP ACCOUNT, scoped to the blocks it funded.
     *
     * Here rather than in the sponsor's controller on purpose. A second
     * implementation of "what has this sponsorship given away" is how two screens
     * come to show a sponsor two different answers, so every figure below is worked
     * out by the methods above: the estimate through perCodeValue(), the actual off
     * the same ledger column, and remaining from the actual and never the estimate.
     *
     * WHAT IS SCOPED, AND WHAT THAT MEANS
     *
     *   COMMITTED   the sponsor's OWN pledge, off their account. Deliberately not the
     *               batch's committed_amount: one batch can carry blocks from two
     *               sponsors, so reusing it would show this sponsor somebody else's
     *               money.
     *   ESTIMATED   their codes x what one code of that batch is probably worth,
     *               summed per batch because a sponsor may fund blocks on more than
     *               one. Still an ESTIMATE, labelled as one.
     *   ACTUAL      the real discount on the ledger rows their own codes paid for.
     *   REMAINING   their pledge less that actual, floored at zero.
     *
     * A batch whose codes have no knowable value contributes nothing to the estimate
     * and is counted in `unpriced`, so the screen can say why the estimate is short
     * rather than quietly understating it.
     *
     * @return array{
     *     committed: float|null,
     *     estimated: float|null,
     *     actual: float,
     *     remaining: float|null,
     *     codes: int,
     *     used: int,
     *     unused: int,
     *     blocks: int,
     *     batches: int,
     *     unpriced: int,
     *     exact: bool,
     * }
     */
    public static function forSponsor(User $sponsor): array
    {
        $committed = $sponsor->sponsor_committed_amount === null
            ? null
            : round((float) $sponsor->sponsor_committed_amount, 2);

        $allocationIds = $sponsor->sponsoredAllocations()->pluck('id')->all();

        $codes = self::sponsorCodes($allocationIds);
        $used = self::sponsorCodes($allocationIds)->whereNotNull('used_at')->count();
        $total = $codes->count();

        $actual = self::actualForAllocations($allocationIds);

        [$estimated, $unpriced, $exact, $batches] = self::sponsorEstimate($allocationIds);

        return [
            'committed' => $committed,
            'estimated' => $estimated,
            'actual' => $actual,
            'remaining' => $committed === null ? null : round(max(0.0, $committed - $actual), 2),
            'codes' => $total,
            'used' => $used,
            'unused' => max(0, $total - $used),
            'blocks' => count($allocationIds),
            'batches' => $batches,
            'unpriced' => $unpriced,
            'exact' => $exact,
        ];
    }

    /**
     * What the batch has really given away, in ringgit.
     *
     * Summed off the ledger, which is the same column the Report's "given away" total
     * reads, so the two can never disagree.
     */
    public static function actual(Coupon $coupon): float
    {
        return round((float) CouponCode::query()
            ->where('coupon_id', $coupon->id)
            ->whereNotNull('redeemed_at')
            ->sum('discount_amount'), 2);
    }

    /**
     * What a set of blocks has really given away, in ringgit.
     *
     * The SAME ledger column as actual() above, reached through the codes those
     * blocks issued: an issued code points at the one ledger row it paid for, so a
     * sponsor's spend is the sum over their own codes and nothing else. Narrowed by
     * block rather than by batch, because a batch can carry another sponsor's blocks.
     *
     * @param  array<int, int>  $allocationIds
     */
    public static function actualForAllocations(array $allocationIds): float
    {
        if ($allocationIds === []) {
            return 0.0;
        }

        return round((float) CouponCode::query()
            ->whereIn('id', CouponIssuedCode::query()
                ->whereIn('coupon_allocation_id', $allocationIds)
                ->whereNotNull('coupon_code_id')
                ->select('coupon_code_id'))
            ->whereNotNull('redeemed_at')
            ->sum('discount_amount'), 2);
    }

    /**
     * How many codes were allocated, or null when there is no countable allocation.
     *
     * Unique mode: the codes minted. Shared mode: the use cap, because that is the
     * same promise expressed as a number of uses. An unlimited shared batch has no
     * allocation to total, so it answers null rather than a figure that could only be
     * wrong.
     */
    public static function codesIssued(Coupon $coupon): ?int
    {
        if ($coupon->isUnique()) {
            return $coupon->issuedCodes()->count();
        }

        return $coupon->isUnlimited() ? null : (int) $coupon->quantity;
    }

    /**
     * What one code is worth, how that was arrived at, and whether it is exact.
     *
     * @return array{0: float|null, 1: string, 2: bool}
     */
    public static function perCodeValue(Coupon $coupon): array
    {
        if (! $coupon->isPercentage()) {
            // A fixed amount is what it is. The only figure here that is not a guess.
            return [round((float) $coupon->discount_value, 2), 'the coupon amount', true];
        }

        $percent = min(100.0, (float) $coupon->discount_value);

        if ($percent <= 0) {
            return [null, 'no discount set', false];
        }

        $prices = self::tickedPrices($coupon);

        if ($prices === []) {
            return [
                null,
                $coupon->isForEvents()
                    ? 'not ticked on any event with a fee yet'
                    : 'not ticked on any product with a price yet',
                false,
            ];
        }

        $lowest = min($prices);
        $single = count(array_unique($prices)) === 1;
        $subject = $coupon->isForEvents() ? 'event fee' : 'product price';

        return [
            round($lowest * $percent / 100, 2),

            $single
                ? sprintf('%s%% of the %s, %s', self::trim($percent), $subject, PaymentFigures::money($lowest))
                : sprintf(
                    '%s%% of the LOWEST of %d %ss, %s',
                    self::trim($percent),
                    count($prices),
                    $subject,
                    PaymentFigures::money($lowest),
                ),

            // Never exact: a percentage is worth whatever the person it was used on
            // was being charged, add-ons and all.
            false,
        ];
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * The codes issued inside a set of blocks.
     *
     * Returns a fresh builder each call so a count and a filtered count do not share
     * one — adding a where to a builder that has already been counted is the kind of
     * reuse that makes two figures on one screen disagree.
     *
     * @param  array<int, int>  $allocationIds
     * @return \Illuminate\Database\Eloquent\Builder<CouponIssuedCode>
     */
    private static function sponsorCodes(array $allocationIds)
    {
        return CouponIssuedCode::query()->whereIn('coupon_allocation_id', $allocationIds ?: [0]);
    }

    /**
     * The estimate across every batch a sponsor holds blocks on.
     *
     * Per batch, because what one code is worth is a property of its batch: 50% of a
     * RM15 event is RM7.50 a code there and something else on the batch beside it.
     * Summed rather than averaged, so the figure is the sponsor's own codes and not a
     * blend of everybody's.
     *
     * @param  array<int, int>  $allocationIds
     * @return array{0: float|null, 1: int, 2: bool, 3: int}
     */
    private static function sponsorEstimate(array $allocationIds): array
    {
        if ($allocationIds === []) {
            return [null, 0, true, 0];
        }

        $perBatch = self::sponsorCodes($allocationIds)
            ->selectRaw('coupon_id, COUNT(*) as codes')
            ->groupBy('coupon_id')
            ->pluck('codes', 'coupon_id');

        $estimated = null;
        $unpriced = 0;
        $exact = true;

        foreach (Coupon::query()->whereIn('id', $perBatch->keys())->get() as $coupon) {
            [$perCode, , $batchExact] = self::perCodeValue($coupon);

            if ($perCode === null) {
                // "We cannot say" for this batch, which is not the same as nothing.
                $unpriced++;
                $exact = false;

                continue;
            }

            $exact = $exact && $batchExact;
            $estimated = round(($estimated ?? 0.0) + $perCode * (int) $perBatch[$coupon->id], 2);
        }

        return [$estimated, $unpriced, $exact, $perBatch->count()];
    }

    /**
     * The prices of whatever this batch is ticked on, above zero.
     *
     * Free events and zero-priced products are left out: a percentage of nothing is
     * nothing, and letting one in would drag the lowest-fee basis to zero and wipe
     * the estimate for every other event in the list.
     *
     * @return array<int, float>
     */
    private static function tickedPrices(Coupon $coupon): array
    {
        if ($coupon->isForEvents()) {
            return $coupon->events()
                ->get()
                ->map(fn ($event) => round($event->registrationAmount(), 2))
                ->filter(fn (float $fee) => $fee > 0)
                ->values()
                ->all();
        }

        return $coupon->products()
            ->get()
            ->map(fn ($product) => round((float) $product->price, 2))
            ->filter(fn (float $price) => $price > 0)
            ->values()
            ->all();
    }

    /** 50.00 reads as 50, 12.50 stays 12.5. */
    private static function trim(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 2), '0'), '.');
    }
}
