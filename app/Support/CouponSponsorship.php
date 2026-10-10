<?php

namespace App\Support;

use App\Models\Coupon;
use App\Models\CouponCode;

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
