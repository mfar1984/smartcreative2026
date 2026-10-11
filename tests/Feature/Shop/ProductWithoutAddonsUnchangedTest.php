<?php

namespace Tests\Feature\Shop;

use App\Models\Setting;
use App\Models\ShopOrder;
use App\Models\ShopProduct;
use App\Models\ShopProductAddon;
use App\Services\Payment\ShopOrderChargeBuilder;
use App\Support\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * The regression lock for a product that has no add-ons.
 *
 * Every live product in the shop is one of these, so this file is the one that says
 * the feature cost nothing. The figures below are written out by hand rather than
 * recomputed from Cart or ShopOrderWriter: a total asserted against the code that
 * produced it would agree with itself however wrong both were.
 */
class ProductWithoutAddonsUnchangedTest extends ShopAddonTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->gatewayShopSettings();

        // A real postage figure, so the lock covers the one money column add-ons
        // must never reach. Flat rate by state on the goods; weight is not consulted.
        Setting::write('integration.shipping.flat_rate_west', '8.00', 'integration.shipping');
    }

    /**
     * The add-on fixture with its add-ons taken away, so the product is otherwise
     * identical to the one the add-on tests use.
     */
    private function plainProduct(): ShopProduct
    {
        $product = $this->addonProduct();

        ShopProductAddon::query()->where('shop_product_id', $product->id)->delete();

        return $product->fresh(['variants', 'addons.variants']);
    }

    /* ---------------------------------------------------------------------
     | The page and the session
     * ------------------------------------------------------------------ */

    public function test_the_product_page_carries_no_extras_block(): void
    {
        $product = $this->plainProduct();

        $response = $this->get(route('shop.product', $product->slug));

        $response->assertOk();
        $response->assertSee('Add to Basket');
        $response->assertDontSee('Extras');
        $response->assertDontSee('name="addons', false);
    }

    public function test_the_cart_key_is_exactly_what_it_has_always_been(): void
    {
        $product = $this->plainProduct();
        $variant = $product->variants->firstWhere('label', 'S');

        $this->assertSame($product->id . ':' . $variant->id, Cart::key($product->id, $variant->id));
        $this->assertSame($product->id . ':base', Cart::key($product->id, null));

        // And passing an empty selection to the new third argument changes nothing.
        $this->assertSame($product->id . ':' . $variant->id, Cart::key($product->id, $variant->id, []));
    }

    public function test_the_stored_session_line_has_no_addons_key(): void
    {
        $product = $this->plainProduct();
        $variant = $product->variants->firstWhere('label', 'S');

        $this->post(route('cart.store'), [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ])->assertRedirect(route('cart'));

        $stored = session('shop.cart');
        $key = $product->id . ':' . $variant->id;

        $this->assertSame([$key], array_keys($stored));
        $this->assertSame(
            ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 2],
            $stored[$key],
        );
    }

    /* ---------------------------------------------------------------------
     | The money
     * ------------------------------------------------------------------ */

    public function test_placing_an_order_writes_one_item_and_no_addon_rows(): void
    {
        $product = $this->plainProduct();
        $variant = $product->variants->firstWhere('label', 'S');

        $this->fakeChipPurchase();

        $this->withSession($this->basketWithAddons($product, $variant->id, [], 2))
            ->post(route('checkout.place'), $this->checkoutFields(ShopOrder::METHOD_GATEWAY));

        $order = ShopOrder::query()->latest('id')->firstOrFail();

        // 80.00 each, two of them, flat 8.00 postage to Selangor, no coupon.
        $this->assertSame('160.00', $order->items_total);
        $this->assertSame('0.00', $order->discount_total);
        $this->assertSame('8.00', $order->shipping_total);
        $this->assertSame('168.00', $order->grand_total);

        $item = $order->items()->sole();

        $this->assertSame('80.00', $item->unit_price);
        $this->assertSame(2, $item->quantity);
        $this->assertSame('160.00', $item->line_total);
        $this->assertSame(300, $item->weight_grams);

        $this->assertSame(0, DB::table('shop_order_item_addons')->count());
        $this->assertSame(0.0, $item->addonsTotal());
    }

    public function test_the_gateway_charge_has_the_same_lines_and_total_as_before(): void
    {
        $product = $this->plainProduct();
        $variant = $product->variants->firstWhere('label', 'S');

        $this->fakeChipPurchase();

        $this->withSession($this->basketWithAddons($product, $variant->id, [], 2))
            ->post(route('checkout.place'), $this->checkoutFields(ShopOrder::METHOD_GATEWAY));

        $charge = app(ShopOrderChargeBuilder::class)->build(ShopOrder::query()->latest('id')->firstOrFail());

        // One goods line and one postage line, and nothing else.
        $this->assertCount(2, $charge->products);
        $this->assertSame(16800, $charge->amountCents);

        $lines = array_sum(array_map(
            fn (array $line) => $line['price'] * (int) $line['quantity'],
            $charge->products,
        ));

        $this->assertSame(16800, $lines);
    }
}
