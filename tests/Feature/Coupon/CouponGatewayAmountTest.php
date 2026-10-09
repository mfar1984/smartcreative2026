<?php

namespace Tests\Feature\Coupon;

use App\Models\Setting;
use App\Models\ShopOrder;
use App\Services\Coupon\RegistrationCouponWriter;
use App\Services\Payment\CheckoutUrls;
use App\Services\Payment\ChipGateway;
use App\Services\Payment\RegistrationBalanceCharge;
use App\Services\Payment\ShopOrderChargeBuilder;
use App\Services\ShopOrderWriter;
use App\Support\Cart;
use App\Support\PaymentSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/**
 * The gateway is asked for the POST-discount figure.
 *
 * If CHIP is sent the full price while our books record a discount, every payment
 * after it reconciles wrong, so this is asserted at the one place that actually
 * decides the number: the body posted to the purchases endpoint.
 *
 * CHIP also totals the product lines itself and both builders refuse a cent-level
 * mismatch, so a discounted purchase whose lines still added up to the old price
 * would be refused outright. That refusal is asserted here too, by proving the lines
 * agree with the charge.
 */
class CouponGatewayAmountTest extends CouponTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::write('integration.payments.provider', PaymentSettings::PROVIDER_CHIP, 'integration.payments');
        Setting::write('integration.payments.chip_brand_id', 'brand-' . uniqid(), 'integration.payments');
        Setting::write('integration.payments.chip_api_key', 'key-' . uniqid(), 'integration.payments');
        Setting::write('integration.payments.currency', 'MYR', 'integration.payments');
    }

    private function urls(): CheckoutUrls
    {
        return new CheckoutUrls(
            success: 'https://example.com/ok',
            failure: 'https://example.com/no',
            cancel: 'https://example.com/back',
            callback: 'https://example.com/webhook',
        );
    }

    private function fakeChip(): void
    {
        Http::fake([
            'gate.chip-in.asia/api/v1/purchases/' => Http::response([
                'id' => 'pur_test_' . uniqid(),
                'checkout_url' => 'https://gate.chip-in.asia/p/test',
            ]),
        ]);
    }

    /**
     * The purchase body CHIP was actually sent.
     *
     * @return array<string, mixed>
     */
    private function sentPayload(): array
    {
        $sent = null;

        Http::recorded(function ($request) use (&$sent) {
            if (str_contains($request->url(), '/purchases/')) {
                $sent = $request->data();
            }

            return true;
        });

        $this->assertNotNull($sent, 'No purchase was posted to CHIP.');

        return $sent;
    }

    /** What the product lines on a payload add up to, in cents. */
    private function lineTotalCents(array $payload): int
    {
        return array_sum(array_map(
            fn (array $product) => $product['price'] * (int) $product['quantity'],
            $payload['purchase']['products'],
        ));
    }

    /* ---------------------------------------------------------------------
     | A registration checkout
     * ------------------------------------------------------------------ */

    public function test_a_registration_checkout_quotes_the_discounted_amount(): void
    {
        $this->fakeChip();

        $event = $this->event(['fee' => 200]);
        $registration = $this->registration($event, ['addons_total' => 40]);

        $this->assertSame('240.00', $registration->amount);

        app(RegistrationCouponWriter::class)->apply($registration, $this->percentageCoupon(25));

        $registration = $registration->fresh(['event', 'participants', 'couponCode']);
        $this->assertSame('180.00', $registration->amount);

        app(ChipGateway::class)->createCheckout($registration, $this->urls());

        $payload = $this->sentPayload();

        // CHIP totals the lines, so the lines ARE the amount. RM180.00, not RM240.00.
        $this->assertSame(18000, $this->lineTotalCents($payload));
        $this->assertSame($registration->reference, $payload['reference']);

        // One consolidated line naming the code, because a negative discount line is
        // not something CHIP is documented to accept.
        $this->assertCount(1, $payload['purchase']['products']);
        $this->assertStringContainsString(
            $registration->couponCode->codeLabel(),
            $payload['purchase']['products'][0]['name'],
        );
    }

    public function test_an_undiscounted_registration_checkout_is_itemised_exactly_as_before(): void
    {
        $this->fakeChip();

        $event = $this->event(['fee' => 200]);
        $registration = $this->registration($event, ['addons_total' => 0]);

        app(ChipGateway::class)->createCheckout($registration->fresh(['event', 'participants']), $this->urls());

        $payload = $this->sentPayload();

        $this->assertSame(20000, $this->lineTotalCents($payload));
        $this->assertSame($event->title, $payload['purchase']['products'][0]['name']);
    }

    public function test_a_fully_discounted_registration_is_never_sent_to_the_gateway(): void
    {
        $this->fakeChip();

        $registration = $this->registration($this->event(['fee' => 100]));

        app(RegistrationCouponWriter::class)->apply($registration, $this->percentageCoupon(100));

        $registration = $registration->fresh(['event', 'participants', 'couponCode']);

        $this->expectException(\App\Services\Payment\PaymentGatewayException::class);

        app(ChipGateway::class)->createCheckout($registration, $this->urls());
    }

    /* ---------------------------------------------------------------------
     | The balance link, which is the other registration path
     * ------------------------------------------------------------------ */

    public function test_the_balance_charge_reads_the_discounted_outstanding(): void
    {
        $event = $this->event(['fee' => 200]);
        $registration = $this->registration($event);

        app(RegistrationCouponWriter::class)->apply($registration, $this->fixedCoupon(50));

        $registration = $registration->fresh(['event', 'participants']);
        $registration->forceFill(['amount_paid' => 50])->save();

        // RM200 less RM50 off, less RM50 received: RM100.
        $charge = app(RegistrationBalanceCharge::class)->build($registration->fresh(['event', 'participants']));

        $this->assertSame(10000, $charge->amountCents);
    }

    /* ---------------------------------------------------------------------
     | A shop order
     * ------------------------------------------------------------------ */

    public function test_a_shop_charge_quotes_the_discounted_goods_plus_the_full_postage(): void
    {
        Setting::write('integration.shipping.flat_rate_west', '10', 'integration.shipping');
        Setting::write('integration.shipping.flat_rate_east', '10', 'integration.shipping');

        $product = $this->product(['price' => 50]);

        session(['shop.cart' => [
            Cart::key($product->id, null) => [
                'product_id' => $product->id,
                'variant_id' => null,
                'quantity' => 2,
            ],
        ]]);

        $coupon = $this->fixedCoupon(20, ['kind' => \App\Models\Coupon::KIND_SHOP, 'quantity' => 1]);
        $outcome = app(\App\Services\Coupon\CouponRedeemer::class)->claim($coupon, 100.0);

        $order = app(ShopOrderWriter::class)->place(
            [
                'customer_name' => 'Aminah Yusof',
                'customer_email' => 'buyer-' . uniqid() . '@example.com',
                'customer_phone' => '0123456789',
                'address_line_1' => '1 Jalan Satu',
                'postcode' => '40000',
                'city' => 'Shah Alam',
                'state' => 'Selangor',
            ],
            ShopOrder::METHOD_GATEWAY,
            null,
            $outcome->discount,
            $outcome->code->id,
        );

        $this->assertSame('90.00', $order->grand_total);

        $charge = app(ShopOrderChargeBuilder::class)->build($order->fresh(['items', 'couponCode']));

        // RM80 of goods plus RM10 postage. The builder's own cent guard would have
        // thrown before this if the lines disagreed with the grand total.
        $this->assertSame(9000, $charge->amountCents);
        $this->assertCount(2, $charge->products);
        $this->assertSame(8000, $charge->products[0]['price']);
        $this->assertSame(1000, $charge->products[1]['price']);
        $this->assertStringContainsString($outcome->code->codeLabel(), $charge->products[0]['name']);
    }

    public function test_an_undiscounted_shop_charge_is_itemised_exactly_as_before(): void
    {
        $product = $this->product(['price' => 50]);

        session(['shop.cart' => [
            Cart::key($product->id, null) => [
                'product_id' => $product->id,
                'variant_id' => null,
                'quantity' => 2,
            ],
        ]]);

        $order = app(ShopOrderWriter::class)->place(
            [
                'customer_name' => 'Aminah Yusof',
                'customer_email' => 'buyer-' . uniqid() . '@example.com',
                'customer_phone' => '0123456789',
                'address_line_1' => '1 Jalan Satu',
                'postcode' => '40000',
                'city' => 'Shah Alam',
                'state' => 'Selangor',
            ],
            ShopOrder::METHOD_GATEWAY,
        );

        $charge = app(ShopOrderChargeBuilder::class)->build($order->fresh('items'));

        $this->assertSame(10000, $charge->amountCents);
        $this->assertCount(1, $charge->products);
        $this->assertSame(5000, $charge->products[0]['price']);
        $this->assertSame('2', $charge->products[0]['quantity']);
    }
}
