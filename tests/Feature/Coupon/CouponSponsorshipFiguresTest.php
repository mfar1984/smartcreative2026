<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\Event;
use App\Services\Coupon\RegistrationCouponWriter;
use App\Support\CouponSponsorship;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The sponsor's four figures, and that they are four and not one.
 *
 * The owner's position is that the system does not need to be exact — a sponsor
 * commits RM2,000, coupons are generated, and the remainder is absorbed. That is
 * right. What would be wrong is presenting the ESTIMATE as a fact, because that is
 * precisely how a sponsor's money appears to vanish.
 *
 * So each test here asserts a figure AND asserts it is not one of the others. A single
 * test that happened to find them all equal would pass against a class that returned
 * the same number four times.
 */
class CouponSponsorshipFiguresTest extends CouponTestCase
{
    use RefreshDatabase;

    private function writer(): RegistrationCouponWriter
    {
        return app(RegistrationCouponWriter::class);
    }

    private function perHeadEvent(float $fee = 0): Event
    {
        return $this->event([
            'registration_mode' => Event::MODE_GROUPING,
            'charges_addons_per_participant' => true,
            'fee' => $fee,
            'min_players' => 1,
            'max_players' => 20,
        ]);
    }

    /* ---------------------------------------------------------------------
     | All four at once, on a batch with some codes used
     * ------------------------------------------------------------------ */

    public function test_the_four_figures_are_each_correct_and_are_not_each_other(): void
    {
        /*
         | The owner's own example. A sponsor commits RM2,000. The coupon is 50% of a
         | RM15 entry, so a code is worth RM7.50. Two hundred codes are issued, which
         | is RM1,500 of estimated value, and twenty of them have been used.
         */
        $event = $this->event(['fee' => 15]);

        $coupon = $this->uniqueCoupon([
            'name' => 'SPONSORA',
            'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
            'discount_value' => 50,
            'committed_amount' => 2000,
        ]);

        $event->coupons()->attach($coupon);
        $block = $this->issue($coupon, 200, ['full_name' => 'Siti Nurhaliza']);

        // Twenty individual entries at RM15, half off each: RM150 actually given.
        foreach ($block->codes()->orderBy('id')->limit(20)->get() as $code) {
            $registration = $this->registration($event, people: 1);

            $this->assertTrue(
                $this->writer()->applyCode($registration, $code->code)->succeeded(),
                'Every one of the twenty has to go through for the figures to mean anything.',
            );
        }

        $figures = CouponSponsorship::figures($coupon->fresh());

        /* ---- committed: a promise, typed by hand ---- */
        $this->assertSame(2000.0, $figures['committed']);

        /* ---- estimated: 200 codes at RM7.50 ---- */
        $this->assertSame(7.5, $figures['per_code']);
        $this->assertSame(200, $figures['codes']);
        $this->assertSame(1500.0, $figures['estimated']);

        /* ---- actual: 20 uses at RM7.50, read off the ledger ---- */
        $this->assertSame(150.0, $figures['actual']);

        /* ---- remaining: committed less ACTUAL, never less the estimate ---- */
        $this->assertSame(1850.0, $figures['remaining']);

        /* ---- and all four are different numbers ---- */
        $this->assertCount(4, array_unique([
            $figures['committed'],
            $figures['estimated'],
            $figures['actual'],
            $figures['remaining'],
        ]));

        // Taken off the estimate, remaining would read RM500. It does not.
        $this->assertNotSame(500.0, $figures['remaining']);

        // A percentage estimate is never presented as exact.
        $this->assertFalse($figures['exact']);
        $this->assertStringContainsString('50% of the event fee', $figures['basis']);
    }

    /* ---------------------------------------------------------------------
     | Each figure on its own
     * ------------------------------------------------------------------ */

    public function test_a_fixed_coupon_knows_exactly_what_a_code_is_worth(): void
    {
        $coupon = $this->uniqueCoupon([
            'discount_type' => Coupon::DISCOUNT_FIXED,
            'discount_value' => 20,
            'committed_amount' => 2000,
        ]);

        $this->issue($coupon, 100);

        $figures = CouponSponsorship::figures($coupon->fresh());

        // RM20 a code, a hundred codes. The only figure here that is not a guess.
        $this->assertTrue($figures['exact']);
        $this->assertSame(20.0, $figures['per_code']);
        $this->assertSame(2000.0, $figures['estimated']);

        // Nothing used yet, so actual is zero and the whole pledge is still unspent.
        $this->assertSame(0.0, $figures['actual']);
        $this->assertSame(2000.0, $figures['remaining']);
    }

    public function test_the_actual_figure_is_the_real_discount_and_not_the_estimate(): void
    {
        /*
         | The case that separates the two: a RM500 coupon used on a RM25 entry gives
         | RM25, because CouponDiscount caps it at the charge. The estimate says
         | RM500 a code; the actual says RM25. A screen showing only one of those is
         | lying about something.
         */
        $event = $this->event(['fee' => 25]);

        $coupon = $this->uniqueCoupon([
            'discount_type' => Coupon::DISCOUNT_FIXED,
            'discount_value' => 500,
            'committed_amount' => 1000,
        ]);

        $event->coupons()->attach($coupon);
        $block = $this->issue($coupon, 2);

        $registration = $this->registration($event, people: 1);
        $this->writer()->applyCode($registration, $this->codeFrom($block));

        $figures = CouponSponsorship::figures($coupon->fresh());

        $this->assertSame(1000.0, $figures['estimated'], 'Two codes at RM500 each.');
        $this->assertSame(25.0, $figures['actual'], 'What really came off the entry.');
        $this->assertSame(975.0, $figures['remaining']);
    }

    public function test_the_actual_figure_counts_every_participant_in_a_group(): void
    {
        $event = $this->perHeadEvent();

        $coupon = $this->uniqueCoupon([
            'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
            'discount_value' => 50,
            'committed_amount' => 2000,
        ]);

        $event->coupons()->attach($coupon);
        $block = $this->issue($coupon, 100, ['full_name' => 'Ahmad Bin Ali']);

        // One group of ten at RM15 a head. One code typed, ten codes spent.
        $registration = $this->registration($event, ['addons_total' => 150], people: 10);
        $this->assertTrue($this->writer()->applyCode($registration, $this->codeFrom($block))->succeeded());

        $figures = CouponSponsorship::figures($coupon->fresh());

        // RM75 off the group, which is ten lots of RM7.50 — the figure the sponsor's
        // money is actually being spent at.
        $this->assertSame(75.0, $figures['actual']);
        $this->assertSame(1925.0, $figures['remaining']);

        // Ten codes gone out of a hundred, so ninety are still in Ahmad's hands.
        $this->assertSame(10, $coupon->fresh()->redeemedCount());
        $this->assertSame(90, $block->fresh()->unusedCount());
    }

    public function test_a_batch_with_no_committed_amount_has_no_remaining_figure(): void
    {
        $coupon = $this->uniqueCoupon(['discount_type' => Coupon::DISCOUNT_FIXED, 'discount_value' => 20]);
        $this->issue($coupon, 10);

        $figures = CouponSponsorship::figures($coupon->fresh());

        // Null rather than zero: "nobody said" and "nothing left" are different
        // answers, and printing RM0.00 would read as the sponsorship being spent.
        $this->assertNull($figures['committed']);
        $this->assertNull($figures['remaining']);
        $this->assertSame(200.0, $figures['estimated']);
    }

    /* ---------------------------------------------------------------------
     | The percentage estimate, and the several-events case
     * ------------------------------------------------------------------ */

    public function test_a_percentage_batch_ticked_on_nothing_has_no_estimate(): void
    {
        $coupon = $this->uniqueCoupon([
            'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
            'discount_value' => 50,
            'committed_amount' => 500,
        ]);

        $this->issue($coupon, 10);

        $figures = CouponSponsorship::figures($coupon->fresh());

        // Null, not zero. There is no fee to take a percentage of yet, and a zero
        // would read as "these codes are worth nothing".
        $this->assertNull($figures['per_code']);
        $this->assertNull($figures['estimated']);
        $this->assertStringContainsString('not ticked on any event', $figures['basis']);

        // The two figures that do not depend on a fee still answer.
        $this->assertSame(500.0, $figures['committed']);
        $this->assertSame(0.0, $figures['actual']);
    }

    public function test_a_percentage_batch_on_several_fees_estimates_from_the_lowest_and_says_so(): void
    {
        /*
         | Three events at RM15, RM40 and RM100. A code is worth a different amount on
         | each, so there is no single answer.
         |
         | The LOWEST is used, deliberately. Understating what was allocated can only
         | make the estimate smaller than reality; the highest or an average would show
         | a sponsor more value allocated than their codes can actually deliver, which
         | is the failure that matters. The basis string says which was taken so nobody
         | has to guess.
         */
        $coupon = $this->uniqueCoupon([
            'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
            'discount_value' => 50,
            'committed_amount' => 2000,
        ]);

        foreach ([15, 40, 100] as $fee) {
            $this->event(['fee' => $fee])->coupons()->attach($coupon);
        }

        $this->issue($coupon, 100);

        $figures = CouponSponsorship::figures($coupon->fresh());

        // Half of RM15, the cheapest of the three.
        $this->assertSame(7.5, $figures['per_code']);
        $this->assertSame(750.0, $figures['estimated']);

        $this->assertFalse($figures['exact']);
        $this->assertStringContainsString('LOWEST of 3 event fees', $figures['basis']);
    }

    public function test_a_free_event_does_not_drag_the_estimate_to_zero(): void
    {
        $coupon = $this->uniqueCoupon([
            'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
            'discount_value' => 50,
        ]);

        // A free event ticked alongside a paid one. A percentage of nothing is
        // nothing, and letting it set the basis would wipe the estimate for the
        // paid event beside it.
        $this->event(['fee' => 0])->coupons()->attach($coupon);
        $this->event(['fee' => 30])->coupons()->attach($coupon);

        $this->issue($coupon, 10);

        $figures = CouponSponsorship::figures($coupon->fresh());

        $this->assertSame(15.0, $figures['per_code']);
        $this->assertSame(150.0, $figures['estimated']);
    }

    /* ---------------------------------------------------------------------
     | Shared batches
     * ------------------------------------------------------------------ */

    public function test_a_capped_shared_batch_estimates_from_its_use_cap(): void
    {
        $coupon = $this->fixedCoupon(20, [
            'quantity' => 50,
            'committed_amount' => 1000,
        ]);

        $figures = CouponSponsorship::figures($coupon);

        // Fifty uses allowed is the same promise as fifty codes issued.
        $this->assertSame(50, $figures['codes']);
        $this->assertSame(1000.0, $figures['estimated']);
    }

    public function test_an_unlimited_shared_batch_has_no_allocation_to_total(): void
    {
        $coupon = $this->fixedCoupon(20, ['quantity' => 0, 'committed_amount' => 1000]);

        $figures = CouponSponsorship::figures($coupon);

        // Nothing to count, so nothing is claimed. The committed and actual figures
        // still answer, which is what makes the screen useful anyway.
        $this->assertNull($figures['codes']);
        $this->assertNull($figures['estimated']);
        $this->assertSame(1000.0, $figures['committed']);
        $this->assertSame(0.0, $figures['actual']);
    }

    public function test_remaining_never_goes_negative(): void
    {
        $event = $this->event(['fee' => 100]);

        $coupon = $this->fixedCoupon(100, ['quantity' => 5, 'committed_amount' => 150]);
        $event->coupons()->attach($coupon);

        // Two RM100 discounts against a RM150 pledge: RM50 over.
        foreach (range(1, 2) as $ignored) {
            $this->writer()->applyCode($this->registration($event, people: 1), $coupon->name);
        }

        $figures = CouponSponsorship::figures($coupon->fresh());

        $this->assertSame(200.0, $figures['actual']);
        $this->assertSame(0.0, $figures['remaining'], 'An overspend reads as nothing left, never as a debt.');
    }
}
