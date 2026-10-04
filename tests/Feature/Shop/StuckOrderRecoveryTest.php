<?php

namespace Tests\Feature\Shop;

use App\Http\Controllers\Payment\ShopOrderPaymentController;
use App\Mail\ShopOrderCollectionReady;
use App\Mail\ShopOrderPaymentLink;
use App\Models\ShopOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * The six stuck orders recovering from one click, with no data touched first.
 *
 * SO-2026-0001..0006 are all `Card or online banking`, all `Pending Payment`, all
 * counter collection, with no payment_reference and no paid_at — because nothing ever
 * opened a CHIP purchase for them. The owner wants to click an icon per order and have
 * them settle themselves.
 *
 * This walks that whole chain on an order built in exactly that shape: the click, the
 * email, the buyer opening the link, pressing Pay, and the callback settling it. The
 * point of doing it as one test rather than six is that the failure being guarded
 * against is a gap between two steps that each pass in isolation.
 *
 * Zero data mutation before the click is asserted explicitly, because the owner is
 * clicking these on a live system.
 */
class StuckOrderRecoveryTest extends ShopPaymentTestCase
{
    use RefreshDatabase;

    /** What CHIP will call the purchase the buyer finally pays. */
    private const PURCHASE = 'pur_recovered';

    private const CAN_NOTIFY = ['admin.access', 'shop.orders.view', 'shop.orders.notify'];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->gatewayShopSettings();
    }

    public function test_one_click_recovers_a_stuck_order_end_to_end(): void
    {
        $product = $this->offlineProduct();
        $order = $this->order($product);

        // The shape the six are actually in, asserted rather than assumed.
        $this->assertSame(ShopOrder::METHOD_GATEWAY, $order->payment_method);
        $this->assertSame(ShopOrder::STATUS_PENDING_PAYMENT, $order->status);
        $this->assertSame(ShopOrder::FULFILMENT_OFFLINE, $order->fulfilment);
        $this->assertNull($order->payment_reference);
        $this->assertNull($order->paid_at);
        $this->assertTrue($order->awaitsGatewayPayment());

        $snapshot = $order->only([
            'status', 'fulfilment', 'payment_method', 'payment_reference',
            'paid_purchase_id', 'paid_at', 'grand_total', 'items_total', 'shipping_total',
        ]);

        /* 1. The owner clicks the icon. */
        $this->actingAs($this->userWith(self::CAN_NOTIFY))
            ->post(route('admin.shop.orders.payment-link', $order))
            ->assertSessionHas('status');

        Mail::assertQueued(ShopOrderPaymentLink::class, 1);

        /* 2. Nothing on the order row moved. He is clicking these on a live system. */
        $afterClick = $order->fresh();

        foreach ($snapshot as $column => $value) {
            $this->assertEquals($value, $afterClick->$column, "The click must not change {$column}.");
        }

        $this->assertSame(0, $afterClick->checkouts()->count());

        /* 3. The buyer opens the emailed link. */
        $link = null;

        Mail::assertQueued(ShopOrderPaymentLink::class, function (ShopOrderPaymentLink $mail) use (&$link) {
            $link = $mail->orderUrl;

            return true;
        });

        $page = $this->get($link);

        $page->assertOk();
        $page->assertSee('Your order is not paid yet');
        $page->assertSee('Pay ' . $order->grandTotalLabel() . ' now');

        // A collected order says where to collect, not a street address nothing is
        // posted to.
        $page->assertSee('Collecting from');
        $page->assertSee('Dewan Serbaguna, Shah Alam');

        /* 4. The buyer presses Pay. */
        $this->fakeChipPurchase(self::PURCHASE);

        $this->post(ShopOrderPaymentController::payUrl($order))
            ->assertRedirect('https://gate.chip-in.asia/p/' . self::PURCHASE);

        $afterPay = $order->fresh();

        // The attempt is recorded before the buyer leaves, so the callback can find
        // this order by the gateway's own id. That is also why the webhook matches on
        // its strongest lookup rather than on our reference.
        $this->assertSame(self::PURCHASE, $afterPay->payment_reference);
        $this->assertSame(1, $afterPay->checkouts()->count());
        $this->assertSame(ShopOrder::STATUS_PENDING_PAYMENT, $afterPay->status);
        $this->assertNull($afterPay->paid_at);

        /* 5. The buyer pays, and CHIP calls back. */
        $this->postWebhook($this->purchasePayload($afterPay, self::PURCHASE))->assertOk();

        $settled = $order->fresh();

        $this->assertSame(ShopOrder::STATUS_PAID, $settled->status);
        $this->assertNotNull($settled->paid_at);
        $this->assertSame(self::PURCHASE, $settled->paid_purchase_id);

        // Stock off once, and the collection email these orders never had.
        $this->assertSame(1, $product->fresh()->stock_taken);
        Mail::assertQueued(ShopOrderCollectionReady::class, 0);
        Mail::assertSent(ShopOrderCollectionReady::class, 1);

        /* 6. And the admin list stops counting it as awaiting payment. */
        $list = $this->actingAs($this->userWith(self::CAN_NOTIFY))
            ->get(route('admin.shop.orders', ['tab' => ShopOrder::FULFILMENT_OFFLINE]));

        $list->assertOk();
        $list->assertDontSee('Send a payment link for ' . $settled->reference);
        $list->assertSee('Delivered');
    }

    public function test_pressing_pay_twice_reuses_the_open_purchase(): void
    {
        $order = $this->order();

        $this->fakeChipPurchase(self::PURCHASE);

        $this->post(ShopOrderPaymentController::payUrl($order))
            ->assertRedirect('https://gate.chip-in.asia/p/' . self::PURCHASE);

        // CHIP reports the purchase still waiting, and would happily create a second
        // one if asked. Asking twice is how a real payment once ended up orphaned.
        Http::fake([
            'gate.chip-in.asia/api/v1/purchases/' . self::PURCHASE . '/' => Http::response([
                'id' => self::PURCHASE,
                'status' => 'created',
                'checkout_url' => 'https://gate.chip-in.asia/p/' . self::PURCHASE,
            ]),
            'gate.chip-in.asia/api/v1/purchases/' => Http::response([
                'id' => 'pur_second_should_not_happen',
                'checkout_url' => 'https://gate.chip-in.asia/p/pur_second_should_not_happen',
            ]),
        ]);

        $this->post(ShopOrderPaymentController::payUrl($order))
            ->assertRedirect('https://gate.chip-in.asia/p/' . self::PURCHASE);

        $after = $order->fresh();

        $this->assertSame(1, $after->checkouts()->count(), 'A second press must not open a second purchase.');
        $this->assertSame(self::PURCHASE, $after->payment_reference);
    }

    public function test_an_order_whose_lines_do_not_add_up_cannot_be_charged(): void
    {
        $order = $this->order();

        // The one data condition that defeats click-recovery: the lines and the total
        // disagree, so there is no honest figure to quote the buyer. Refused before any
        // HTTP call rather than charging a figure the order never showed.
        $order->forceFill(['grand_total' => 99.00])->save();

        Http::fake();

        $response = $this->post(ShopOrderPaymentController::payUrl($order->fresh()));

        $response->assertRedirectContains('/order/' . $order->reference);
        $response->assertSessionHasErrors('payment');

        Http::assertNothingSent();
        $this->assertSame(0, $order->fresh()->checkouts()->count());
    }
}
