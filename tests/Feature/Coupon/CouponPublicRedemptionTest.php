<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\CouponCode;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\ShopOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/**
 * A code typed on the public side, claimed when the form is submitted.
 *
 * The money path, so this is the file that matters most. Three things are asserted
 * hardest:
 *
 *   the arithmetic    percentage and ringgit, on a registration and on an order with
 *                     postage, proving the postage is never discounted.
 *   the free path     a coupon covering the charge completes with NO payment step and
 *                     never reaches the gateway. Http::assertNothingSent() is the
 *                     assertion: ChipGateway's own "nothing to pay" refusal is a
 *                     backstop, not the path, and a test that relied on it would pass
 *                     while sending visitors to a gateway that throws at them.
 *   the race          two submissions for the last code. Exactly one wins, the loser
 *                     is told and is charged the normal price, and no second
 *                     redemption row exists.
 */
class CouponPublicRedemptionTest extends CouponTestCase
{
    use RefreshDatabase;

    /**
     * Nothing may reach the gateway, and we would know if it tried.
     *
     * Http::fake() with no stubs both captures the request and answers it with an
     * empty 200, which is what makes assertNothingSent() an assertion rather than a
     * hope: a real request would escape to the network and be recorded nowhere.
     *
     * Registered per test rather than in setUp, because the first matching stub wins
     * and a catch-all registered up front would shadow the purchase stub the tests
     * that DO expect a gateway call rely on.
     */
    private function expectNoGatewayCall(): void
    {
        Http::fake();
    }

    /** Submit the public registration form for one person. */
    private function register(Event $event, array $fields = [])
    {
        return $this->post(route('registration.store', ['event' => $event->slug]), $fields + [
            'participants' => [$this->participantFields()],
        ]);
    }

    /* ---------------------------------------------------------------------
     | The arithmetic
     * ------------------------------------------------------------------ */

    public function test_a_percentage_code_reduces_a_registration(): void
    {
        $event = $this->event(['fee' => 200]);
        $coupon = $this->percentageCoupon(30, ['quantity' => 5]);
        $event->coupons()->attach($coupon);

        $code = $coupon->name;

        $this->register($event, ['voucher_code' => $code])->assertSessionHasNoErrors();

        $registration = EventRegistration::query()->sole();

        $this->assertSame('200.00', $registration->registration_fee);
        $this->assertSame('60.00', $registration->discount_amount);
        $this->assertSame('140.00', $registration->amount);
        $this->assertTrue($registration->hasDiscount());

        // The redemption points at the entry it paid for, which is what Tracking reads.
        $claimed = CouponCode::query()->where('code', $code)->sole();

        $this->assertTrue($claimed->isRedeemed());
        $this->assertSame('60.00', $claimed->discount_amount);
        $this->assertSame($registration->id, $claimed->event_registration_id);
        $this->assertSame($claimed->id, $registration->coupon_code_id);
        $this->assertSame(4, $coupon->fresh()->remaining());
    }

    public function test_a_ringgit_code_reduces_a_registration(): void
    {
        $event = $this->event(['fee' => 100]);
        $coupon = $this->fixedCoupon(25, ['quantity' => 2]);
        $event->coupons()->attach($coupon);

        $this->register($event, ['voucher_code' => $coupon->name])
            ->assertSessionHasNoErrors();

        $registration = EventRegistration::query()->sole();

        $this->assertSame('25.00', $registration->discount_amount);
        $this->assertSame('75.00', $registration->amount);
    }

    public function test_a_code_comes_off_the_fee_and_the_extras_together(): void
    {
        $event = $this->event(['fee' => 10]);
        [$addon, $small] = $this->shirt($event, 40);

        $coupon = $this->percentageCoupon(50, ['quantity' => 2]);
        $event->coupons()->attach($coupon);

        $this->register($event, [
            'voucher_code' => $coupon->name,
            'addons' => [$addon->id => ['choice' => (string) $small->id]],
        ])->assertSessionHasNoErrors();

        $registration = EventRegistration::query()->sole();

        // Half of RM50, not half of the fee alone. The two columns that say what was
        // bought are untouched by the discount.
        $this->assertSame('10.00', $registration->registration_fee);
        $this->assertSame('40.00', $registration->addons_total);
        $this->assertSame('25.00', $registration->discount_amount);
        $this->assertSame('25.00', $registration->amount);
    }

    public function test_a_ringgit_code_is_owed_once_per_head_on_a_per_participant_event(): void
    {
        $event = $this->event([
            'registration_mode' => Event::MODE_GROUPING,
            'charges_addons_per_participant' => true,
            'fee' => 300,
            'min_players' => 1,
            'max_players' => 20,
        ]);

        $coupon = $this->fixedCoupon(10, ['quantity' => 2]);
        $event->coupons()->attach($coupon);

        $this->post(route('registration.store', ['event' => $event->slug]), [
            'voucher_code' => $coupon->name,
            'team_name' => 'Kumpulan Sibu',
            'participants' => [
                $this->participantFields(),
                $this->participantFields(['full_name' => 'Member Two']),
                $this->participantFields(['full_name' => 'Member Three']),
            ],
        ])->assertSessionHasNoErrors();

        $registration = EventRegistration::query()->sole();

        // RM10 each for three people, because each of them is charged for their own
        // extras. Same rule AddonOrder prices by.
        $this->assertSame(3, $registration->participants()->count());
        $this->assertSame('30.00', $registration->discount_amount);
        $this->assertSame('270.00', $registration->amount);
    }

    /* ---------------------------------------------------------------------
     | The shop
     * ------------------------------------------------------------------ */

    public function test_a_code_comes_off_the_goods_and_leaves_the_postage_alone(): void
    {
        $this->shopOpenWithGateway();
        $this->flatShipping(10);
        $this->fakeChipPurchase();

        $product = $this->product(['price' => 50]);
        $coupon = $this->percentageCoupon(20, ['kind' => Coupon::KIND_SHOP, 'quantity' => 3]);
        $product->coupons()->attach($coupon);

        $this->withSession($this->basket($product, 2))
            ->post(route('checkout.place'), $this->checkoutFields([
                'voucher_code' => $coupon->name,
            ]));

        $order = ShopOrder::query()->sole();

        $this->assertSame('100.00', $order->items_total);
        $this->assertSame('20.00', $order->discount_total);

        // Untouched. The assertion the whole rule exists for.
        $this->assertSame('10.00', $order->shipping_total);
        $this->assertSame('90.00', $order->grand_total);

        $claimed = CouponCode::query()->whereKey($order->coupon_code_id)->sole();

        $this->assertSame($order->id, $claimed->shop_order_id);
        $this->assertSame('20.00', $claimed->discount_amount);
    }

    public function test_a_code_worth_more_than_the_goods_never_eats_the_postage(): void
    {
        $this->shopOpenWithGateway();
        $this->flatShipping(10);
        $this->fakeChipPurchase();

        $product = $this->product(['price' => 30]);
        $coupon = $this->fixedCoupon(500, ['kind' => Coupon::KIND_SHOP, 'quantity' => 2]);
        $product->coupons()->attach($coupon);

        $this->withSession($this->basket($product))
            ->post(route('checkout.place'), $this->checkoutFields([
                'voucher_code' => $coupon->name,
            ]));

        $order = ShopOrder::query()->sole();

        $this->assertSame('30.00', $order->discount_total, 'Capped at the goods.');
        $this->assertSame('10.00', $order->shipping_total);

        // The postage still has to be collected, so the total is the postage alone
        // rather than zero and never negative.
        $this->assertSame('10.00', $order->grand_total);
    }

    /* ---------------------------------------------------------------------
     | Covered in full: no payment step at all
     * ------------------------------------------------------------------ */

    public function test_a_hundred_percent_coupon_settles_a_registration_without_the_gateway(): void
    {
        $this->expectNoGatewayCall();

        $event = $this->event(['fee' => 150]);
        $coupon = $this->percentageCoupon(100, ['quantity' => 2]);
        $event->coupons()->attach($coupon);

        $response = $this->register($event, ['voucher_code' => $coupon->name]);

        $response->assertSessionHasNoErrors();

        // Straight back to the event list with a confirmation, not to a payment page.
        $response->assertRedirect(route('registration'));
        $response->assertSessionHas('registration_reference');

        $registration = EventRegistration::query()->sole();

        $this->assertSame('150.00', $registration->discount_amount);
        $this->assertSame('0.00', $registration->amount);
        $this->assertTrue($registration->isFree());
        $this->assertSame(EventRegistration::PAYMENT_PAID, $registration->payment_status);
        $this->assertSame(EventRegistration::STATUS_CONFIRMED, $registration->status);
        $this->assertFalse($registration->awaitingPayment());
        $this->assertFalse($registration->owesBalance());

        // No purchase was opened, and no checkout row written. The gateway's own
        // refusal is a backstop; this proves we never get there.
        Http::assertNothingSent();
        $this->assertSame(0, $registration->checkouts()->count());
        $this->assertNull($registration->payment_reference);
    }

    public function test_the_registration_button_offers_a_free_registration_at_a_hundred_percent(): void
    {
        // The label is swapped by the running total, which is why the wording has to
        // be in the page for it to reach.
        $event = $this->event(['fee' => 150]);
        $event->coupons()->attach($this->percentageCoupon(100, ['quantity' => 2]));

        $this->get(route('registration'))
            ->assertOk()
            ->assertSee('Free Registration');
    }

    public function test_a_full_coupon_settles_an_order_without_the_gateway(): void
    {
        $this->expectNoGatewayCall();
        $this->shopOpenWithGateway();

        // Collected at the counter, so there is no postage to leave behind.
        $product = $this->product([
            'price' => 40,
            'fulfilment' => \App\Models\ShopProduct::FULFILMENT_OFFLINE,
            'collection_location' => 'Dewan Serbaguna',
            'collection_at' => now()->addWeek()->setTime(10, 0),
        ]);

        $coupon = $this->percentageCoupon(100, ['kind' => Coupon::KIND_SHOP, 'quantity' => 2]);
        $product->coupons()->attach($coupon);

        $response = $this->withSession($this->basket($product))
            ->post(route('checkout.place'), $this->checkoutFields([
                'identity_card' => '900101071234',
                'voucher_code' => $coupon->name,
            ]));

        $order = ShopOrder::query()->sole();

        // To its own confirmation page, not away to a gateway.
        $response->assertRedirectContains('/order/' . $order->reference);

        $this->assertSame('40.00', $order->discount_total);
        $this->assertSame('0.00', $order->grand_total);
        $this->assertSame(ShopOrder::STATUS_PAID, $order->status);
        $this->assertTrue($order->isPaid());
        $this->assertFalse($order->awaitsGatewayPayment());

        Http::assertNothingSent();
        $this->assertSame(0, $order->checkouts()->count());
    }

    public function test_the_checkout_button_offers_a_free_submission_at_a_hundred_percent(): void
    {
        $this->shopOpenWithGateway();
        $this->flatShipping();

        $product = $this->product(['price' => 40]);
        $product->coupons()->attach($this->percentageCoupon(100, ['kind' => Coupon::KIND_SHOP, 'quantity' => 2]));

        $this->withSession($this->basket($product))
            ->get(route('checkout'))
            ->assertOk()
            ->assertSee('Free Submission');
    }

    /* ---------------------------------------------------------------------
     | The race
     * ------------------------------------------------------------------ */

    public function test_two_submissions_for_the_last_code_produce_exactly_one_discount(): void
    {
        $event = $this->event(['fee' => 100]);

        // One code in the batch, so there is nothing to fall back to.
        $coupon = $this->percentageCoupon(100, ['quantity' => 1]);
        $event->coupons()->attach($coupon);

        $code = $coupon->name;

        $this->register($event, ['voucher_code' => $code])->assertSessionHasNoErrors();
        $this->register($event, ['voucher_code' => $code])->assertSessionHasNoErrors();

        $entries = EventRegistration::query()->orderBy('id')->get();

        $this->assertCount(2, $entries, 'Losing the race must not cost anybody their place.');

        // The winner.
        $this->assertSame('0.00', $entries[0]->amount);
        $this->assertSame('100.00', $entries[0]->discount_amount);
        $this->assertSame(EventRegistration::PAYMENT_PAID, $entries[0]->payment_status);

        // The loser, charged the normal price rather than given a free place.
        $this->assertSame('100.00', $entries[1]->amount);
        $this->assertSame('0.00', $entries[1]->discount_amount);
        $this->assertNull($entries[1]->coupon_code_id);
        $this->assertTrue($entries[1]->awaitingPayment());

        // Exactly one redemption row, and no second claim against the batch.
        $this->assertSame(1, CouponCode::query()->redeemed()->count());
        $this->assertSame(0, $coupon->fresh()->remaining());
        $this->assertTrue($coupon->fresh()->isExhausted());
    }

    public function test_the_loser_is_told_the_coupon_was_already_gone(): void
    {
        $event = $this->event(['fee' => 100]);
        $coupon = $this->percentageCoupon(50, ['quantity' => 1]);
        $event->coupons()->attach($coupon);

        $code = $coupon->name;

        $this->register($event, ['voucher_code' => $code]);

        $second = $this->register($event, ['voucher_code' => $code]);

        $second->assertSessionHas('coupon_status', 'That coupon has been fully used, so the normal price applies.');
    }

    public function test_an_unlimited_coupon_discounts_everybody_who_types_it(): void
    {
        $event = $this->event(['fee' => 100]);
        $coupon = $this->fixedCoupon(20, ['quantity' => 0]);
        $event->coupons()->attach($coupon);

        $this->register($event, ['voucher_code' => $coupon->name]);
        $this->register($event, ['voucher_code' => $coupon->name]);

        foreach (EventRegistration::query()->get() as $registration) {
            $this->assertSame('20.00', $registration->discount_amount);
            $this->assertSame('80.00', $registration->amount);
        }

        $this->assertSame(2, $coupon->fresh()->redeemedCount());
        $this->assertFalse($coupon->fresh()->isExhausted());
    }

    /* ---------------------------------------------------------------------
     | Refused at submit, not merely hidden
     * ------------------------------------------------------------------ */

    public function test_an_expired_code_is_refused_at_submit_and_the_normal_price_applies(): void
    {
        $event = $this->event(['fee' => 100]);

        $coupon = $this->percentageCoupon(50, [
            'quantity' => 5,
            'expires_at' => now()->subDay()->toDateString(),
        ]);

        // Ticked, so a stale page could well still be offering it.
        $event->coupons()->attach($coupon);

        $response = $this->register($event, ['voucher_code' => $coupon->name]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('coupon_status', 'That coupon has expired, so the normal price applies.');

        $registration = EventRegistration::query()->sole();

        $this->assertSame('100.00', $registration->amount);
        $this->assertSame('0.00', $registration->discount_amount);
        $this->assertSame(0, CouponCode::query()->redeemed()->count());
        $this->assertSame(5, $coupon->fresh()->remaining());
    }

    public function test_an_exhausted_coupon_is_refused_at_submit(): void
    {
        $event = $this->event(['fee' => 100]);
        $coupon = $this->fixedCoupon(20, ['quantity' => 1]);
        $event->coupons()->attach($coupon);

        $this->register($event, ['voucher_code' => $coupon->name]);

        // A second visitor with the same code. Nothing is left to give.
        $response = $this->register($event, ['voucher_code' => $coupon->name]);

        $response->assertSessionHasNoErrors();

        $second = EventRegistration::query()->orderByDesc('id')->first();

        $this->assertSame('100.00', $second->amount);
        $this->assertSame('0.00', $second->discount_amount);
        $this->assertSame(1, CouponCode::query()->redeemed()->count());
        $this->assertSame(1, CouponCode::query()->count(), 'The ledger gains exactly one row.');
    }

    public function test_a_code_for_a_batch_that_is_not_ticked_here_is_refused_at_submit(): void
    {
        $event = $this->event(['fee' => 100]);
        $event->coupons()->attach($this->percentageCoupon(10, ['quantity' => 5]));

        // Another event's live batch, posted by hand at this one.
        $other = $this->percentageCoupon(100, ['quantity' => 5]);

        $response = $this->register($event, ['voucher_code' => $other->name]);

        $response->assertSessionHas('coupon_status', 'That coupon cannot be used here.');

        $registration = EventRegistration::query()->sole();

        $this->assertSame('100.00', $registration->amount);
        $this->assertSame(5, $other->fresh()->remaining());
    }

    public function test_a_code_posted_at_an_event_offering_nothing_is_refused(): void
    {
        // Nothing ticked at all, so there was no box: a stale page or a crafted post.
        $event = $this->event(['fee' => 100]);
        $coupon = $this->percentageCoupon(100, ['quantity' => 5]);

        $this->register($event, ['voucher_code' => $coupon->name]);

        $registration = EventRegistration::query()->sole();

        $this->assertSame('100.00', $registration->amount);
        $this->assertSame(5, $coupon->fresh()->remaining());
    }

    public function test_an_unknown_code_costs_the_discount_and_never_the_place(): void
    {
        $event = $this->event(['fee' => 100]);
        $event->coupons()->attach($this->percentageCoupon(30, ['quantity' => 5]));

        $response = $this->register($event, ['voucher_code' => 'NOSUCH']);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('coupon_status', 'That coupon code was not recognised.');

        $this->assertSame('100.00', EventRegistration::query()->sole()->amount);
    }

    public function test_a_shop_code_is_refused_at_a_registration(): void
    {
        $event = $this->event(['fee' => 100]);
        $shopBatch = $this->percentageCoupon(100, ['kind' => Coupon::KIND_SHOP, 'quantity' => 5]);

        // Ticked on the event, which the pivot allows and the rule does not.
        $event->coupons()->attach($shopBatch);

        $this->register($event, ['voucher_code' => $shopBatch->name]);

        $this->assertSame('100.00', EventRegistration::query()->sole()->amount);
        $this->assertSame(5, $shopBatch->fresh()->remaining());
    }

    /* ---------------------------------------------------------------------
     | Nothing changes without a coupon
     * ------------------------------------------------------------------ */

    public function test_a_registration_with_no_code_typed_is_exactly_as_before(): void
    {
        $event = $this->event(['fee' => 100]);

        // Ticked, so the box is on screen. Nothing typed into it.
        $event->coupons()->attach($this->percentageCoupon(100, ['quantity' => 5]));

        $response = $this->register($event);

        $response->assertSessionHasNoErrors();
        $response->assertSessionMissing('coupon_status');

        $registration = EventRegistration::query()->sole();

        $this->assertSame('100.00', $registration->amount);
        $this->assertSame('0.00', $registration->discount_amount);
        $this->assertNull($registration->coupon_code_id);
        $this->assertSame(EventRegistration::PAYMENT_UNPAID, $registration->payment_status);
        $this->assertSame(EventRegistration::STATUS_PENDING, $registration->status);
        $this->assertTrue($registration->awaitingPayment());

        // Straight to the payment page, as it always did.
        $response->assertRedirectContains('/registration/payment/' . $registration->reference);
    }

    public function test_an_order_with_no_code_typed_still_reaches_the_gateway(): void
    {
        $this->shopOpenWithGateway();
        $this->flatShipping(10);
        $this->fakeChipPurchase('pur_plain');

        $product = $this->product(['price' => 50]);
        $product->coupons()->attach($this->percentageCoupon(100, ['kind' => Coupon::KIND_SHOP, 'quantity' => 5]));

        $response = $this->withSession($this->basket($product))
            ->post(route('checkout.place'), $this->checkoutFields());

        $response->assertRedirect('https://gate.chip-in.asia/p/pur_plain');

        $order = ShopOrder::query()->sole();

        $this->assertSame('0.00', $order->discount_total);
        $this->assertSame('60.00', $order->grand_total);
        $this->assertNull($order->coupon_code_id);
        $this->assertSame(ShopOrder::STATUS_PENDING_PAYMENT, $order->status);
        $this->assertSame(0, CouponCode::query()->redeemed()->count());
    }

    public function test_an_event_with_no_coupon_ticked_behaves_exactly_as_before(): void
    {
        $event = $this->event(['fee' => 100]);

        $this->get(route('registration'))
            ->assertOk()
            ->assertDontSee('name="voucher_code"', false)
            ->assertSee('Continue to Payment');

        $this->register($event)->assertSessionHasNoErrors();

        $registration = EventRegistration::query()->sole();

        $this->assertSame('100.00', $registration->amount);
        $this->assertSame('0.00', $registration->discount_amount);
    }
}
