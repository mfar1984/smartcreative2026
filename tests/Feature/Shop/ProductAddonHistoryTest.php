<?php

namespace Tests\Feature\Shop;

use App\Models\ShopOrder;
use App\Models\ShopOrderItemAddon;
use App\Models\ShopProduct;
use App\Models\User;
use App\Services\ShopOrderWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * An issued order outlives the catalogue it was bought from.
 *
 * The add-on, its option and the whole product are deleted one after another, and
 * the order still says what was bought and what was charged. That is what the
 * snapshot columns and the nullable foreign keys are for: an invoice cannot be
 * rewritten by somebody tidying up the shop.
 */
class ProductAddonHistoryTest extends ShopAddonTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->gatewayShopSettings();
    }

    /** A paid order for two medals in S with "Front" engraving. */
    private function paidOrder(ShopProduct $product): ShopOrder
    {
        $engraving = $product->addons->firstWhere('name', 'Engraving');

        $this->fakeChipPurchase();

        $this->withSession($this->basketWithAddons(
            $product,
            $product->variants->firstWhere('label', 'S')->id,
            [$engraving->id => $engraving->variants->firstWhere('label', 'Front')->id],
            2,
        ))->post(route('checkout.place'), $this->checkoutFields(ShopOrder::METHOD_GATEWAY));

        $order = ShopOrder::query()->latest('id')->firstOrFail();

        app(ShopOrderWriter::class)->moveTo($order, ShopOrder::STATUS_PAID);

        return $order->fresh();
    }

    private function admin(): User
    {
        return $this->userWith(['admin.access', 'shop.orders.view']);
    }

    public function test_the_snapshot_survives_deleting_the_option_the_addon_and_the_product(): void
    {
        $product = $this->addonProduct();
        $order = $this->paidOrder($product);

        $engraving = $product->addons->firstWhere('name', 'Engraving');
        $front = $engraving->variants->firstWhere('label', 'Front');

        $row = ShopOrderItemAddon::query()->sole();

        $this->assertSame($engraving->id, $row->shop_product_addon_id);
        $this->assertSame($front->id, $row->shop_product_addon_variant_id);

        // The option goes.
        $front->delete();
        $row->refresh();

        $this->assertNull($row->shop_product_addon_variant_id);
        $this->assertSame('Front', $row->variant_label);
        $this->assertSame('Engraving (Front)', $row->describe());

        // Then the add-on.
        $engraving->delete();
        $row->refresh();

        $this->assertNull($row->shop_product_addon_id);
        $this->assertSame('Engraving', $row->name);
        $this->assertSame('10.00', $row->unit_price);
        $this->assertSame('20.00', $row->line_total);

        // Then the product itself.
        $product->delete();

        $this->assertSame(1, ShopOrderItemAddon::query()->count());
        $this->assertNull($order->items()->sole()->shop_product_id);
        $this->assertSame('Engraving (Front)', ShopOrderItemAddon::query()->sole()->describe());

        // And the order still adds up from its own rows.
        $this->assertSame('180.00', $order->items_total);
        $this->assertSame(180.0, $order->items()->sole()->baseLineTotal() + $order->items()->sole()->addonsTotal());
    }

    public function test_the_admin_order_page_still_shows_the_addon_after_the_catalogue_is_gone(): void
    {
        $product = $this->addonProduct();
        $order = $this->paidOrder($product);

        $product->delete();

        $response = $this->actingAs($this->admin())->get(route('admin.shop.orders.show', $order));

        $response->assertOk();
        $response->assertSee('Engraving (Front)');
        $response->assertSee('RM 20.00');
        $response->assertSee('No longer in the catalogue');
    }

    public function test_the_signed_confirmation_page_still_shows_the_addon(): void
    {
        $product = $this->addonProduct();
        $order = $this->paidOrder($product);

        $product->delete();

        $response = $this->get(URL::signedRoute('shop.order', ['reference' => $order->reference]));

        $response->assertOk();
        $response->assertSee('Engraving (Front)');
        $response->assertSee('RM 10.00 each');
    }

    public function test_the_csv_export_still_names_the_addon(): void
    {
        $product = $this->addonProduct();
        $order = $this->paidOrder($product);

        $product->delete();

        $response = $this->actingAs($this->admin())
            ->get(route('admin.shop.orders.export', ['tab' => ShopOrder::FULFILMENT_ONLINE]));

        $response->assertOk();

        $content = $response->streamedContent();

        $this->assertStringContainsString($order->reference, $content);
        // Item row: name and size in separate columns.
        $this->assertStringContainsString('Champion Medal', $content);
        $this->assertStringContainsString(',S,', $content);
        // Addon row: addon name in the Addon column.
        $this->assertStringContainsString('Engraving (Front)', $content);
    }
}
