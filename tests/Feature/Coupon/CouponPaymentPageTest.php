<?php

namespace Tests\Feature\Coupon;

use App\Http\Controllers\Payment\RegistrationPaymentController;
use App\Http\Controllers\Payment\ShopOrderPaymentController;
use App\Models\Coupon;
use App\Models\CouponCode;
use App\Models\EventRegistration;
use App\Models\ShopOrder;
use App\Models\ShopProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

/**
 * Applying a code to something already submitted.
 *
 * This is the screen the owner was looking at when he asked for the field, so it has
 * one, and one rule: ONLY WHILE NOTHING HAS BEEN PAID.
 *
 * A discount on a part-paid or settled record would reduce the charge below money
 * already received, which creates a credit nobody has decided how to refund. So the
 * field is not drawn and the endpoint refuses — both, because a signed link lives for
 * thirty days and the page it was drawn from can be long out of date by the time it is
 * posted. The refusal is what most of this file asserts.
 */
class CouponPaymentPageTest extends CouponTestCase
{
    use RefreshDatabase;

    /* ---------------------------------------------------------------------
     | Registrations
     * ------------------------------------------------------------------ */

    private function paymentPage(EventRegistration $registration)
    {
        return $this->get(RegistrationPaymentController::urlFor($registration));
    }

    private function applyTo(EventRegistration $registration, string $code)
    {
        return $this->post(
            URL::temporarySignedRoute(
                'registration.payment.coupon',
                now()->addDay(),
                ['reference' => $registration->reference],
            ),
            ['voucher_code' => $code],
        );
    }

    public function test_the_field_appears_while_nothing_has_been_paid(): void
    {
        $event = $this->event(['fee' => 100]);
        $event->coupons()->attach($this->percentageCoupon(30, ['quantity' => 5]));

        $registration = $this->registration($event);

        $this->paymentPage($registration)
            ->assertOk()
            ->assertSee('name="voucher_code"', false)
            ->assertSee('Voucher Code');
    }

    public function test_the_field_is_absent_when_no_coupon_is_ticked_on_the_event(): void
    {
        $registration = $this->registration($this->event(['fee' => 100]));

        $this->paymentPage($registration)
            ->assertOk()
            ->assertDontSee('name="voucher_code"', false);
    }

    public function test_a_code_applied_here_reduces_what_is_owed(): void
    {
        $event = $this->event(['fee' => 200]);
        $coupon = $this->percentageCoupon(25, ['quantity' => 5]);
        $event->coupons()->attach($coupon);

        $registration = $this->registration($event);
        $code = $coupon->name;

        $response = $this->applyTo($registration, $code);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('coupon_status');

        $registration->refresh();

        $this->assertSame('50.00', $registration->discount_amount);
        $this->assertSame('150.00', $registration->amount);
        $this->assertSame(150.0, $registration->outstandingAmount());
        $this->assertTrue($registration->owesBalance());

        $claimed = CouponCode::query()->where('code', $code)->sole();

        $this->assertTrue($claimed->isRedeemed());
        $this->assertSame($registration->id, $claimed->event_registration_id);

        // And the page now says so: the coupon line, and the reduced total.
        $page = $this->paymentPage($registration->fresh());

        $page->assertSee('RM 50.00');
        $page->assertSee('RM 150.00');
        $page->assertSee($claimed->codeLabel());

        // One coupon per entry, so the box is gone once one is on.
        $page->assertDontSee('name="voucher_code"', false);
    }

    public function test_a_full_coupon_applied_here_stops_offering_payment(): void
    {
        $event = $this->event(['fee' => 120]);
        $coupon = $this->percentageCoupon(100, ['quantity' => 2]);
        $event->coupons()->attach($coupon);

        $registration = $this->registration($event);

        $this->applyTo($registration, $coupon->name)->assertSessionHasNoErrors();

        $registration->refresh();

        // Through the existing isFree() path: paymentStatusFromLedger() already
        // answers PAID for a free entry, so no new status rule was needed.
        $this->assertSame('0.00', $registration->amount);
        $this->assertTrue($registration->isFree());
        $this->assertSame(EventRegistration::PAYMENT_PAID, $registration->payment_status);
        $this->assertSame(EventRegistration::STATUS_CONFIRMED, $registration->status);
        $this->assertFalse($registration->owesBalance());

        $page = $this->paymentPage($registration);

        $page->assertSee('Nothing left to pay');
        $page->assertDontSee('name="voucher_code"', false);
        $page->assertDontSee('Pay RM');
    }

    /* ---------------------------------------------------------------------
     | The refusal. The reason this endpoint has a rule at all.
     * ------------------------------------------------------------------ */

    public function test_a_part_paid_registration_is_refused_and_shown_no_field(): void
    {
        $event = $this->event(['fee' => 200]);
        $coupon = $this->percentageCoupon(50, ['quantity' => 5]);
        $event->coupons()->attach($coupon);

        $registration = $this->registration($event);

        // RM80 has arrived by hand. Taking RM100 off now would leave a charge below
        // the money already received.
        $registration->forceFill([
            'amount_paid' => 80,
            'payment_status' => EventRegistration::PAYMENT_PARTIAL,
        ])->save();

        // Not offered.
        $this->paymentPage($registration)
            ->assertOk()
            ->assertDontSee('name="voucher_code"', false);

        // And refused even when posted directly at a valid signed URL.
        $response = $this->applyTo($registration, $coupon->name);

        $response->assertSessionHasErrors('voucher_code');

        $registration->refresh();

        $this->assertSame('0.00', $registration->discount_amount);
        $this->assertSame('200.00', $registration->amount);
        $this->assertNull($registration->coupon_code_id);
        $this->assertSame(EventRegistration::PAYMENT_PARTIAL, $registration->payment_status);

        // Nothing was claimed, so the code is still there to be used properly.
        $this->assertSame(0, CouponCode::query()->redeemed()->count());
        $this->assertSame(5, $coupon->fresh()->remaining());
    }

    public function test_a_paid_registration_is_refused_and_shown_no_field(): void
    {
        $event = $this->event(['fee' => 200]);
        $coupon = $this->percentageCoupon(50, ['quantity' => 5]);
        $event->coupons()->attach($coupon);

        $registration = $this->registration($event);

        $registration->forceFill([
            'amount_paid' => 200,
            'payment_status' => EventRegistration::PAYMENT_PAID,
            'status' => EventRegistration::STATUS_CONFIRMED,
        ])->save();

        $this->paymentPage($registration)
            ->assertOk()
            ->assertDontSee('name="voucher_code"', false);

        $this->applyTo($registration, $coupon->name)
            ->assertSessionHasErrors('voucher_code');

        $registration->refresh();

        $this->assertSame('0.00', $registration->discount_amount);
        $this->assertSame('200.00', $registration->amount);
        $this->assertSame(0, CouponCode::query()->redeemed()->count());
    }

    public function test_the_refusal_says_how_much_has_already_been_received(): void
    {
        $event = $this->event(['fee' => 200]);
        $coupon = $this->percentageCoupon(50, ['quantity' => 5]);
        $event->coupons()->attach($coupon);

        $registration = $this->registration($event);

        $registration->forceFill([
            'amount_paid' => 80,
            'payment_status' => EventRegistration::PAYMENT_PARTIAL,
        ])->save();

        $this->applyTo($registration, $coupon->name)
            ->assertSessionHasErrors([
                'voucher_code' => 'We have already received RM 80.00 against this registration, so a coupon cannot be applied to it now. Contact us quoting '.$registration->reference.'.',
            ]);
    }

    public function test_a_second_coupon_cannot_be_stacked_on_a_registration(): void
    {
        $event = $this->event(['fee' => 200]);
        $first = $this->percentageCoupon(25, ['quantity' => 5]);
        $second = $this->percentageCoupon(25, ['quantity' => 5]);
        $event->coupons()->attach([$first->id, $second->id]);

        $registration = $this->registration($event);

        $this->applyTo($registration, $first->name)->assertSessionHasNoErrors();

        $this->applyTo($registration->fresh(), $second->name)
            ->assertSessionHasErrors('voucher_code');

        $this->assertSame('50.00', $registration->fresh()->discount_amount);
        $this->assertSame(1, CouponCode::query()->redeemed()->count());
    }

    public function test_an_unsigned_post_is_refused_outright(): void
    {
        $event = $this->event(['fee' => 200]);
        $coupon = $this->percentageCoupon(50, ['quantity' => 5]);
        $event->coupons()->attach($coupon);

        $registration = $this->registration($event);

        $this->post(
            route('registration.payment.coupon', ['reference' => $registration->reference]),
            ['voucher_code' => $coupon->name],
        )->assertForbidden();

        $this->assertSame('200.00', $registration->fresh()->amount);
    }

    /* ---------------------------------------------------------------------
     | Shop orders
     * ------------------------------------------------------------------ */

    private function orderFor(ShopProduct $product, array $overrides = []): ShopOrder
    {
        $order = ShopOrder::create($overrides + [
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
            'items_total' => 100.00,
            'shipping_total' => 10.00,
            'grand_total' => 110.00,
            'shipping_label' => 'Flat rate, Peninsular Malaysia',
        ]);

        $order->items()->create([
            'shop_product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'unit_price' => 50.00,
            'quantity' => 2,
            'line_total' => 100.00,
            'weight_grams' => 300,
        ]);

        return $order->fresh('items');
    }

    private function orderPage(ShopOrder $order)
    {
        return $this->get(ShopOrderPaymentController::urlFor($order));
    }

    private function applyToOrder(ShopOrder $order, string $code)
    {
        return $this->post(
            URL::temporarySignedRoute(
                'shop.order.coupon',
                now()->addDay(),
                ['reference' => $order->reference],
            ),
            ['voucher_code' => $code],
        );
    }

    public function test_an_order_offers_the_field_while_nothing_has_been_paid(): void
    {
        $this->shopOpenWithGateway();

        $product = $this->product(['price' => 50]);
        $coupon = $this->fixedCoupon(20, ['kind' => Coupon::KIND_SHOP, 'quantity' => 5]);
        $product->coupons()->attach($coupon);

        $this->orderPage($this->orderFor($product))
            ->assertOk()
            ->assertSee('name="voucher_code"', false);
    }

    public function test_a_code_applied_to_an_order_comes_off_the_goods_only(): void
    {
        $this->shopOpenWithGateway();

        $product = $this->product(['price' => 50]);
        $coupon = $this->fixedCoupon(20, ['kind' => Coupon::KIND_SHOP, 'quantity' => 5]);
        $product->coupons()->attach($coupon);

        $order = $this->orderFor($product);

        $this->applyToOrder($order, $coupon->name)->assertSessionHasNoErrors();

        $order->refresh();

        $this->assertSame('100.00', $order->items_total);
        $this->assertSame('20.00', $order->discount_total);

        // Untouched.
        $this->assertSame('10.00', $order->shipping_total);
        $this->assertSame('90.00', $order->grand_total);
        $this->assertTrue($order->awaitsGatewayPayment());
    }

    public function test_a_full_coupon_settles_an_order_from_its_confirmation_page(): void
    {
        $this->shopOpenWithGateway();

        // Collected, so there is no postage left behind to pay.
        $product = $this->product([
            'price' => 50,
            'fulfilment' => ShopProduct::FULFILMENT_OFFLINE,
            'collection_location' => 'Dewan Serbaguna',
            'collection_at' => now()->addWeek()->setTime(10, 0),
        ]);

        $coupon = $this->percentageCoupon(100, ['kind' => Coupon::KIND_SHOP, 'quantity' => 2]);
        $product->coupons()->attach($coupon);

        $order = $this->orderFor($product, [
            'fulfilment' => ShopOrder::FULFILMENT_OFFLINE,
            'shipping_total' => 0,
            'grand_total' => 100.00,
            'collection_label' => 'Collection point',
            'collection_location' => 'Dewan Serbaguna',
            'collection_at' => now()->addWeek()->setTime(10, 0),
        ]);

        $this->applyToOrder($order, $coupon->name)->assertSessionHasNoErrors();

        $order->refresh();

        $this->assertSame('100.00', $order->discount_total);
        $this->assertSame('0.00', $order->grand_total);
        $this->assertSame(ShopOrder::STATUS_PAID, $order->status);
        $this->assertTrue($order->isPaid());
        $this->assertFalse($order->awaitsGatewayPayment());

        $page = $this->orderPage($order);

        $page->assertSee('Nothing left to pay');
        $page->assertDontSee('name="voucher_code"', false);
    }

    public function test_a_paid_order_is_refused_and_shown_no_field(): void
    {
        $this->shopOpenWithGateway();

        $product = $this->product(['price' => 50]);
        $coupon = $this->fixedCoupon(20, ['kind' => Coupon::KIND_SHOP, 'quantity' => 5]);
        $product->coupons()->attach($coupon);

        $order = $this->orderFor($product);
        $order->forceFill(['status' => ShopOrder::STATUS_PAID, 'paid_at' => now()])->save();

        $this->orderPage($order)
            ->assertOk()
            ->assertDontSee('name="voucher_code"', false);

        $this->applyToOrder($order, $coupon->name)
            ->assertSessionHasErrors('voucher_code');

        $order->refresh();

        $this->assertSame('0.00', $order->discount_total);
        $this->assertSame('110.00', $order->grand_total);
        $this->assertSame(0, CouponCode::query()->redeemed()->count());
        $this->assertSame(5, $coupon->fresh()->remaining());
    }

    public function test_a_cancelled_order_is_refused(): void
    {
        $this->shopOpenWithGateway();

        $product = $this->product(['price' => 50]);
        $coupon = $this->fixedCoupon(20, ['kind' => Coupon::KIND_SHOP, 'quantity' => 5]);
        $product->coupons()->attach($coupon);

        $order = $this->orderFor($product);
        $order->forceFill(['status' => ShopOrder::STATUS_CANCELLED])->save();

        $this->applyToOrder($order, $coupon->name)
            ->assertSessionHasErrors('voucher_code');

        $this->assertSame('110.00', $order->fresh()->grand_total);
    }

    public function test_an_order_whose_products_carry_no_coupon_is_offered_nothing(): void
    {
        $this->shopOpenWithGateway();

        $product = $this->product(['price' => 50]);
        $coupon = $this->fixedCoupon(20, ['kind' => Coupon::KIND_SHOP, 'quantity' => 5]);

        // Created but never ticked on this product.
        $order = $this->orderFor($product);

        $this->orderPage($order)
            ->assertOk()
            ->assertDontSee('name="voucher_code"', false);

        $this->applyToOrder($order, $coupon->name)
            ->assertSessionHasErrors('voucher_code');

        $this->assertSame('110.00', $order->fresh()->grand_total);
    }
}
