<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\Coupon\RegistrationCouponWriter;
use App\Services\Registration\RegistrationTotalsRecalculator;
use App\Support\PaymentFigures;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * What a coupon does to a registration's money.
 *
 * The discount lives inside `amount`, which is what every money figure in the system
 * keys off. That is the design, and these tests are what hold it: reduce `amount` and
 * the whole Payments module stays correct with no changes, because outstanding is
 * computed as `amount - amount_paid` rather than read from a stored column.
 */
class CouponRegistrationChargeTest extends CouponTestCase
{
    use RefreshDatabase;

    private function writer(): RegistrationCouponWriter
    {
        return app(RegistrationCouponWriter::class);
    }

    /* ---------------------------------------------------------------------
     | The charge
     * ------------------------------------------------------------------ */

    public function test_a_percentage_coupon_reduces_the_amount_and_leaves_what_was_bought_alone(): void
    {
        $event = $this->event(['fee' => 100]);
        $registration = $this->registration($event, ['addons_total' => 40]);

        $this->assertSame('140.00', $registration->amount);

        $outcome = $this->writer()->apply($registration, $this->percentageCoupon(25));

        $this->assertTrue($outcome->succeeded());
        $this->assertSame(35.0, $outcome->discount);

        $registration->refresh();

        // The two columns saying what was bought do not move: a discount changes what
        // is owed, not what was ordered.
        $this->assertSame('100.00', $registration->registration_fee);
        $this->assertSame('40.00', $registration->addons_total);

        $this->assertSame('35.00', $registration->discount_amount);
        $this->assertSame('105.00', $registration->amount);
        $this->assertSame(105.0, $registration->outstandingAmount());
    }

    public function test_a_fixed_coupon_reduces_the_amount_by_exactly_that_much(): void
    {
        $registration = $this->registration($this->event(['fee' => 100]));

        $this->writer()->apply($registration, $this->fixedCoupon(30));

        $registration->refresh();

        $this->assertSame('30.00', $registration->discount_amount);
        $this->assertSame('70.00', $registration->amount);
    }

    public function test_a_discount_larger_than_the_charge_floors_the_amount_at_zero(): void
    {
        $registration = $this->registration($this->event(['fee' => 25]));

        $outcome = $this->writer()->apply($registration, $this->fixedCoupon(500));

        $this->assertTrue($outcome->succeeded());

        // The recorded discount is the capped figure, not the RM500 the batch names.
        $this->assertSame(25.0, $outcome->discount);

        $registration->refresh();

        $this->assertSame('25.00', $registration->discount_amount);
        $this->assertSame('0.00', $registration->amount);
        $this->assertSame(0.0, $registration->outstandingAmount());
    }

    /* ---------------------------------------------------------------------
     | A hundred percent settles itself through the EXISTING rule
     * ------------------------------------------------------------------ */

    public function test_a_full_discount_leaves_the_entry_free_and_paid_through_the_existing_ledger_rule(): void
    {
        $registration = $this->registration($this->event(['fee' => 240]));

        $this->assertFalse($registration->isFree());

        $this->writer()->apply($registration, $this->percentageCoupon(100));

        $registration->refresh();

        $this->assertSame('0.00', $registration->amount);
        $this->assertTrue($registration->isFree());

        // The point of the design: no new "is this free" concept was invented. The
        // rule that already existed answers PAID for a free entry, and that is what
        // wrote the status.
        $this->assertSame(EventRegistration::PAYMENT_PAID, $registration->paymentStatusFromLedger());
        $this->assertSame(EventRegistration::PAYMENT_PAID, $registration->payment_status);

        // Nothing to wait for, so the place is confirmed rather than left pending.
        $this->assertSame(EventRegistration::STATUS_CONFIRMED, $registration->status);

        // And nothing is chaseable or payable.
        $this->assertFalse($registration->owesBalance());
        $this->assertFalse($registration->awaitingPayment());
        $this->assertSame('Free of charge.', $registration->paymentPositionLabel());
    }

    public function test_a_partial_discount_leaves_the_entry_payable_as_before(): void
    {
        $registration = $this->registration($this->event(['fee' => 240]));

        $this->writer()->apply($registration, $this->percentageCoupon(50));

        $registration->refresh();

        $this->assertSame('120.00', $registration->amount);
        $this->assertFalse($registration->isFree());
        $this->assertTrue($registration->owesBalance());
        $this->assertTrue($registration->awaitingPayment());
    }

    /* ---------------------------------------------------------------------
     | Grouping
     * ------------------------------------------------------------------ */

    public function test_a_fixed_discount_multiplies_by_the_head_count_when_the_event_charges_per_participant(): void
    {
        $event = $this->event([
            'registration_mode' => Event::MODE_GROUPING,
            'charges_addons_per_participant' => true,
            'fee' => 0,
            'min_players' => 1,
            'max_players' => 20,
        ]);

        // Three people, a RM40 shirt each, which is what the per-participant rule
        // already charges: RM120.
        $registration = $this->registration($event, ['addons_total' => 120], people: 3);

        $this->assertSame('120.00', $registration->amount);

        $this->writer()->apply($registration, $this->fixedCoupon(10));

        $registration->refresh();

        // RM10 each for three people.
        $this->assertSame('30.00', $registration->discount_amount);
        $this->assertSame('90.00', $registration->amount);
    }

    public function test_the_same_fixed_discount_is_taken_once_when_the_event_does_not_charge_per_participant(): void
    {
        $event = $this->event([
            'registration_mode' => Event::MODE_GROUPING,
            'fee' => 0,
            'min_players' => 1,
            'max_players' => 20,
        ]);

        $registration = $this->registration($event, ['addons_total' => 120], people: 3);

        $this->writer()->apply($registration, $this->fixedCoupon(10));

        $registration->refresh();

        $this->assertSame('10.00', $registration->discount_amount);
        $this->assertSame('110.00', $registration->amount);
    }

    /* ---------------------------------------------------------------------
     | Guards
     * ------------------------------------------------------------------ */

    public function test_a_shop_coupon_cannot_be_applied_to_a_registration(): void
    {
        $registration = $this->registration($this->event());

        $outcome = $this->writer()->apply($registration, $this->coupon(['kind' => Coupon::KIND_SHOP]));

        $this->assertFalse($outcome->succeeded());
        $this->assertSame('100.00', $registration->fresh()->amount);
    }

    public function test_a_second_coupon_cannot_be_stacked_on_one_entry(): void
    {
        $registration = $this->registration($this->event(['fee' => 100]));

        $this->writer()->apply($registration, $this->fixedCoupon(10));
        $registration->refresh();

        $second = $this->fixedCoupon(10, ['quantity' => 3]);
        $outcome = $this->writer()->apply($registration, $second);

        $this->assertFalse($outcome->succeeded());
        $this->assertSame('90.00', $registration->fresh()->amount);
        $this->assertSame(3, $second->fresh()->remaining(), 'A refused claim must not spend a code.');
    }

    public function test_a_typed_code_applies_the_discount_and_links_the_redemption_to_the_entry(): void
    {
        $registration = $this->registration($this->event(['fee' => 100]));
        $coupon = $this->fixedCoupon(20, ['quantity' => 2]);
        $code = $coupon->name;

        $outcome = $this->writer()->applyCode($registration, $code);

        $this->assertTrue($outcome->succeeded());

        $registration->refresh();

        $this->assertSame('80.00', $registration->amount);
        $this->assertSame($outcome->code->id, $registration->coupon_code_id);
        $this->assertSame($registration->id, $outcome->code->fresh()->event_registration_id);
        $this->assertSame($code, $registration->couponCode->codeLabel());
    }

    /* ---------------------------------------------------------------------
     | THE TRAP: "Recheck Totals" must not wipe the discount
     * ------------------------------------------------------------------ */

    /**
     * A discounted, fully paid registration pressed through the recalculator.
     *
     * Without the discount in that arithmetic, one press writes the undiscounted
     * figure over an entry that was settled in full, reopens a balance nobody owes
     * and knocks the badge back to Partly Paid. This project has been burned by
     * exactly that shape twice.
     */
    public function test_recalculation_preserves_the_discount_on_a_discounted_fully_paid_entry(): void
    {
        $event = $this->event([
            'registration_mode' => Event::MODE_GROUPING,
            'charges_addons_per_participant' => true,
            'fee' => 0,
            'min_players' => 1,
            'max_players' => 20,
        ]);

        [$addon, $small] = $this->shirt($event, 40);

        // Three people with a RM40 shirt each, priced the way the per-participant
        // rule already prices them, so the recalculator finds nothing wrong with the
        // items themselves.
        $registration = $this->registration($event, ['addons_total' => 120], people: 3);

        foreach ($registration->participants as $person) {
            $registration->addonLines()->create([
                'event_participant_id' => $person->id,
                'event_addon_id' => $addon->id,
                'event_addon_variant_id' => $small->id,
                'name' => $addon->name,
                'variant_label' => $small->label,
                'unit_price' => 40,
                'quantity' => 1,
                'line_total' => 40,
            ]);
        }

        // 25% off, so RM120 becomes RM90...
        $this->writer()->apply($registration->fresh(['participants', 'event']), $this->percentageCoupon(25));

        $registration->refresh();
        $this->assertSame('30.00', $registration->discount_amount);
        $this->assertSame('90.00', $registration->amount);

        // ...and the registrant pays it in full.
        $registration->forceFill([
            'amount_paid' => 90,
            'payment_status' => EventRegistration::PAYMENT_PAID,
            'status' => EventRegistration::STATUS_CONFIRMED,
        ])->save();

        $registration->refresh();
        $this->assertTrue($registration->isSettledInFull());

        /* ---- the press ---- */

        $recalculator = app(RegistrationTotalsRecalculator::class);

        $preview = $recalculator->preview($event->fresh());
        $correction = $preview[0];

        // The preview carries the discount as its own figure, so the operator can
        // read along the row and see it was kept.
        $this->assertTrue($correction->hasDiscount());
        $this->assertSame(30.0, $correction->discount);
        $this->assertSame('RM 30.00', $correction->discountLabel());

        // Nothing moves, so nothing is written at all.
        $this->assertFalse($correction->movesMoney(), 'A discounted entry priced correctly must not move money.');
        $this->assertFalse($correction->changes());
        $this->assertSame(90.0, $correction->correctedAmount);

        $applied = $recalculator->apply($event->fresh());
        $this->assertSame([], $applied, 'There was nothing to correct.');

        $registration->refresh();

        // The figures, the badge and the place are exactly where they were.
        $this->assertSame('90.00', $registration->amount);
        $this->assertSame('30.00', $registration->discount_amount);
        $this->assertSame('120.00', $registration->addons_total);
        $this->assertSame(EventRegistration::PAYMENT_PAID, $registration->payment_status);
        $this->assertSame(EventRegistration::STATUS_CONFIRMED, $registration->status);
        $this->assertSame(0.0, $registration->outstandingAmount());
        $this->assertTrue($registration->isSettledInFull());
    }

    /**
     * The same press on an entry whose ITEMS really were mispriced.
     *
     * The correction has to happen and the discount has to survive it, which is the
     * harder half: the arithmetic runs, so the discount has to be part of it rather
     * than simply left alone by a no-op.
     */
    public function test_a_genuine_recalculation_subtracts_the_discount_from_the_corrected_total(): void
    {
        $event = $this->event([
            'registration_mode' => Event::MODE_GROUPING,
            'charges_addons_per_participant' => true,
            'fee' => 0,
            'min_players' => 1,
            'max_players' => 20,
        ]);

        [$addon, $small] = $this->shirt($event, 40);

        // Charged the OLD way: one RM40 shirt between three people.
        $registration = $this->registration($event, ['addons_total' => 40], people: 3);

        foreach ($registration->participants as $person) {
            $registration->addonLines()->create([
                'event_participant_id' => $person->id,
                'event_addon_id' => $addon->id,
                'event_addon_variant_id' => $small->id,
                'name' => $addon->name,
                'variant_label' => $small->label,
                'unit_price' => 0,
                'quantity' => 1,
                'line_total' => 0,
            ]);
        }

        $registration->addonLines()->create([
            'event_participant_id' => null,
            'event_addon_id' => $addon->id,
            'event_addon_variant_id' => null,
            'name' => $addon->name,
            'variant_label' => null,
            'unit_price' => 40,
            'quantity' => 1,
            'line_total' => 40,
        ]);

        // RM10 off, once per head because the event charges per participant: RM30.
        $this->writer()->apply($registration->fresh(['participants', 'event']), $this->fixedCoupon(10));

        $registration->refresh();
        $this->assertSame('30.00', $registration->discount_amount);
        $this->assertSame('10.00', $registration->amount);

        $recalculator = app(RegistrationTotalsRecalculator::class);
        $correction = $recalculator->preview($event->fresh())[0];

        // Items corrected to RM120, less the RM30 already given: RM90.
        $this->assertSame(120.0, $correction->correctedAddonsTotal);
        $this->assertSame(30.0, $correction->discount);
        $this->assertSame(90.0, $correction->correctedAmount);
        $this->assertTrue($correction->movesMoney());

        $recalculator->apply($event->fresh());

        $registration->refresh();

        $this->assertSame('120.00', $registration->addons_total);
        $this->assertSame('30.00', $registration->discount_amount, 'The discount must survive the write.');
        $this->assertSame('90.00', $registration->amount);

        // And the trail records it on both sides, so nobody has to infer it.
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => EventRegistration::class,
            'auditable_id' => $registration->id,
            'event' => 'amount.recalculated',
        ]);
    }

    public function test_a_recalculated_discount_larger_than_the_corrected_items_floors_at_zero(): void
    {
        $event = $this->event([
            'registration_mode' => Event::MODE_GROUPING,
            'charges_addons_per_participant' => true,
            'fee' => 0,
            'min_players' => 1,
            'max_players' => 20,
        ]);

        [$addon, $small] = $this->shirt($event, 40);

        $registration = $this->registration($event, ['addons_total' => 40], people: 1);

        $registration->addonLines()->create([
            'event_participant_id' => $registration->participants->first()->id,
            'event_addon_id' => $addon->id,
            'event_addon_variant_id' => $small->id,
            'name' => $addon->name,
            'variant_label' => $small->label,
            'unit_price' => 40,
            'quantity' => 1,
            'line_total' => 40,
        ]);

        // A full discount, so there was nothing to pay.
        $this->writer()->apply($registration->fresh(['participants', 'event']), $this->percentageCoupon(100));

        $registration->refresh();
        $this->assertSame('0.00', $registration->amount);

        app(RegistrationTotalsRecalculator::class)->apply($event->fresh());

        $registration->refresh();

        $this->assertSame('0.00', $registration->amount, 'A full discount must never recalculate into a debt.');
        $this->assertTrue($registration->isFree());
        $this->assertSame(EventRegistration::PAYMENT_PAID, $registration->payment_status);
    }

    public function test_the_recalculation_preview_names_the_discount_and_the_code_on_screen(): void
    {
        $event = $this->event(['fee' => 200]);
        $registration = $this->registration($event);

        // Unlimited, so the code on screen is the batch name itself rather than a
        // random minted one, which is what makes the assertion readable.
        $coupon = $this->fixedCoupon(50, ['quantity' => 0, 'name' => 'KEEPME']);

        $outcome = $this->writer()->apply($registration, $coupon);
        $this->assertTrue($outcome->succeeded());

        $response = $this->actingAs($this->userWith(['participants.view', 'participants.update']))
            ->get(route('admin.event.participants.recalculate', $event));

        $response->assertOk();

        // Its own figure with the code beside it, so the operator can read along the
        // row rather than take it on trust from a paragraph.
        $response->assertSee('Coupon');
        $response->assertSee('RM 50.00');
        $response->assertSee('KEEPME');
        $response->assertSee('A coupon discount already given is');
    }

    /* ---------------------------------------------------------------------
     | Head counts
     * ------------------------------------------------------------------ */

    public function test_a_fully_discounted_entry_still_appears_in_the_per_event_head_count(): void
    {
        $event = $this->event(['fee' => 100]);

        $paid = $this->registration($event);
        $paid->forceFill(['amount_paid' => 100, 'payment_status' => EventRegistration::PAYMENT_PAID])->save();

        $free = $this->registration($event);
        $this->writer()->apply($free, $this->percentageCoupon(100));

        $rows = PaymentFigures::byEvent();

        $this->assertCount(1, $rows);

        // Two real people registered, and the free one must not vanish from the count
        // just because it holds no money.
        $this->assertSame(2, $rows[0]['count']);

        // The money figures are unchanged: a RM0.00 entry belongs in neither.
        $this->assertSame(100.0, $rows[0]['collected']);
        $this->assertSame(0.0, $rows[0]['outstanding']);
    }

    public function test_a_fully_discounted_entry_is_in_neither_collected_nor_outstanding(): void
    {
        $event = $this->event(['fee' => 100]);

        $free = $this->registration($event);
        $this->writer()->apply($free, $this->percentageCoupon(100));

        $this->assertSame(0.0, PaymentFigures::collected());
        $this->assertSame(0.0, PaymentFigures::outstanding());
    }
}
