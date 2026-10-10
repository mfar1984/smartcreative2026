<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\Event;
use App\Support\CouponDiscount;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * What a coupon takes off a charge, and the two caps that stop it taking too much.
 *
 * The whole Payments module ends up resting on this arithmetic, so it is asserted on
 * its own before anything is written anywhere.
 */
class CouponDiscountArithmeticTest extends CouponTestCase
{
    use RefreshDatabase;

    /* ---------------------------------------------------------------------
     | The two kinds
     * ------------------------------------------------------------------ */

    public function test_a_percentage_takes_that_share_of_the_charge(): void
    {
        $coupon = $this->percentageCoupon(10);

        $this->assertSame(10.0, CouponDiscount::on($coupon, 100));
        $this->assertSame(25.0, CouponDiscount::on($coupon, 250));

        // Rounded to the sen, not left as a fraction of one.
        $this->assertSame(3.33, CouponDiscount::on($coupon, 33.33));
    }

    public function test_a_fixed_amount_takes_exactly_that_much(): void
    {
        $coupon = $this->fixedCoupon(15);

        $this->assertSame(15.0, CouponDiscount::on($coupon, 100));
        $this->assertSame(15.0, CouponDiscount::on($coupon, 15.01));
    }

    /* ---------------------------------------------------------------------
     | The caps
     * ------------------------------------------------------------------ */

    public function test_a_fixed_amount_larger_than_the_charge_is_capped_at_the_charge(): void
    {
        $coupon = $this->fixedCoupon(500);

        // Not RM500, which would make the total negative and then be subtracted from
        // what everybody else on the event owes.
        $this->assertSame(30.0, CouponDiscount::on($coupon, 30));
        $this->assertSame(0.0, CouponDiscount::applyTo(30, CouponDiscount::on($coupon, 30)));
    }

    public function test_a_percentage_stored_above_a_hundred_cannot_exceed_the_charge(): void
    {
        // Validation refuses this on the way in. Asserted anyway, because a row
        // written by hand must not be able to produce a negative charge.
        $coupon = $this->percentageCoupon(10);
        $coupon->forceFill(['discount_value' => 250])->save();

        $this->assertSame(80.0, CouponDiscount::on($coupon->fresh(), 80));
    }

    public function test_applying_a_discount_floors_the_charge_at_zero(): void
    {
        $this->assertSame(0.0, CouponDiscount::applyTo(40, 40));
        $this->assertSame(0.0, CouponDiscount::applyTo(40, 99));
        $this->assertSame(10.0, CouponDiscount::applyTo(40, 30));
    }

    public function test_nothing_is_discounted_off_nothing(): void
    {
        $this->assertSame(0.0, CouponDiscount::on($this->percentageCoupon(50), 0));
        $this->assertSame(0.0, CouponDiscount::on($this->fixedCoupon(50), 0));
        $this->assertSame(0.0, CouponDiscount::on($this->fixedCoupon(50), -10));
    }

    public function test_a_hundred_percent_takes_the_whole_charge(): void
    {
        $coupon = $this->percentageCoupon(100);

        $this->assertSame(240.0, CouponDiscount::on($coupon, 240));
        $this->assertSame(0.0, CouponDiscount::applyTo(240, CouponDiscount::on($coupon, 240)));
    }

    /* ---------------------------------------------------------------------
     | Grouping
     * ------------------------------------------------------------------ */

    public function test_a_fixed_discount_is_owed_once_per_head_when_the_event_charges_per_participant(): void
    {
        $event = $this->event([
            'registration_mode' => Event::MODE_GROUPING,
            'charges_addons_per_participant' => true,
        ]);

        $this->assertTrue($event->chargesAddonsPerParticipant());
        $this->assertSame(3, CouponDiscount::timesFor($event, 3));

        $coupon = $this->fixedCoupon(10);

        // RM10 each for three people, off a RM120 charge for three RM40 shirts.
        $this->assertSame(30.0, CouponDiscount::on($coupon, 120, CouponDiscount::timesFor($event, 3)));
    }

    public function test_a_fixed_discount_is_owed_once_for_the_whole_entry_when_it_does_not(): void
    {
        $event = $this->event(['registration_mode' => Event::MODE_GROUPING]);

        $this->assertFalse($event->chargesAddonsPerParticipant());
        $this->assertSame(1, CouponDiscount::timesFor($event, 3));

        $this->assertSame(10.0, CouponDiscount::on($this->fixedCoupon(10), 120, CouponDiscount::timesFor($event, 3)));
    }

    public function test_a_percentage_is_not_multiplied_by_the_head_count(): void
    {
        /*
         | A percentage is already proportional to what the group is charged: a tenth
         | of a three-person total is three times a tenth of a one-person total. So
         | taking it per head and adding the shares up gives the same ringgit figure
         | as taking it once, and multiplying it again would turn a 34% coupon into
         | 102% — which the cap would quietly turn into "free".
         */
        $coupon = $this->percentageCoupon(34);

        $this->assertSame(40.80, CouponDiscount::on($coupon, 120, 1));
        $this->assertSame(40.80, CouponDiscount::on($coupon, 120, 3));

        // And the figure is still a third of the charge rather than the whole of it.
        $this->assertSame(79.20, CouponDiscount::applyTo(120, CouponDiscount::on($coupon, 120, 3)));
    }

    public function test_the_head_count_rule_mirrors_the_existing_per_participant_pricing_flag(): void
    {
        // Individual and manager mode cannot charge per participant, whatever the
        // column says, so the discount is owed once. Event::chargesAddonsPerParticipant()
        // is the one place that decides this and CouponDiscount asks it.
        foreach ([Event::MODE_INDIVIDUAL, Event::MODE_MANAGER] as $mode) {
            $event = $this->event([
                'registration_mode' => $mode,
                'charges_addons_per_participant' => true,
                'min_players' => $mode === Event::MODE_MANAGER ? 2 : 1,
            ]);

            $this->assertSame(1, CouponDiscount::timesFor($event, 5), $mode.' must not charge per head.');
        }
    }

    /* ---------------------------------------------------------------------
     | Labels
     * ------------------------------------------------------------------ */

    public function test_a_batch_reports_its_discount_in_the_right_unit(): void
    {
        $this->assertSame('10%', $this->percentageCoupon(10)->discountLabel());
        $this->assertSame('12.5%', $this->percentageCoupon(12.5)->discountLabel());
        $this->assertSame('RM 15.00', $this->fixedCoupon(15)->discountLabel());
    }

    public function test_a_batch_knows_which_kind_of_thing_it_discounts(): void
    {
        $event = $this->coupon(['kind' => Coupon::KIND_EVENT]);
        $shop = $this->coupon(['kind' => Coupon::KIND_SHOP]);

        $this->assertTrue($event->isForEvents());
        $this->assertFalse($event->isForShop());
        $this->assertTrue($shop->isForShop());
        $this->assertFalse($shop->isForEvents());
    }
}
