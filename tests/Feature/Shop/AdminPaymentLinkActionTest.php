<?php

namespace Tests\Feature\Shop;

use App\Mail\ShopOrderPaymentLink;
use App\Models\ShopOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * The action icon the owner asked for: one press, one email, one order.
 *
 * "kena letak 1 action icon di dalam ini seperti dalam /admin/event/participants?tab=unpaid"
 * — a per-order control that sends the buyer a payment link, copying the Send Payment
 * Reminder pattern. Pressing it must not change the order: it asks for money, it does
 * not assert any arrived.
 *
 * Mail::fake() throughout, so no address in these fixtures can ever be posted to.
 * The link assertions are the sharp ones: the email has to carry the GET confirmation
 * page, because the pay route is POST-only and a mail client following it would get a
 * 405 for every recipient.
 */
class AdminPaymentLinkActionTest extends ShopPaymentTestCase
{
    use RefreshDatabase;

    /** What somebody who may send a link holds. */
    private const CAN_NOTIFY = ['admin.access', 'shop.orders.view', 'shop.orders.notify'];

    /** Read-only: the icon must be invisible and the route must refuse. */
    private const READ_ONLY = ['admin.access', 'shop.orders.view'];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->gatewayShopSettings();
    }

    private function send(ShopOrder $order, array $permissions = self::CAN_NOTIFY)
    {
        return $this->actingAs($this->userWith($permissions))
            ->from(route('admin.shop.orders', ['tab' => ShopOrder::FULFILMENT_OFFLINE]))
            ->post(route('admin.shop.orders.payment-link', $order));
    }

    public function test_it_queues_one_link_and_changes_nothing_on_the_order(): void
    {
        $order = $this->order();

        $before = $order->only(['status', 'payment_reference', 'paid_at', 'paid_purchase_id', 'grand_total']);

        $response = $this->send($order);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        // Queued, not sent: the admin presses this from a table row and must not wait
        // on SMTP. The cPanel cron worker is what delivers it.
        Mail::assertQueued(ShopOrderPaymentLink::class, 1);
        Mail::assertNotSent(ShopOrderPaymentLink::class);

        $after = $order->fresh();

        $this->assertSame($before['status'], $after->status);
        $this->assertSame($before['payment_reference'], $after->payment_reference);
        $this->assertNull($after->paid_at);
        $this->assertNull($after->paid_purchase_id);
        $this->assertEquals($before['grand_total'], $after->grand_total);

        // One line on the trail that already renders on the order page. "queued", not
        // "sent".
        $this->assertSame(1, $after->events()
            ->where('note', 'like', '%Payment link queued%')
            ->count());
    }

    public function test_the_emailed_link_is_a_valid_signed_get_url_for_this_order(): void
    {
        $order = $this->order();

        $this->send($order);

        Mail::assertQueued(ShopOrderPaymentLink::class, function (ShopOrderPaymentLink $mail) use ($order) {
            $url = $mail->orderUrl;

            // Signed, and valid. A reference like SO-2026-0001 is trivial to guess, so
            // an unsigned link would let anybody walk the sequence.
            $this->assertTrue(
                URL::hasValidSignature(Request::create($url)),
                'The emailed link must carry a valid signature.',
            );

            $this->assertStringContainsString('/order/' . $order->reference, $url);

            // The POST-only pay route must never be emailed: it is a 405 for anything
            // that follows a link, which mail clients and scanners both do.
            $this->assertStringNotContainsString('/pay', $url);

            return $mail->order->is($order);
        });
    }

    public function test_a_tampered_emailed_link_is_rejected(): void
    {
        $order = $this->order();

        $this->send($order);

        $url = null;

        Mail::assertQueued(ShopOrderPaymentLink::class, function (ShopOrderPaymentLink $mail) use (&$url) {
            $url = $mail->orderUrl;

            return true;
        });

        // The signature covers the whole URL including the query string, so a figure
        // appended to it invalidates the lot.
        $this->get($url . '&amount=1')->assertForbidden();

        // And a reference swapped for another order's cannot be signed by this one.
        $other = $this->order();
        $this->get(str_replace($order->reference, $other->reference, $url))->assertForbidden();
    }

    public function test_pressing_it_twice_sends_twice_and_still_touches_nothing(): void
    {
        $order = $this->order();

        $this->send($order);
        $this->send($order);

        // An admin-initiated chase, not an idempotent operation: the second press is a
        // second decision to chase.
        Mail::assertQueued(ShopOrderPaymentLink::class, 2);

        $after = $order->fresh();

        $this->assertSame(ShopOrder::STATUS_PENDING_PAYMENT, $after->status);
        $this->assertNull($after->paid_at);
        $this->assertNull($after->payment_reference);
        $this->assertSame(2, $after->events()
            ->where('note', 'like', '%Payment link queued%')
            ->count());
    }

    public function test_without_the_notify_permission_the_route_refuses(): void
    {
        $order = $this->order();

        $this->send($order, self::READ_ONLY)->assertForbidden();

        Mail::assertNothingQueued();
    }

    public function test_a_paid_order_gets_a_warning_and_no_email(): void
    {
        $order = $this->order();
        $order->forceFill(['status' => ShopOrder::STATUS_PAID, 'paid_at' => now()])->save();

        $this->send($order)->assertSessionHas('warning');

        Mail::assertNothingQueued();
    }

    public function test_a_manual_method_gets_a_warning_and_no_email(): void
    {
        $order = $this->order(null, ['payment_method' => ShopOrder::METHOD_COD]);

        $this->send($order)->assertSessionHas('warning');

        Mail::assertNothingQueued();
    }

    public function test_an_unconfigured_gateway_gets_a_warning_and_no_email(): void
    {
        $order = $this->order();
        $this->disableGateway();

        $this->send($order)->assertSessionHas('warning');

        Mail::assertNothingQueued();
    }

    public function test_an_order_with_no_email_address_gets_a_warning(): void
    {
        $order = $this->order();
        $order->forceFill(['customer_email' => ''])->save();

        $this->send($order)->assertSessionHas('warning');

        Mail::assertNothingQueued();
    }

    public function test_the_icon_is_on_the_offline_tab_for_a_payable_order(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->userWith(self::CAN_NOTIFY))
            ->get(route('admin.shop.orders', ['tab' => ShopOrder::FULFILMENT_OFFLINE]));

        $response->assertOk();
        $response->assertSee('Actions');
        $response->assertSee('Send a payment link for ' . $order->reference);
        $response->assertSee(route('admin.shop.orders.payment-link', $order), false);
    }

    public function test_the_icon_is_absent_without_the_permission(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->userWith(self::READ_ONLY))
            ->get(route('admin.shop.orders', ['tab' => ShopOrder::FULFILMENT_OFFLINE]));

        $response->assertOk();

        // The column header stays, so the table does not reshape per user. The control
        // inside it is what goes.
        $response->assertSee('Actions');
        $response->assertDontSee('Send a payment link for ' . $order->reference);
    }

    public function test_the_icon_is_absent_for_a_manual_payment_order(): void
    {
        $order = $this->order(null, ['payment_method' => ShopOrder::METHOD_BANK_TRANSFER]);

        $response = $this->actingAs($this->userWith(self::CAN_NOTIFY))
            ->get(route('admin.shop.orders', ['tab' => ShopOrder::FULFILMENT_OFFLINE]));

        $response->assertOk();
        $response->assertDontSee('Send a payment link for ' . $order->reference);
    }

    public function test_the_order_page_offers_the_same_control_and_a_copyable_link(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->userWith(self::CAN_NOTIFY))
            ->get(route('admin.shop.orders.show', $order));

        $response->assertOk();
        $response->assertSee('Payment Link');
        $response->assertSee('Email a payment link');

        // The copyable field holds the GET page, never the POST pay route.
        $response->assertSee('id="payment-link"', false);
        $response->assertDontSee('/pay?', false);
    }
}
