<?php

namespace Tests\Feature\Shop;

use App\Mail\ShopOrderBankTransferInstructions;
use App\Mail\ShopOrderCollectionReady;
use App\Models\Setting;
use App\Models\ShopOrder;
use App\Models\ShopProduct;
use App\Models\ShopProductAddon;
use App\Services\Payment\ShopOrderChargeBuilder;
use App\Services\ShopOrderWriter;
use App\Support\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Buying a product with extras.
 *
 * Two halves. One is the money: the add-on price reaches the line, the order, the
 * snapshot row and the gateway charge, to the cent. The other is the rule that is
 * only worth anything if it is enforced on the server — a compulsory add-on cannot
 * be dropped by a crafted POST, and a compulsory one with no options is applied
 * whether or not the payload mentions it.
 */
class ProductAddonPurchaseTest extends ShopAddonTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->gatewayShopSettings();

        Setting::write('integration.shipping.flat_rate_west', '8.00', 'integration.shipping');
    }

    /**
     * The fixture with the compulsory Engraving taken off, leaving only the optional
     * tick box. The shape most products will have.
     */
    private function optionalOnlyProduct(): ShopProduct
    {
        $product = $this->addonProduct();

        ShopProductAddon::query()
            ->whereKey($product->addons->firstWhere('name', 'Engraving')->id)
            ->delete();

        return $product->fresh(['variants', 'addons.variants']);
    }

    /* ---------------------------------------------------------------------
     | The page
     * ------------------------------------------------------------------ */

    public function test_the_product_page_offers_the_addons_below_the_sizes(): void
    {
        $product = $this->addonProduct();

        $response = $this->get(route('shop.product', $product->slug));

        $response->assertOk();
        $response->assertSee('Extras');
        $response->assertSee('With Laser Hole');
        $response->assertSee('+RM 5.00');
        $response->assertSee('Required');
        $response->assertSee('Front &amp; Back', false);

        // The size fieldset is untouched and still above the extras.
        $response->assertSee('Choose a size');
        $this->assertLessThan(
            strpos($response->getContent(), 'With Laser Hole'),
            strpos($response->getContent(), 'Choose a size'),
        );
    }

    /* ---------------------------------------------------------------------
     | The basket
     * ------------------------------------------------------------------ */

    public function test_an_optional_addon_left_out_gives_exactly_the_old_total(): void
    {
        $product = $this->optionalOnlyProduct();
        $variant = $product->variants->firstWhere('label', 'S');

        $this->post(route('cart.store'), [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ])->assertRedirect(route('cart'));

        $this->assertSame(
            ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 2],
            session('shop.cart')[$product->id . ':' . $variant->id],
        );

        $line = Cart::lines()->sole();

        $this->assertSame([], $line['addons']);
        $this->assertSame(0.0, $line['addons_unit_price']);
        $this->assertSame(160.0, $line['line_total']);
    }

    public function test_a_chosen_addon_reaches_the_line_the_order_and_the_snapshot(): void
    {
        $product = $this->optionalOnlyProduct();
        $variant = $product->variants->firstWhere('label', 'S');
        $laser = $product->addons->firstWhere('name', 'With Laser Hole');

        $this->post(route('cart.store'), [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
            'addons' => [$laser->id => '1'],
        ])->assertRedirect(route('cart'));

        $line = Cart::lines()->sole();

        // 80.00 for the medal, 5.00 for the hole, twice.
        $this->assertSame(80.0, $line['unit_price']);
        $this->assertSame(5.0, $line['addons_unit_price']);
        $this->assertSame(170.0, $line['line_total']);
        $this->assertSame('With Laser Hole', $line['addons'][0]['addon']->name);
        $this->assertNull($line['addons'][0]['variant']);

        $this->fakeChipPurchase();
        $this->post(route('checkout.place'), $this->checkoutFields(ShopOrder::METHOD_GATEWAY));

        $order = ShopOrder::query()->latest('id')->firstOrFail();

        $this->assertSame('170.00', $order->items_total);
        $this->assertSame('8.00', $order->shipping_total);
        $this->assertSame('178.00', $order->grand_total);

        $item = $order->items()->sole();

        // The product's own figure is untouched: the extra rides on the line total.
        $this->assertSame('80.00', $item->unit_price);
        $this->assertSame('170.00', $item->line_total);
        $this->assertSame(160.0, $item->baseLineTotal());

        $addon = $item->addons()->sole();

        $this->assertSame($laser->id, $addon->shop_product_addon_id);
        $this->assertNull($addon->shop_product_addon_variant_id);
        $this->assertSame('With Laser Hole', $addon->name);
        $this->assertNull($addon->variant_label);
        $this->assertSame('5.00', $addon->unit_price);
        $this->assertSame(2, $addon->quantity);
        $this->assertSame('10.00', $addon->line_total);
    }

    public function test_an_option_carries_its_own_price_and_label_into_the_snapshot(): void
    {
        $product = $this->addonProduct();
        $variant = $product->variants->firstWhere('label', 'M');
        $engraving = $product->addons->firstWhere('name', 'Engraving');
        $front = $engraving->variants->firstWhere('label', 'Front');

        $this->post(route('cart.store'), [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'addons' => [$engraving->id => $front->id],
        ])->assertRedirect(route('cart'));

        // A null option price means "charge the add-on's price", so Front is 10.00.
        $this->assertSame(90.0, Cart::lines()->sole()['line_total']);

        $this->fakeChipPurchase();
        $this->post(route('checkout.place'), $this->checkoutFields(ShopOrder::METHOD_GATEWAY));

        $addon = ShopOrder::query()->latest('id')->firstOrFail()->items()->sole()->addons()->sole();

        $this->assertSame('Engraving', $addon->name);
        $this->assertSame('Front', $addon->variant_label);
        $this->assertSame('10.00', $addon->unit_price);
        $this->assertSame('10.00', $addon->line_total);
        $this->assertSame('Engraving (Front)', $addon->describe());
    }

    public function test_the_same_product_with_different_extras_is_two_lines(): void
    {
        $product = $this->addonProduct();
        $variant = $product->variants->firstWhere('label', 'S');
        $engraving = $product->addons->firstWhere('name', 'Engraving');

        foreach ($engraving->variants as $option) {
            $this->post(route('cart.store'), [
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'addons' => [$engraving->id => $option->id],
            ])->assertRedirect(route('cart'));
        }

        $this->assertCount(2, session('shop.cart'));
        $this->assertCount(2, Cart::lines());
    }

    /* ---------------------------------------------------------------------
     | Compulsory, decided by the server
     * ------------------------------------------------------------------ */

    public function test_a_compulsory_addon_with_options_cannot_be_skipped(): void
    {
        $product = $this->addonProduct();
        $variant = $product->variants->firstWhere('label', 'S');

        $response = $this->post(route('cart.store'), [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
        ]);

        $response->assertSessionHasErrors('cart');
        $this->assertStringContainsString(
            'Choose an option for "Engraving"',
            session('errors')->first('cart'),
        );

        $this->assertTrue(Cart::isEmpty());
    }

    public function test_a_crafted_payload_that_omits_the_compulsory_field_is_refused(): void
    {
        $product = $this->addonProduct();
        $variant = $product->variants->firstWhere('label', 'S');
        $laser = $product->addons->firstWhere('name', 'With Laser Hole');
        $engraving = $product->addons->firstWhere('name', 'Engraving');

        // Everything the form would post except the engraving radio, plus a blank
        // value for it, which is the other shape a tampered form takes.
        foreach ([[$laser->id => '1'], [$laser->id => '1', $engraving->id => '']] as $addons) {
            $this->flushSession();

            $this->post(route('cart.store'), [
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'addons' => $addons,
            ])->assertSessionHasErrors('cart');

            $this->assertTrue(Cart::isEmpty());
        }
    }

    public function test_a_compulsory_addon_with_no_options_is_added_even_when_the_payload_omits_it(): void
    {
        $product = $this->addonProduct();
        $variant = $product->variants->firstWhere('label', 'S');

        // The laser hole becomes compulsory, and the engraving goes, so nothing on
        // this product has options to choose.
        $laser = $product->addons->firstWhere('name', 'With Laser Hole');
        $laser->forceFill(['is_required' => true])->save();
        ShopProductAddon::query()
            ->whereKey($product->addons->firstWhere('name', 'Engraving')->id)
            ->delete();

        $this->post(route('cart.store'), [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
        ])->assertRedirect(route('cart'));

        $stored = collect(session('shop.cart'))->sole();

        $this->assertSame([$laser->id => null], $stored['addons']);
        $this->assertSame(85.0, Cart::lines()->sole()['line_total']);

        // And it is charged, not merely stored.
        $this->fakeChipPurchase();
        $this->post(route('checkout.place'), $this->checkoutFields(ShopOrder::METHOD_GATEWAY));

        $this->assertSame('85.00', ShopOrder::query()->latest('id')->firstOrFail()->items_total);
    }

    public function test_an_option_belonging_to_another_product_is_refused(): void
    {
        $product = $this->addonProduct();
        $other = $this->addonProduct();

        $variant = $product->variants->firstWhere('label', 'S');
        $engraving = $product->addons->firstWhere('name', 'Engraving');
        $strangerOption = $other->addons->firstWhere('name', 'Engraving')->variants->first();

        $this->post(route('cart.store'), [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'addons' => [$engraving->id => $strangerOption->id],
        ])->assertSessionHasErrors('cart');

        $this->assertSame(Cart::UNAVAILABLE, session('errors')->first('cart'));
        $this->assertTrue(Cart::isEmpty());
        $this->assertSame(0, DB::table('shop_order_item_addons')->count());
    }

    public function test_an_addon_belonging_to_another_product_is_ignored_rather_than_charged(): void
    {
        $product = $this->optionalOnlyProduct();
        $other = $this->optionalOnlyProduct();

        $variant = $product->variants->firstWhere('label', 'S');

        $this->post(route('cart.store'), [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'addons' => [$other->addons->firstWhere('name', 'With Laser Hole')->id => '1'],
        ])->assertRedirect(route('cart'));

        // Priced at the medal alone: the add-on is not this product's to sell.
        $this->assertSame(80.0, Cart::lines()->sole()['line_total']);
        $this->assertSame([], Cart::lines()->sole()['addons']);
    }

    /* ---------------------------------------------------------------------
     | The screens and the emails that carry the figures
     * ------------------------------------------------------------------ */

    public function test_the_checkout_summary_lists_the_chosen_extras(): void
    {
        $product = $this->addonProduct();
        $engraving = $product->addons->firstWhere('name', 'Engraving');

        $response = $this->withSession($this->basketWithAddons(
            $product,
            $product->variants->firstWhere('label', 'S')->id,
            [$engraving->id => $engraving->variants->firstWhere('label', 'Front')->id],
        ))->get(route('checkout'));

        $response->assertOk();
        $response->assertSee('Engraving');
        $response->assertSee('RM 10.00 each');
        $response->assertSee('RM 90.00');
    }

    public function test_both_order_emails_name_the_extras(): void
    {
        $product = $this->addonProduct();
        $engraving = $product->addons->firstWhere('name', 'Engraving');

        $this->fakeChipPurchase();

        $this->withSession($this->basketWithAddons(
            $product,
            $product->variants->firstWhere('label', 'S')->id,
            [$engraving->id => $engraving->variants->firstWhere('label', 'Front')->id],
        ))->post(route('checkout.place'), $this->checkoutFields(ShopOrder::METHOD_GATEWAY));

        $order = ShopOrder::query()->latest('id')->firstOrFail();

        // Rendered rather than only asserted as sent, because Mail::fake() never
        // compiles the view and a broken email is only found by sending one.
        $bankTransfer = (new ShopOrderBankTransferInstructions($order, null, null, 'https://example.com/receipt'))->render();
        $collection = (new ShopOrderCollectionReady($order))->render();

        $this->assertStringContainsString('Engraving (Front)', $bankTransfer);
        $this->assertStringContainsString('Engraving (Front)', $collection);
    }

    /* ---------------------------------------------------------------------
     | The gateway
     * ------------------------------------------------------------------ */

    public function test_the_gateway_charge_lines_add_up_with_addons(): void
    {
        $product = $this->addonProduct();
        $variant = $product->variants->firstWhere('label', 'L');
        $laser = $product->addons->firstWhere('name', 'With Laser Hole');
        $engraving = $product->addons->firstWhere('name', 'Engraving');
        $both = $engraving->variants->firstWhere('label', 'Front & Back');

        $this->post(route('cart.store'), [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
            'addons' => [$laser->id => '1', $engraving->id => $both->id],
        ])->assertRedirect(route('cart'));

        $this->fakeChipPurchase();
        $this->post(route('checkout.place'), $this->checkoutFields(ShopOrder::METHOD_GATEWAY));

        $order = ShopOrder::query()->latest('id')->firstOrFail();

        // (80.00 + 5.00 + 4.00) x 2 = 178.00 goods, plus 8.00 postage.
        $this->assertSame('178.00', $order->items_total);
        $this->assertSame('186.00', $order->grand_total);

        $charge = app(ShopOrderChargeBuilder::class)->build($order);

        // The medal, the hole, the engraving, the postage.
        $this->assertCount(4, $charge->products);

        $lines = array_sum(array_map(
            fn (array $line) => $line['price'] * (int) $line['quantity'],
            $charge->products,
        ));

        $this->assertSame(18600, $charge->amountCents);
        $this->assertSame(18600, $lines);
    }

    /**
     * A coupon and an extra on the same order.
     *
     * The crossing worth asserting: the charge builder itemises the extras in its
     * normal branch, but a discounted order takes the other branch and collapses
     * the goods into ONE line priced at what is owed. The add-on money is already
     * inside items_total, so that line has to carry it too — if the two branches
     * ever disagreed about where the extras live, the cent-level guard would refuse
     * the payment and a buyer with a code could not pay at all.
     */
    public function test_a_discounted_order_carrying_addons_still_balances(): void
    {
        $product = $this->addonProduct();
        $variant = $product->variants->firstWhere('label', 'L');
        $laser = $product->addons->firstWhere('name', 'With Laser Hole');
        $engraving = $product->addons->firstWhere('name', 'Engraving');
        $both = $engraving->variants->firstWhere('label', 'Front & Back');

        $this->post(route('cart.store'), [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
            'addons' => [$laser->id => '1', $engraving->id => $both->id],
        ])->assertRedirect(route('cart'));

        // RM20 off the goods, the way CouponRedeemer hands the figure over.
        $order = app(ShopOrderWriter::class)->place(
            $this->checkoutFields(ShopOrder::METHOD_GATEWAY),
            ShopOrder::METHOD_GATEWAY,
            null,
            20.0,
        );

        // (80.00 + 5.00 + 4.00) x 2 = 178.00 goods, 20.00 off, 8.00 postage.
        $this->assertSame('178.00', $order->items_total);
        $this->assertSame('20.00', $order->discount_total);
        $this->assertSame('8.00', $order->shipping_total);
        $this->assertSame('166.00', $order->grand_total);

        $charge = app(ShopOrderChargeBuilder::class)->build($order);

        // One goods line and the postage: the extras are inside the goods figure,
        // not itemised, because that is what this branch does with a discount.
        $this->assertCount(2, $charge->products);
        $this->assertSame(15800, $charge->products[0]['price']);

        $lines = array_sum(array_map(
            fn (array $line) => $line['price'] * (int) $line['quantity'],
            $charge->products,
        ));

        $this->assertSame(16600, $charge->amountCents);
        $this->assertSame(16600, $lines);

        // The snapshot rows are still written on a discounted order.
        $this->assertSame(2, $order->items()->sole()->addons()->count());
    }

    /* ---------------------------------------------------------------------
     | The session shape
     * ------------------------------------------------------------------ */

    /**
     * The fixture helper and the real add path agree on the line.
     *
     * basketWithAddons() builds a session by hand so a test does not have to post
     * through the storefront, and the order writer reads that session. If the
     * helper keyed or shaped a line differently from Cart::add(), every test built
     * on it would be asserting against a basket the shop never writes.
     */
    public function test_the_fixture_basket_matches_what_the_cart_writes(): void
    {
        $product = $this->addonProduct();
        $variant = $product->variants->firstWhere('label', 'M');
        $laser = $product->addons->firstWhere('name', 'With Laser Hole');
        $engraving = $product->addons->firstWhere('name', 'Engraving');
        $front = $engraving->variants->firstWhere('label', 'Front');

        $this->post(route('cart.store'), [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 3,
            'addons' => [$laser->id => '1', $engraving->id => $front->id],
        ])->assertRedirect(route('cart'));

        $this->assertSame(
            $this->basketWithAddons(
                $product,
                $variant->id,
                [$laser->id => null, $engraving->id => $front->id],
                3,
            )['shop.cart'],
            session('shop.cart'),
        );
    }

    public function test_a_free_addon_sends_no_gateway_line(): void
    {
        $product = $this->optionalOnlyProduct();
        $variant = $product->variants->firstWhere('label', 'S');
        $laser = $product->addons->firstWhere('name', 'With Laser Hole');
        $laser->forceFill(['price' => 0])->save();

        $this->post(route('cart.store'), [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'addons' => [$laser->id => '1'],
        ])->assertRedirect(route('cart'));

        $this->fakeChipPurchase();
        $this->post(route('checkout.place'), $this->checkoutFields(ShopOrder::METHOD_GATEWAY));

        $order = ShopOrder::query()->latest('id')->firstOrFail();
        $charge = app(ShopOrderChargeBuilder::class)->build($order);

        // The row is still written, because it is what was bought; it just is not
        // sent to CHIP as a product costing nothing.
        $this->assertSame('0.00', $order->items()->sole()->addons()->sole()->unit_price);
        $this->assertCount(2, $charge->products);
        $this->assertSame(8800, $charge->amountCents);
    }
}
