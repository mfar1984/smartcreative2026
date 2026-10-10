<?php

namespace Tests\Feature\Coupon;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\ShopOrder;
use App\Services\Registration\RegistrationTotalsRecalculator;
use App\Support\PaymentFigures;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Nothing changes for a registration or an order that has no coupon.
 *
 * The whole module is additive, and this is the file that holds it to that. Every
 * figure here is the figure the system reported before coupons existed: two new
 * columns defaulting to nothing must not move a single sen of it.
 */
class CouponRegressionGuardTest extends CouponTestCase
{
    use RefreshDatabase;

    /* ---------------------------------------------------------------------
     | Registrations
     * ------------------------------------------------------------------ */

    public function test_a_registration_with_no_coupon_carries_no_discount(): void
    {
        $registration = $this->registration($this->event(['fee' => 150]), ['addons_total' => 50]);

        $this->assertSame('0.00', $registration->discount_amount);
        $this->assertNull($registration->coupon_code_id);
        $this->assertFalse($registration->hasDiscount());

        // The charge is the sum of the two columns that say what it is for, exactly
        // as it always was.
        $this->assertSame('200.00', $registration->amount);
        $this->assertSame(200.0, $registration->outstandingAmount());
        $this->assertFalse($registration->isFree());
    }

    public function test_the_public_registration_path_writes_the_same_figures_as_before(): void
    {
        $event = $this->event([
            'registration_mode' => Event::MODE_INDIVIDUAL,
            'fee' => 10,
        ]);

        [$addon, $small] = $this->shirt($event, 40);

        $this->post(route('registration.store', ['event' => $event->slug]), [
            'participants' => [[
                'role' => \App\Support\ParticipantOptions::ROLE_PARTICIPANT,
                'full_name' => 'Member One',
                'ic_number' => '900101010001',
                'address_line_1' => '1 Jalan Sibu',
                'city' => 'Sibu',
                'state' => 'Sarawak',
                'country' => 'Malaysia',
                'phone' => '0140000001',
                'email' => 'member1@example.com',
                'gender' => 'male',
                'race' => 'malay',
            ]],
            'addons' => [$addon->id => ['choice' => (string) $small->id]],
        ])->assertSessionHasNoErrors();

        $registration = EventRegistration::query()->sole();

        $this->assertSame('10.00', $registration->registration_fee);
        $this->assertSame('40.00', $registration->addons_total);
        $this->assertSame('50.00', $registration->amount);
        $this->assertSame('0.00', $registration->discount_amount);
        $this->assertNull($registration->coupon_code_id);
        $this->assertSame(EventRegistration::PAYMENT_UNPAID, $registration->payment_status);
        $this->assertSame(EventRegistration::STATUS_PENDING, $registration->status);
    }

    public function test_a_free_event_still_confirms_and_settles_itself_on_arrival(): void
    {
        $event = $this->event(['fee' => null]);

        $this->post(route('registration.store', ['event' => $event->slug]), [
            'participants' => [[
                'role' => \App\Support\ParticipantOptions::ROLE_PARTICIPANT,
                'full_name' => 'Member One',
                'ic_number' => '900101010002',
                'address_line_1' => '1 Jalan Sibu',
                'city' => 'Sibu',
                'state' => 'Sarawak',
                'country' => 'Malaysia',
                'phone' => '0140000002',
                'email' => 'member2@example.com',
                'gender' => 'male',
                'race' => 'malay',
            ]],
        ])->assertSessionHasNoErrors();

        $registration = EventRegistration::query()->sole();

        $this->assertSame('0.00', $registration->amount);
        $this->assertSame('0.00', $registration->discount_amount);
        $this->assertSame(EventRegistration::PAYMENT_PAID, $registration->payment_status);
        $this->assertSame(EventRegistration::STATUS_CONFIRMED, $registration->status);
    }

    /* ---------------------------------------------------------------------
     | The money figures
     * ------------------------------------------------------------------ */

    public function test_the_payment_figures_report_the_same_numbers_for_entries_with_no_coupon(): void
    {
        $event = $this->event(['fee' => 100]);

        // Settled in full.
        $paid = $this->registration($event);
        $paid->forceFill([
            'amount_paid' => 100,
            'payment_status' => EventRegistration::PAYMENT_PAID,
        ])->save();

        // Part paid.
        $partial = $this->registration($event);
        $partial->forceFill([
            'amount_paid' => 40,
            'payment_status' => EventRegistration::PAYMENT_PARTIAL,
        ])->save();

        // Nothing received.
        $this->registration($event);

        // Cancelled, so nobody is going to pay it.
        $cancelled = $this->registration($event);
        $cancelled->forceFill(['status' => EventRegistration::STATUS_CANCELLED])->save();

        // Refunded in part.
        $refunded = $this->registration($event);
        $refunded->forceFill([
            'amount_paid' => 100,
            'refunded_amount' => 30,
            'payment_status' => EventRegistration::PAYMENT_PAID,
        ])->save();

        // 100 + 40 + 100 received, less 30 sent back.
        $this->assertSame(210.0, PaymentFigures::collected());

        // 60 still owed on the part-paid entry, 100 on the untouched one, and
        // nothing on the cancelled one.
        $this->assertSame(160.0, PaymentFigures::outstanding());

        $this->assertSame(240.0, PaymentFigures::grossCollected());
        $this->assertSame(30.0, PaymentFigures::refunded());

        $byEvent = PaymentFigures::byEvent();

        $this->assertCount(1, $byEvent);
        $this->assertSame(5, $byEvent[0]['count']);
        $this->assertSame(210.0, $byEvent[0]['collected'] - 30.0);
        $this->assertSame(160.0, $byEvent[0]['outstanding']);
    }

    public function test_the_per_event_count_is_unchanged_when_nothing_is_free(): void
    {
        $event = $this->event(['fee' => 100]);

        $this->registration($event);
        $this->registration($event);
        $this->registration($event);

        $rows = PaymentFigures::byEvent();

        $this->assertSame(3, $rows[0]['count']);
        $this->assertSame(0.0, $rows[0]['collected']);
        $this->assertSame(300.0, $rows[0]['outstanding']);
    }

    public function test_the_payment_status_breakdown_still_excludes_free_entries(): void
    {
        $event = $this->event(['fee' => 100]);

        $this->registration($event);

        // A free entry holds no money, so it stays out of the breakdown drawn beside
        // the takings. That was true before coupons and is still true.
        $this->registration($event, ['registration_fee' => 0, 'addons_total' => 0]);

        $counts = PaymentFigures::countsByStatus();

        $this->assertSame(1, $counts[EventRegistration::PAYMENT_UNPAID]);
        $this->assertSame(1, array_sum($counts));
    }

    /* ---------------------------------------------------------------------
     | Recalculation
     * ------------------------------------------------------------------ */

    public function test_recalculation_behaves_exactly_as_before_on_an_entry_with_no_coupon(): void
    {
        $event = $this->event([
            'registration_mode' => Event::MODE_GROUPING,
            'charges_addons_per_participant' => true,
            'fee' => 0,
            'min_players' => 1,
            'max_players' => 20,
        ]);

        [$addon, $small] = $this->shirt($event, 40);

        // Charged the old way: one RM40 shirt between three people.
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

        $recalculator = app(RegistrationTotalsRecalculator::class);
        $correction = $recalculator->preview($event->fresh())[0];

        $this->assertFalse($correction->hasDiscount());
        $this->assertSame(0.0, $correction->discount);

        // RM40 becomes RM120: the figure this screen has always reported.
        $this->assertSame(120.0, $correction->correctedAmount);
        $this->assertSame(80.0, $correction->difference());
        $this->assertTrue($correction->movesMoney());

        $recalculator->apply($event->fresh());

        $registration->refresh();

        $this->assertSame('120.00', $registration->amount);
        $this->assertSame('120.00', $registration->addons_total);
        $this->assertSame('0.00', $registration->discount_amount);
    }

    public function test_a_second_recalculation_is_still_a_no_op(): void
    {
        $event = $this->event([
            'registration_mode' => Event::MODE_GROUPING,
            'charges_addons_per_participant' => true,
            'fee' => 0,
            'min_players' => 1,
            'max_players' => 20,
        ]);

        [$addon, $small] = $this->shirt($event, 40);

        $registration = $this->registration($event, ['addons_total' => 40], people: 2);

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

        $recalculator = app(RegistrationTotalsRecalculator::class);

        $this->assertCount(1, $recalculator->apply($event->fresh()));
        $this->assertSame([], $recalculator->apply($event->fresh()));

        $this->assertSame('80.00', $registration->fresh()->amount);
    }

    /* ---------------------------------------------------------------------
     | Shop orders
     * ------------------------------------------------------------------ */

    public function test_a_shop_order_row_written_without_a_discount_reports_as_before(): void
    {
        $order = ShopOrder::create([
            'reference' => ShopOrder::nextReference(),
            'status' => ShopOrder::STATUS_PENDING_PAYMENT,
            'fulfilment' => ShopOrder::FULFILMENT_ONLINE,
            'payment_method' => ShopOrder::METHOD_GATEWAY,
            'customer_name' => 'Aminah Yusof',
            'customer_email' => 'buyer-'.uniqid().'@example.com',
            'customer_phone' => '0123456789',
            'address_line_1' => '1 Jalan Satu',
            'postcode' => '40000',
            'city' => 'Shah Alam',
            'state' => 'Selangor',
            'country' => 'Malaysia',
            'items_total' => 120.00,
            'shipping_total' => 10.00,
            'grand_total' => 130.00,
            'shipping_label' => 'Flat rate, Peninsular Malaysia',
        ]);

        // Read back, so the assertion is about what the database holds rather than
        // what happened to be in memory: the column default is what an order written
        // by a path that has never heard of coupons gets.
        $order = $order->fresh();

        $this->assertSame('0.00', $order->discount_total);
        $this->assertNull($order->coupon_code_id);
        $this->assertFalse($order->hasDiscount());
        $this->assertSame(120.0, $order->discountedItemsTotal());
        $this->assertSame('130.00', $order->grand_total);
        $this->assertTrue($order->awaitsGatewayPayment());
        $this->assertSame('RM 130.00', $order->grandTotalLabel());
    }
}
