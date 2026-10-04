<?php

namespace Tests\Feature\Shop;

use App\Mail\ShopOrderBankTransferInstructions;
use App\Models\ShopOrder;
use App\Models\ShopProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * A counter-collected product paid by card actually reaches the gateway.
 *
 * This is the fault the owner reported: a product set to Offline collection, with
 * Card or online banking ticked, produced an order nobody could ever pay for. No code
 * opened a CHIP checkout for a shop order at all, so six real orders sat in Pending
 * Payment with no money behind them.
 *
 * The other half of this file is the one that keeps the fix honest: cash on delivery
 * and bank transfer are posted through the SAME edited method, with CHIP configured,
 * so a branch written one statement too high or with the comparison inverted would
 * show up here rather than in production.
 */
class CheckoutGatewayRedirectTest extends ShopPaymentTestCase
{
    use RefreshDatabase;

    /** The id CHIP hands back for the purchase these tests open. */
    private const PURCHASE = 'pur_test_1';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->gatewayShopSettings();
    }

    public function test_the_checkout_offers_card_payment_for_a_collected_product(): void
    {
        $product = $this->offlineProduct([ShopOrder::METHOD_GATEWAY, ShopOrder::METHOD_BANK_TRANSFER]);
        $this->enableBankTransfer();

        $response = $this->withSession($this->basket($product))->get(route('checkout'));

        $response->assertOk();
        $response->assertSee('value="' . ShopOrder::METHOD_GATEWAY . '"', false);
        $response->assertSee(ShopOrder::METHODS[ShopOrder::METHOD_GATEWAY]);

        // The sentence that resolves the owner's confusion: collection and payment are
        // two separate things.
        $response->assertSee('You still collect in person; only the payment happens online.');
    }

    public function test_the_narrowing_comes_from_the_product_not_from_fulfilment(): void
    {
        $product = $this->offlineProduct([ShopOrder::METHOD_BANK_TRANSFER]);
        $this->enableBankTransfer();

        $response = $this->withSession($this->basket($product))->get(route('checkout'));

        $response->assertOk();
        $response->assertDontSee('value="' . ShopOrder::METHOD_GATEWAY . '"', false);
    }

    public function test_placing_a_gateway_order_opens_a_chip_purchase_and_redirects_to_it(): void
    {
        $product = $this->offlineProduct();
        $this->fakeChipPurchase(self::PURCHASE);

        $response = $this->withSession($this->basket($product))
            ->post(route('checkout.place'), $this->checkoutFields(ShopOrder::METHOD_GATEWAY));

        $response->assertRedirect('https://gate.chip-in.asia/p/' . self::PURCHASE);

        $order = ShopOrder::query()->latest('id')->first();

        $this->assertSame(ShopOrder::FULFILMENT_OFFLINE, $order->fulfilment);
        $this->assertSame(ShopOrder::METHOD_GATEWAY, $order->payment_method);
        $this->assertSame(ShopOrder::STATUS_PENDING_PAYMENT, $order->status);
        $this->assertNull($order->paid_at);
        $this->assertSame(self::PURCHASE, $order->payment_reference);

        // The attempt is on record before the redirect, so a callback can find this
        // order by the gateway's own id whatever happens next.
        $this->assertSame(1, $order->checkouts()->count());
        $this->assertSame(self::PURCHASE, $order->checkouts()->first()->purchase_id);
        $this->assertSame(
            'https://gate.chip-in.asia/p/' . self::PURCHASE,
            $order->checkouts()->first()->checkout_url,
        );

        // Stock only ever moves on paid.
        $this->assertSame(0, $product->fresh()->stock_taken);

        // The amount is built server-side from the order's own lines, to the cent.
        Http::assertSent(function ($request) use ($order) {
            $body = $request->data();
            $lines = $body['purchase']['products'];
            $total = array_sum(array_map(
                fn (array $line) => $line['price'] * (int) $line['quantity'],
                $lines,
            ));

            return $body['reference'] === $order->reference
                && $total === (int) round((float) $order->grand_total * 100);
        });

        $this->assertTrue(session('shop.cart') === null || session('shop.cart') === []);
    }

    public function test_a_bank_transfer_order_keeps_its_manual_flow(): void
    {
        $product = $this->offlineProduct([
            ShopOrder::METHOD_GATEWAY,
            ShopOrder::METHOD_BANK_TRANSFER,
            ShopOrder::METHOD_COD,
        ]);

        $this->enableBankTransfer();
        $this->enableCod();

        // CHIP is configured, so the gateway branch is live. Only the posted method
        // keeps it from firing.
        Http::fake();

        $response = $this->withSession($this->basket($product))
            ->post(route('checkout.place'), $this->checkoutFields(ShopOrder::METHOD_BANK_TRANSFER));

        $order = ShopOrder::query()->latest('id')->first();

        $response->assertRedirectContains('/order/' . $order->reference);
        $this->assertStringNotContainsString('gate.chip-in.asia', $response->headers->get('Location'));

        Http::assertNothingSent();
        $this->assertSame(0, $order->checkouts()->count());
        $this->assertNull($order->payment_reference);
        $this->assertSame(ShopOrder::STATUS_PENDING_PAYMENT, $order->status);

        // The assertion that fails if the gateway branch was inserted above the
        // notifier call rather than below it.
        Mail::assertSent(ShopOrderBankTransferInstructions::class, 1);
    }

    public function test_a_cash_on_delivery_order_keeps_its_manual_flow(): void
    {
        $product = ShopProduct::create([
            'slug' => 'poster-' . uniqid(),
            'name' => 'Poster',
            'sku' => 'SKU-' . strtoupper(uniqid()),
            'price' => 25.00,
            'track_inventory' => false,
            'status' => ShopProduct::STATUS_ACTIVE,
            'payment_methods' => [ShopOrder::METHOD_GATEWAY, ShopOrder::METHOD_COD],
            'fulfilment' => ShopProduct::FULFILMENT_ONLINE,
        ]);

        $this->enableCod();
        Http::fake();

        $response = $this->withSession($this->basket($product))
            ->post(route('checkout.place'), $this->checkoutFields(ShopOrder::METHOD_COD));

        $order = ShopOrder::query()->latest('id')->first();

        $response->assertRedirectContains('/order/' . $order->reference);
        $this->assertStringNotContainsString('gate.chip-in.asia', $response->headers->get('Location'));

        Http::assertNothingSent();
        $this->assertSame(0, $order->checkouts()->count());
        $this->assertNull($order->payment_reference);

        // bankTransferInstructions() no-ops for COD, so nothing at all goes out.
        Mail::assertNothingSent();
    }

    public function test_a_gateway_refusal_keeps_the_order_and_offers_a_retry(): void
    {
        $product = $this->offlineProduct();

        Http::fake([
            'gate.chip-in.asia/api/v1/purchases/' => Http::response(['detail' => 'nope'], 500),
        ]);

        $response = $this->withSession($this->basket($product))
            ->post(route('checkout.place'), $this->checkoutFields(ShopOrder::METHOD_GATEWAY));

        $order = ShopOrder::query()->latest('id')->first();

        $response->assertRedirectContains('/order/' . $order->reference);
        $response->assertSessionHasErrors('payment');

        // The order survives the failed hand-off. Only the hand-off failed.
        $this->assertSame(ShopOrder::STATUS_PENDING_PAYMENT, $order->status);
        $this->assertNull($order->payment_reference);
        $this->assertSame(0, $order->checkouts()->count());

        // And the buyer has somewhere to try again from.
        $this->followRedirects($response)
            ->assertSee('Your order is not paid yet')
            ->assertSee('Pay ' . $order->grandTotalLabel() . ' now');
    }
}
