<?php

namespace Tests\Feature\Shop;

use App\Models\ShopOrder;
use App\Models\ShopProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * How a product reaches the buyer never narrows how it may be paid for.
 *
 * The owner's words: "walaupun di set Collection Point adalah Offline. sepatutnya
 * pembayaran mestilah online sebab kita sudah set pembayaran ini melalui Card or online
 * banking". The two were already independent in the columns, the validation and the
 * form JS — what misled him was the copy, which read as though collection forced a
 * manual payment route.
 *
 * So this file locks the independence down, so a future reader cannot "fix" the
 * confusion by adding the coupling, and checks that the storefront sentence names the
 * routes that are genuinely payable rather than the ones merely ticked.
 */
class ProductPaymentFulfilmentIndependenceTest extends ShopPaymentTestCase
{
    use RefreshDatabase;

    public function test_an_offline_product_keeps_only_the_gateway_when_that_is_what_was_ticked(): void
    {
        $product = $this->offlineProduct([ShopOrder::METHOD_GATEWAY]);

        $this->assertSame(ShopProduct::FULFILMENT_OFFLINE, $product->fulfilment);
        $this->assertSame([ShopOrder::METHOD_GATEWAY], $product->fresh()->allowedPaymentMethods());
    }

    public function test_an_offline_product_may_hold_all_three_methods(): void
    {
        $product = $this->offlineProduct([
            ShopOrder::METHOD_GATEWAY,
            ShopOrder::METHOD_BANK_TRANSFER,
            ShopOrder::METHOD_COD,
        ]);

        $this->assertCount(3, $product->fresh()->allowedPaymentMethods());
    }

    public function test_switching_fulfilment_leaves_the_payment_methods_alone(): void
    {
        $product = $this->offlineProduct([ShopOrder::METHOD_GATEWAY, ShopOrder::METHOD_BANK_TRANSFER]);

        $product->forceFill(['fulfilment' => ShopProduct::FULFILMENT_ONLINE])->save();

        $this->assertSame(
            [ShopOrder::METHOD_GATEWAY, ShopOrder::METHOD_BANK_TRANSFER],
            $product->fresh()->allowedPaymentMethods(),
        );

        $product->forceFill(['fulfilment' => ShopProduct::FULFILMENT_OFFLINE])->save();

        $this->assertSame(
            [ShopOrder::METHOD_GATEWAY, ShopOrder::METHOD_BANK_TRANSFER],
            $product->fresh()->allowedPaymentMethods(),
        );
    }

    public function test_the_collection_panel_names_the_card_route_when_the_gateway_is_ready(): void
    {
        $this->gatewayShopSettings();

        $product = $this->offlineProduct([ShopOrder::METHOD_GATEWAY]);

        $response = $this->get(route('shop.product', ['slug' => $product->slug]));

        $response->assertOk();
        $response->assertSee('Collected in person, not posted');

        // The sentence the owner was looking for on the storefront, and it is true of
        // what the checkout will now do.
        $response->assertSee('Pay here by Card or online banking, then collect at our counter.');
    }

    public function test_the_collection_panel_names_only_payable_routes(): void
    {
        // CHIP on, bank transfer off. A product may tick bank transfer; the shop cannot
        // take it, so the panel must not advertise it.
        $this->gatewayShopSettings();

        $product = $this->offlineProduct([
            ShopOrder::METHOD_GATEWAY,
            ShopOrder::METHOD_BANK_TRANSFER,
            ShopOrder::METHOD_COD,
        ]);

        $response = $this->get(route('shop.product', ['slug' => $product->slug]));

        $response->assertOk();
        $response->assertSee('Card or online banking');
        $response->assertDontSee('Bank transfer');

        // Dropped even when it is payable: nothing is delivered, so the phrase is
        // incoherent on a collection panel.
        $response->assertDontSee('Cash on delivery');
    }

    public function test_the_collection_panel_falls_back_when_nothing_can_be_taken(): void
    {
        // No gateway, no bank transfer, no COD.
        \App\Models\Setting::write('shop.enabled', '1', 'shop');
        \App\Support\ShopSettings::flush();

        $product = $this->offlineProduct([ShopOrder::METHOD_GATEWAY]);

        $response = $this->get(route('shop.product', ['slug' => $product->slug]));

        $response->assertOk();
        $response->assertSee('Collect at our counter.');
        $response->assertDontSee('Pay here by');
    }

    public function test_add_to_basket_is_judged_per_product_not_shop_wide(): void
    {
        $this->gatewayShopSettings();

        $gatewayOnly = $this->offlineProduct([ShopOrder::METHOD_GATEWAY]);

        $this->get(route('shop.product', ['slug' => $gatewayOnly->slug]))
            ->assertOk()
            ->assertSee('Add to Basket', false);

        // Same product, but now the shop takes only cash on delivery, which this
        // product refuses. Offering a button that dies at checkout wastes the sale at
        // the last step.
        $this->disableGateway();
        $this->enableCod();

        $this->get(route('shop.product', ['slug' => $gatewayOnly->slug]))
            ->assertOk()
            ->assertDontSee('Add to Basket', false);
    }

    public function test_the_product_form_says_the_two_settings_are_independent(): void
    {
        $user = $this->userWith(['admin.access', 'shop.products.view', 'shop.products.create']);

        $response = $this->actingAs($user)->get(route('admin.shop.products.create'));

        $response->assertOk();

        // The copy that caused this whole task. Both panels now say so.
        //
        // Matched on fragments that sit within one source line: the Blade wraps these
        // sentences across lines, so the rendered HTML carries newlines and indentation
        // in the middle of them and a whole-sentence match would fail on whitespace
        // rather than on the copy being wrong.
        $response->assertSee('a product collected at a counter can still be paid by card or online banking', false);
        $response->assertSee('is set separately under Payment Methods above');
    }
}
