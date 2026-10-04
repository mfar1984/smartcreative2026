<?php

namespace Tests\Feature\Shop;

use App\Http\Controllers\Payment\ShopOrderPaymentController;
use App\Models\ShopOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Nothing in a URL can change what is charged, which order, or whether it is paid.
 *
 * The payment link goes out by email, so it ends up in inboxes, forwarded messages and
 * browser history. The defence is that the only things in it are a reference and an
 * expiry, both inside the signature, and that the amount is read from the database on
 * every single request.
 *
 * The success-outcome test is the one worth reading twice: a buyer can legitimately
 * hold a valid signed return URL saying "success". It must still not pay the order.
 * What settles an order is the signed webhook or a direct read of the purchase, never a
 * query string.
 */
class PaymentLinkTamperTest extends ShopPaymentTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->gatewayShopSettings();
    }

    public function test_an_unsigned_pay_request_is_refused(): void
    {
        $order = $this->order();
        Http::fake();

        $this->post('/order/' . $order->reference . '/pay')->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, $order->fresh()->checkouts()->count());
    }

    public function test_an_amount_appended_to_a_signed_pay_url_is_refused(): void
    {
        $order = $this->order();
        Http::fake();

        $this->post(ShopOrderPaymentController::payUrl($order) . '&amount=1')->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, $order->fresh()->checkouts()->count());
    }

    public function test_a_signed_pay_url_cannot_be_pointed_at_another_order(): void
    {
        $mine = $this->order();
        $theirs = $this->order();

        Http::fake();

        $url = str_replace($mine->reference, $theirs->reference, ShopOrderPaymentController::payUrl($mine));

        $this->post($url)->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, $theirs->fresh()->checkouts()->count());
    }

    public function test_an_expired_link_is_refused(): void
    {
        $order = $this->order();
        Http::fake();

        $url = ShopOrderPaymentController::payUrl($order);

        // Thirty-one days on: the link was issued for thirty.
        $this->travel(31)->days();

        $this->post($url)->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, $order->fresh()->checkouts()->count());
    }

    public function test_a_tampered_outcome_on_the_return_route_is_refused(): void
    {
        $order = $this->order();

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'shop.order.payment.return',
            now()->addDays(30),
            ['reference' => $order->reference, 'outcome' => 'cancel'],
        );

        $this->get(str_replace('/cancel', '/success', $url))->assertForbidden();

        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_a_valid_success_return_does_not_pay_an_order_the_gateway_calls_unfinished(): void
    {
        $order = $this->order();
        $order->forceFill(['payment_reference' => 'pur_open'])->save();

        // CHIP says the purchase was only ever created. The query string says success.
        // The gateway wins.
        Http::fake([
            'gate.chip-in.asia/api/v1/purchases/*' => Http::response([
                'id' => 'pur_open',
                'status' => 'created',
                'purchase' => ['total' => 2500],
            ]),
        ]);

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'shop.order.payment.return',
            now()->addDays(30),
            ['reference' => $order->reference, 'outcome' => 'success'],
        );

        $this->get($url)->assertRedirectContains('/order/' . $order->reference);

        $after = $order->fresh();

        $this->assertSame(ShopOrder::STATUS_PENDING_PAYMENT, $after->status);
        $this->assertNull($after->paid_at);
        $this->assertNull($after->paid_purchase_id);
        Mail::assertNothingSent();
    }

    public function test_a_refunded_order_is_not_repayable_and_says_why(): void
    {
        $order = $this->order();
        $order->forceFill([
            'status' => ShopOrder::STATUS_REFUNDED,
            'paid_at' => now()->subDay(),
            'payment_reference' => 'pur_refunded',
            'refunded_amount' => 25.00,
            'refunded_at' => now(),
        ])->save();

        Http::fake();

        $response = $this->post(ShopOrderPaymentController::payUrl($order));

        $response->assertRedirectContains('/order/' . $order->reference);
        $response->assertSessionHasErrors('payment');

        Http::assertNothingSent();
        $this->assertSame(0, $order->fresh()->checkouts()->count());

        // isClosed() before isPaid(): paid_at survives a refund, so testing paid first
        // would tell this buyer the order is "already paid, nothing further is owed".
        $this->followRedirects($response)->assertSee('has been refunded');
    }

    public function test_a_paid_order_cannot_be_paid_again(): void
    {
        $order = $this->order();
        $order->forceFill([
            'status' => ShopOrder::STATUS_PAID,
            'paid_at' => now(),
            'paid_purchase_id' => 'pur_done',
            'payment_reference' => 'pur_done',
        ])->save();

        Http::fake();

        $response = $this->post(ShopOrderPaymentController::payUrl($order));

        $response->assertRedirectContains('/order/' . $order->reference);

        Http::assertNothingSent();
        $this->assertSame(0, $order->fresh()->checkouts()->count());

        $this->followRedirects($response)->assertSee('already paid');
    }

    public function test_a_manual_method_order_cannot_be_paid_through_the_gateway_route(): void
    {
        $order = $this->order(null, ['payment_method' => ShopOrder::METHOD_COD]);

        Http::fake();

        $response = $this->post(ShopOrderPaymentController::payUrl($order));

        $response->assertSessionHasErrors('payment');
        Http::assertNothingSent();
        $this->assertSame(0, $order->fresh()->checkouts()->count());

        // The banner pair on the confirmation page is above every payment-state branch,
        // which is the only reason this reason reaches the buyer at all.
        $this->followRedirects($response)->assertSee('settled with us directly');
    }

    public function test_the_charge_is_rebuilt_from_the_database_not_from_the_request(): void
    {
        $order = $this->order();
        $this->fakeChipPurchase('pur_from_db');

        // Every figure a buyer could possibly submit, all of them wrong.
        $this->post(ShopOrderPaymentController::payUrl($order), [
            'amount' => 1,
            'grand_total' => 0.01,
            'items_total' => 0.01,
            'status' => ShopOrder::STATUS_PAID,
        ])->assertRedirect('https://gate.chip-in.asia/p/pur_from_db');

        Http::assertSent(function ($request) use ($order) {
            $body = $request->data();
            $total = array_sum(array_map(
                fn (array $line) => $line['price'] * (int) $line['quantity'],
                $body['purchase']['products'],
            ));

            return $total === (int) round((float) $order->grand_total * 100)
                && $body['reference'] === $order->reference;
        });

        $after = $order->fresh();

        $this->assertSame(ShopOrder::STATUS_PENDING_PAYMENT, $after->status);
        $this->assertNull($after->paid_at);
        $this->assertEquals('25.00', $after->grand_total);
    }
}
