<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\CouponCode;
use App\Models\Setting;
use App\Models\ShopOrder;
use App\Services\Coupon\CouponRedeemer;
use App\Services\ShopOrderWriter;
use App\Services\ShopPaymentLinkSender;
use App\Support\Cart;
use App\Support\ShippingSettings;
use App\Support\ShopSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * What a coupon does to a shop order's total.
 *
 * The one rule worth asserting hardest: the discount comes off the GOODS and never
 * off the postage. A courier charges what it charges whether or not the buyer had a
 * code, so letting a coupon eat the delivery charge would be paying to post somebody
 * a free parcel.
 */
class CouponShopOrderTest extends CouponTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ShopSettings::flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function buyer(): array
    {
        return [
            'customer_name' => 'Aminah Yusof',
            'customer_email' => 'buyer-' . uniqid() . '@example.com',
            'customer_phone' => '0123456789',
            'address_line_1' => '1 Jalan Satu',
            'postcode' => '40000',
            'city' => 'Shah Alam',
            'state' => 'Selangor',
        ];
    }

    /**
     * Put a product in the session basket, the way the shop pages do.
     */
    private function fillBasket(int $productId, int $quantity = 1): void
    {
        session(['shop.cart' => [
            Cart::key($productId, null) => [
                'product_id' => $productId,
                'variant_id' => null,
                'quantity' => $quantity,
            ],
        ]]);
    }

    /* ---------------------------------------------------------------------
     | The total
     * ------------------------------------------------------------------ */

    public function test_a_discount_comes_off_the_goods_and_leaves_the_postage_alone(): void
    {
        $this->flatShipping(10);

        $product = $this->product(['price' => 50]);
        $this->fillBasket($product->id, 2);

        // RM100 of goods, RM10 postage, RM20 off.
        $order = app(ShopOrderWriter::class)->place(
            $this->buyer(),
            ShopOrder::METHOD_GATEWAY,
            null,
            20.0,
        );

        $this->assertSame('100.00', $order->items_total);
        $this->assertSame('20.00', $order->discount_total);

        // Untouched. This is the assertion the whole rule exists for.
        $this->assertSame('10.00', $order->shipping_total);

        $this->assertSame('90.00', $order->grand_total);
        $this->assertTrue($order->hasDiscount());
        $this->assertSame(80.0, $order->discountedItemsTotal());
    }

    public function test_a_discount_larger_than_the_goods_is_capped_and_never_eats_the_postage(): void
    {
        $this->flatShipping(10);

        $product = $this->product(['price' => 30]);
        $this->fillBasket($product->id);

        $order = app(ShopOrderWriter::class)->place(
            $this->buyer(),
            ShopOrder::METHOD_GATEWAY,
            null,
            500.0,
        );

        $this->assertSame('30.00', $order->items_total);
        $this->assertSame('30.00', $order->discount_total, 'Capped at the goods.');
        $this->assertSame('10.00', $order->shipping_total);

        // The postage still has to be collected, so the total is the postage alone
        // rather than zero and never negative.
        $this->assertSame('10.00', $order->grand_total);
    }

    public function test_a_full_discount_on_a_collected_order_leaves_nothing_to_pay(): void
    {
        // Collected at the counter, so no postage is quoted at all.
        $product = $this->product([
            'price' => 40,
            'fulfilment' => \App\Models\ShopProduct::FULFILMENT_OFFLINE,
            'collection_location' => 'Dewan Serbaguna',
            'collection_at' => now()->addWeek()->setTime(10, 0),
        ]);

        $this->fillBasket($product->id);

        $order = app(ShopOrderWriter::class)->place(
            $this->buyer(),
            ShopOrder::METHOD_GATEWAY,
            null,
            40.0,
        );

        $this->assertSame('0.00', $order->grand_total);

        // The zero-charge path already existed and is reused rather than duplicated.
        $this->assertSame(
            ShopPaymentLinkSender::SKIP_NOTHING_TO_PAY,
            app(ShopPaymentLinkSender::class)->skipReason($order->fresh()),
        );

        $this->assertFalse($order->awaitsGatewayPayment());
    }

    public function test_the_postage_is_quoted_on_the_full_goods_total_not_the_discounted_one(): void
    {
        // Free delivery over RM100, flat RM10 below it.
        Setting::write('integration.shipping.flat_rate_west', '10', 'integration.shipping');
        Setting::write('integration.shipping.flat_rate_east', '10', 'integration.shipping');
        Setting::write('integration.shipping.free_shipping_threshold', '100', 'integration.shipping');

        $this->assertSame(100.0, ShippingSettings::freeShippingThreshold());

        $product = $this->product(['price' => 60]);
        $this->fillBasket($product->id, 2);

        // RM120 of goods earns free delivery. A RM50 coupon must not take it away by
        // dropping the basket under the threshold: the coupon is a reduction on the
        // goods, not a second promotion on the delivery.
        $order = app(ShopOrderWriter::class)->place(
            $this->buyer(),
            ShopOrder::METHOD_GATEWAY,
            null,
            50.0,
        );

        $this->assertSame('120.00', $order->items_total);
        $this->assertSame('0.00', $order->shipping_total);
        $this->assertSame('70.00', $order->grand_total);
    }

    /* ---------------------------------------------------------------------
     | The redemption record
     * ------------------------------------------------------------------ */

    public function test_a_claimed_code_is_linked_to_the_order_it_paid_for(): void
    {
        $this->flatShipping(10);

        $product = $this->product(['price' => 50]);
        $this->fillBasket($product->id);

        $coupon = $this->fixedCoupon(15, ['kind' => Coupon::KIND_SHOP, 'quantity' => 2]);
        $code = $coupon->name;

        $outcome = app(CouponRedeemer::class)->claimByCode($code, Coupon::KIND_SHOP, 50.0);

        $this->assertTrue($outcome->succeeded());
        $this->assertSame(15.0, $outcome->discount);

        $order = app(ShopOrderWriter::class)->place(
            $this->buyer(),
            ShopOrder::METHOD_GATEWAY,
            null,
            $outcome->discount,
            $outcome->code->id,
        );

        $this->assertSame('15.00', $order->discount_total);
        $this->assertSame('45.00', $order->grand_total);
        $this->assertSame($outcome->code->id, $order->coupon_code_id);

        $claimed = CouponCode::query()->whereKey($outcome->code->id)->sole();

        $this->assertSame($order->id, $claimed->shop_order_id);
        $this->assertSame($order->reference, $claimed->usedOnReference());
        $this->assertSame('Shop order', $claimed->usedOnLabel());
        $this->assertSame(1, $coupon->fresh()->remaining());
    }

    public function test_an_event_coupon_cannot_be_claimed_for_the_shop(): void
    {
        $coupon = $this->fixedCoupon(15, ['kind' => Coupon::KIND_EVENT, 'quantity' => 1]);
        $code = $coupon->name;

        $outcome = app(CouponRedeemer::class)->claimByCode($code, Coupon::KIND_SHOP, 50.0);

        $this->assertFalse($outcome->succeeded());
        $this->assertSame(1, $coupon->fresh()->remaining());
    }

    /* ---------------------------------------------------------------------
     | Nothing changes for an order with no coupon
     * ------------------------------------------------------------------ */

    public function test_an_order_with_no_coupon_is_exactly_as_it_was(): void
    {
        $this->flatShipping(10);

        $product = $this->product(['price' => 50]);
        $this->fillBasket($product->id, 2);

        $order = app(ShopOrderWriter::class)->place($this->buyer(), ShopOrder::METHOD_GATEWAY);

        $this->assertSame('100.00', $order->items_total);
        $this->assertSame('0.00', $order->discount_total);
        $this->assertSame('10.00', $order->shipping_total);
        $this->assertSame('110.00', $order->grand_total);
        $this->assertNull($order->coupon_code_id);
        $this->assertFalse($order->hasDiscount());
        $this->assertTrue($order->awaitsGatewayPayment());
        $this->assertNull(app(ShopPaymentLinkSender::class)->skipReason($order->fresh()));
    }
}
