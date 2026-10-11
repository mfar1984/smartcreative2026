<?php

namespace Tests\Feature\Shop;

use App\Models\ShopOrder;
use App\Models\ShopProduct;
use App\Models\ShopProductAddon;
use App\Services\ShopOrderWriter;
use App\Support\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

/**
 * Add-on stock, at the same three points the sizes have it: refused on the way in,
 * clamped on the way out, and taken once when the money lands.
 *
 * The last test is the important one. The shop has no stock restore path anywhere —
 * the only writer of a variant's stock_taken is the increment on the move into paid,
 * and a refund, a cancellation or deleting an order all give nothing back. An add-on
 * that quietly returned stock while the size it was bought with did not would be a
 * new asymmetry on a live store, so the two are asserted in lockstep.
 */
class ProductAddonStockTest extends ShopAddonTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->gatewayShopSettings();
    }

    /**
     * The fixture with the compulsory Engraving taken off, so a basket can carry the
     * laser hole alone. With it still there a line that named no engraving would be
     * refused, which is a different test's subject.
     */
    private function laserOnlyProduct(): ShopProduct
    {
        $product = $this->addonProduct();

        ShopProductAddon::query()
            ->whereKey($product->addons->firstWhere('name', 'Engraving')->id)
            ->delete();

        return $product->fresh(['variants', 'addons.variants']);
    }

    /**
     * An order for two medals in size S with "Front" engraving, still unpaid.
     */
    private function orderWithEngraving(ShopProduct $product): ShopOrder
    {
        $engraving = $product->addons->firstWhere('name', 'Engraving');

        $this->fakeChipPurchase();

        $this->withSession($this->basketWithAddons(
            $product,
            $product->variants->firstWhere('label', 'S')->id,
            [$engraving->id => $engraving->variants->firstWhere('label', 'Front')->id],
            2,
        ))->post(route('checkout.place'), $this->checkoutFields(ShopOrder::METHOD_GATEWAY));

        return ShopOrder::query()->latest('id')->firstOrFail();
    }

    /* ---------------------------------------------------------------------
     | On the way in
     * ------------------------------------------------------------------ */

    public function test_a_sold_out_option_cannot_be_added(): void
    {
        $product = $this->addonProduct();
        $variant = $product->variants->firstWhere('label', 'S');
        $engraving = $product->addons->firstWhere('name', 'Engraving');
        $front = $engraving->variants->firstWhere('label', 'Front');

        // Stock of 3 with 3 gone: nothing left, which is not the same as unlimited.
        $front->forceFill(['stock' => 3, 'stock_taken' => 3])->save();

        $this->post(route('cart.store'), [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'addons' => [$engraving->id => $front->id],
        ])->assertSessionHasErrors('cart');

        $this->assertSame(Cart::UNAVAILABLE, session('errors')->first('cart'));
        $this->assertTrue(Cart::isEmpty());

        // The page says so too, rather than offering a radio that cannot be used.
        $this->get(route('shop.product', $product->slug))->assertSee('Sold out');
    }

    public function test_adding_more_than_the_addon_has_left_is_held_to_the_stock(): void
    {
        $product = $this->addonProduct();
        $variant = $product->variants->firstWhere('label', 'S');
        $laser = $product->addons->firstWhere('name', 'With Laser Hole');
        $engraving = $product->addons->firstWhere('name', 'Engraving');

        $laser->forceFill(['stock' => 3, 'stock_taken' => 0])->save();

        $this->post(route('cart.store'), [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 5,
            'addons' => [
                $laser->id => '1',
                $engraving->id => $engraving->variants->firstWhere('label', 'Front')->id,
            ],
        ])->assertRedirect(route('cart'));

        // The size has ten; the hole has three, and the lowest figure wins.
        $this->assertSame(3, collect(session('shop.cart'))->sole()['quantity']);
    }

    /* ---------------------------------------------------------------------
     | On the way out
     * ------------------------------------------------------------------ */

    public function test_a_basket_above_the_addon_stock_is_capped_and_reported(): void
    {
        $product = $this->laserOnlyProduct();
        $laser = $product->addons->firstWhere('name', 'With Laser Hole');
        $laser->forceFill(['stock' => 3, 'stock_taken' => 0])->save();

        $basket = $this->basketWithAddons(
            $product,
            $product->variants->firstWhere('label', 'S')->id,
            [$laser->id => null],
            5,
        );

        // Stored at five, as a basket would be if the stock fell afterwards.
        $this->withSession($basket)
            ->get(route('cart'))
            ->assertOk()
            ->assertSee('Some quantities were reduced');

        $this->session($basket);

        // Read once: lines() writes the trimmed quantity back, so a second read is
        // no longer over the stock and reports nothing.
        $capped = Cart::cappedLines();

        $this->assertCount(1, $capped);
        $this->assertSame(3, $capped->first()['quantity']);
        $this->assertSame(3, $capped->first()['capped_to']);
    }

    public function test_max_quantity_caps_the_line(): void
    {
        $product = $this->laserOnlyProduct();
        $laser = $product->addons->firstWhere('name', 'With Laser Hole');

        // No stock limit at all, only a per order cap.
        $laser->forceFill(['stock' => null, 'max_quantity' => 2])->save();

        $this->session($this->basketWithAddons(
            $product,
            $product->variants->firstWhere('label', 'S')->id,
            [$laser->id => null],
            5,
        ));

        $line = Cart::lines()->sole();

        $this->assertSame(2, $line['quantity']);
        $this->assertSame(2, $line['capped_to']);
    }

    public function test_an_addon_with_no_limits_does_not_cap_anything(): void
    {
        $product = $this->laserOnlyProduct();
        $laser = $product->addons->firstWhere('name', 'With Laser Hole');

        $this->session($this->basketWithAddons(
            $product,
            $product->variants->firstWhere('label', 'S')->id,
            [$laser->id => null],
            4,
        ));

        $line = Cart::lines()->sole();

        $this->assertSame(4, $line['quantity']);
        $this->assertNull($line['capped_to']);
    }

    /* ---------------------------------------------------------------------
     | When the money lands
     * ------------------------------------------------------------------ */

    public function test_stock_is_taken_once_on_the_move_into_paid(): void
    {
        $product = $this->addonProduct();
        $order = $this->orderWithEngraving($product);

        $engraving = $product->addons->firstWhere('name', 'Engraving');
        $front = $engraving->variants->firstWhere('label', 'Front');
        $size = $product->variants->firstWhere('label', 'S');

        // Nothing moves while the order is only placed.
        $this->assertSame(0, $front->refresh()->stock_taken);
        $this->assertSame(0, $size->refresh()->stock_taken);

        app(ShopOrderWriter::class)->moveTo($order, ShopOrder::STATUS_PAID);

        // Two medals, two engravings: one add-on per unit bought.
        $this->assertSame(2, $front->refresh()->stock_taken);
        $this->assertSame(2, $size->refresh()->stock_taken);

        // The add-on's own count is not touched, because the option's governs.
        $this->assertSame(0, $engraving->refresh()->stock_taken);

        // A second move cannot double it.
        app(ShopOrderWriter::class)->moveTo($order->fresh(), ShopOrder::STATUS_PAID);

        $this->assertSame(2, $front->refresh()->stock_taken);
        $this->assertSame(2, $size->refresh()->stock_taken);
    }

    public function test_an_addon_with_no_options_takes_its_own_stock(): void
    {
        $product = $this->laserOnlyProduct();
        $laser = $product->addons->firstWhere('name', 'With Laser Hole');
        $laser->forceFill(['stock' => 10])->save();

        $this->fakeChipPurchase();

        $this->withSession($this->basketWithAddons(
            $product,
            $product->variants->firstWhere('label', 'S')->id,
            [$laser->id => null],
            3,
        ))->post(route('checkout.place'), $this->checkoutFields(ShopOrder::METHOD_GATEWAY));

        app(ShopOrderWriter::class)->moveTo(
            ShopOrder::query()->latest('id')->firstOrFail(),
            ShopOrder::STATUS_PAID,
        );

        $this->assertSame(3, $laser->refresh()->stock_taken);
    }

    /* ---------------------------------------------------------------------
     | Nothing comes back
     * ------------------------------------------------------------------ */

    public function test_cancelling_a_paid_order_returns_neither_the_size_nor_the_addon(): void
    {
        $product = $this->addonProduct();
        $order = $this->orderWithEngraving($product);

        $front = $product->addons->firstWhere('name', 'Engraving')->variants->firstWhere('label', 'Front');
        $size = $product->variants->firstWhere('label', 'S');

        $writer = app(ShopOrderWriter::class);
        $writer->moveTo($order, ShopOrder::STATUS_PAID);
        $writer->moveTo($order->fresh(), ShopOrder::STATUS_CANCELLED, 'Buyer changed their mind.');

        // In lockstep, and both unchanged: the shop has no restore path, so an
        // add-on must not invent one its own size does not have.
        $this->assertSame(2, $front->refresh()->stock_taken);
        $this->assertSame(2, $size->refresh()->stock_taken);
    }

    public function test_refunding_a_paid_order_returns_neither_the_size_nor_the_addon(): void
    {
        $product = $this->addonProduct();
        $order = $this->orderWithEngraving($product);

        $front = $product->addons->firstWhere('name', 'Engraving')->variants->firstWhere('label', 'Front');
        $size = $product->variants->firstWhere('label', 'S');

        $writer = app(ShopOrderWriter::class);
        $writer->moveTo($order, ShopOrder::STATUS_PAID);
        $writer->moveTo($order->fresh(), ShopOrder::STATUS_REFUNDED, 'Refunded by hand.');

        $this->assertSame(2, $front->refresh()->stock_taken);
        $this->assertSame(2, $size->refresh()->stock_taken);
    }
}
