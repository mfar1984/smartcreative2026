<?php

namespace Tests\Feature\Shop;

use App\Mail\ShopOrderPaymentLink;
use App\Models\ActivityLog;
use App\Models\ShopOrder;
use App\Services\ShopPaymentLinkSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

/**
 * The one press that chases everybody on the list.
 *
 * "ada cara nak handle SEND TO ALL REMINDER EMAIL pada status Pending Payment" — the
 * owner is looking at twenty-two orders, every one of them Pending Payment and paid by
 * card, and clicking twenty-two envelopes is not a plan.
 *
 * What is actually being asserted here is restraint, not reach. A button labelled
 * "all" on a filtered list that quietly meant "all in the database" would email every
 * buyer the shop has ever had, so the filter tests below are the important ones. After
 * those: that a buyer nobody can pay is left alone, and that nobody is mailed twice.
 *
 * Mail::fake() in setUp(), so nothing in these fixtures can reach a transport.
 */
class AdminBulkPaymentLinkTest extends ShopPaymentTestCase
{
    use RefreshDatabase;

    /** What somebody who may chase a buyer holds. */
    private const CAN_NOTIFY = ['admin.access', 'shop.orders.view', 'shop.orders.notify'];

    /** Read-only: the button must be invisible and the route must refuse. */
    private const READ_ONLY = ['admin.access', 'shop.orders.view'];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->gatewayShopSettings();
    }

    /**
     * Press the button, with the filters the screen would have posted.
     *
     * @param  array<string, string>  $filters
     * @param  array<int, string>  $permissions
     */
    private function sendAll(array $filters = [], array $permissions = self::CAN_NOTIFY)
    {
        return $this->actingAs($this->userWith($permissions))
            ->from(route('admin.shop.orders', ['tab' => ShopOrder::FULFILMENT_OFFLINE]))
            ->post(route('admin.shop.orders.payment-links'), $filters + [
                'tab' => ShopOrder::FULFILMENT_OFFLINE,
            ]);
    }

    /* ---------------------------------------------------------------------
     | What goes out
     * ------------------------------------------------------------------ */

    public function test_it_queues_exactly_one_link_per_payable_order(): void
    {
        $orders = collect(range(1, 3))->map(fn () => $this->order());

        $response = $this->sendAll();

        $response->assertRedirect();
        $response->assertSessionHas('status');

        // Queued, never sent: the admin presses this from a table header and must not
        // wait on SMTP three, or twenty-two, times over.
        Mail::assertQueued(ShopOrderPaymentLink::class, 3);
        Mail::assertNotSent(ShopOrderPaymentLink::class);

        foreach ($orders as $order) {
            Mail::assertQueued(
                ShopOrderPaymentLink::class,
                fn (ShopOrderPaymentLink $mail) => $mail->order->is($order),
            );

            $after = $order->fresh();

            // It asks for money. It must not assert any arrived.
            $this->assertSame(ShopOrder::STATUS_PENDING_PAYMENT, $after->status);
            $this->assertNull($after->paid_at);
            $this->assertNull($after->payment_reference);
            $this->assertEquals($order->grand_total, $after->grand_total);

            // The cooldown stamp, and one line on the trail that already renders on
            // the order page.
            $this->assertNotNull($after->payment_link_sent_at);
            $this->assertSame(1, $after->events()
                ->where('note', 'like', '%Payment link queued%')
                ->count());
        }
    }

    public function test_an_unconfigured_gateway_sends_nothing_at_all(): void
    {
        $this->order();
        $this->disableGateway();

        $this->sendAll()->assertSessionHas('warning');

        Mail::assertNothingQueued();
    }

    /* ---------------------------------------------------------------------
     | The filters are the contract
     * ------------------------------------------------------------------ */

    public function test_it_only_mails_the_tab_that_is_open(): void
    {
        $collected = $this->order();
        $posted = $this->order(null, ['fulfilment' => ShopOrder::FULFILMENT_ONLINE]);

        $this->sendAll(['tab' => ShopOrder::FULFILMENT_OFFLINE]);

        Mail::assertQueued(ShopOrderPaymentLink::class, 1);
        Mail::assertQueued(
            ShopOrderPaymentLink::class,
            fn (ShopOrderPaymentLink $mail) => $mail->order->is($collected),
        );

        // Untouched, not merely unmailed: the order on the other tab is as it was.
        $this->assertNull($posted->fresh()->payment_link_sent_at);
    }

    public function test_it_only_mails_what_the_search_box_matched(): void
    {
        $wanted = $this->order(null, ['customer_name' => 'Zarina Binti Kassim']);
        $other = $this->order(null, ['customer_name' => 'Aminah Yusof']);

        $this->sendAll(['q' => 'Zarina']);

        Mail::assertQueued(ShopOrderPaymentLink::class, 1);
        Mail::assertQueued(
            ShopOrderPaymentLink::class,
            fn (ShopOrderPaymentLink $mail) => $mail->order->is($wanted),
        );

        $this->assertNull($other->fresh()->payment_link_sent_at);
    }

    public function test_it_only_mails_what_the_method_dropdown_selected(): void
    {
        $this->order(null, ['payment_method' => ShopOrder::METHOD_BANK_TRANSFER]);
        $gateway = $this->order();

        // Filtered to bank transfer: every order on screen is one with no online link,
        // so the press must send nothing rather than quietly falling back to the lot.
        $this->sendAll(['method' => ShopOrder::METHOD_BANK_TRANSFER])
            ->assertSessionHas('warning');

        Mail::assertNothingQueued();

        $this->assertNull($gateway->fresh()->payment_link_sent_at);
    }

    public function test_it_only_mails_what_the_status_dropdown_selected(): void
    {
        $pending = $this->order();
        $cancelled = $this->order();
        $cancelled->forceFill(['status' => ShopOrder::STATUS_CANCELLED])->save();

        $this->sendAll(['status' => ShopOrder::STATUS_PENDING_PAYMENT]);

        Mail::assertQueued(ShopOrderPaymentLink::class, 1);
        Mail::assertQueued(
            ShopOrderPaymentLink::class,
            fn (ShopOrderPaymentLink $mail) => $mail->order->is($pending),
        );
    }

    public function test_a_status_that_does_not_exist_is_refused(): void
    {
        $this->order();

        $this->sendAll(['status' => 'not-a-status'])->assertSessionHasErrors('status');

        Mail::assertNothingQueued();
    }

    /* ---------------------------------------------------------------------
     | Who is passed over, and why
     * ------------------------------------------------------------------ */

    public function test_paid_cancelled_refunded_emailless_and_manual_orders_are_skipped(): void
    {
        $payable = $this->order();

        $paid = $this->order();
        $paid->forceFill(['status' => ShopOrder::STATUS_PAID, 'paid_at' => now()])->save();

        $cancelled = $this->order();
        $cancelled->forceFill(['status' => ShopOrder::STATUS_CANCELLED])->save();

        $refunded = $this->order();
        $refunded->forceFill([
            'status' => ShopOrder::STATUS_REFUNDED,
            'paid_at' => now()->subDay(),
            'refunded_amount' => 25.00,
            'refunded_at' => now(),
        ])->save();

        $noEmail = $this->order();
        $noEmail->forceFill(['customer_email' => ''])->save();

        $cod = $this->order(null, ['payment_method' => ShopOrder::METHOD_COD]);
        $transfer = $this->order(null, ['payment_method' => ShopOrder::METHOD_BANK_TRANSFER]);

        $nothingToPay = $this->order(null, ['items_total' => 0, 'grand_total' => 0]);

        $this->sendAll()->assertSessionHas('status');

        // One email, to the one buyer who can actually pay.
        Mail::assertQueued(ShopOrderPaymentLink::class, 1);
        Mail::assertQueued(
            ShopOrderPaymentLink::class,
            fn (ShopOrderPaymentLink $mail) => $mail->order->is($payable),
        );

        foreach ([$paid, $cancelled, $refunded, $noEmail, $cod, $transfer, $nothingToPay] as $skipped) {
            $this->assertNull(
                $skipped->fresh()->payment_link_sent_at,
                sprintf('Order %s was skipped, so nothing should have been stamped on it.', $skipped->reference),
            );
        }

        // The counts, by reason, in the words the operator reads. Seven passed over:
        // one each of paid, cancelled, refunded, no address, two settled by hand and
        // one with nothing owing.
        $reasons = ShopPaymentLinkSender::reasons();
        $message = session('status');

        $this->assertStringContainsString('1 payment link queued', $message);
        $this->assertStringContainsString('7 orders passed over', $message);
        $this->assertStringContainsString($reasons[ShopPaymentLinkSender::SKIP_PAID] . ' (1)', $message);
        $this->assertStringContainsString($reasons[ShopPaymentLinkSender::SKIP_CLOSED] . ' (2)', $message);
        $this->assertStringContainsString($reasons[ShopPaymentLinkSender::SKIP_MANUAL] . ' (2)', $message);
        $this->assertStringContainsString($reasons[ShopPaymentLinkSender::SKIP_NO_EMAIL] . ' (1)', $message);
        $this->assertStringContainsString($reasons[ShopPaymentLinkSender::SKIP_NOTHING_TO_PAY] . ' (1)', $message);
    }

    public function test_a_list_with_nobody_to_chase_says_so_and_sends_nothing(): void
    {
        $this->sendAll()->assertSessionHas('warning');

        Mail::assertNothingQueued();
    }

    /* ---------------------------------------------------------------------
     | The cooldown
     * ------------------------------------------------------------------ */

    public function test_a_second_press_inside_the_cooldown_queues_nothing(): void
    {
        $order = $this->order();

        $this->sendAll()->assertSessionHas('status');
        $this->sendAll()->assertSessionHas('warning');

        Mail::assertQueued(ShopOrderPaymentLink::class, 1);

        $this->assertStringContainsString(
            ShopPaymentLinkSender::reasons()[ShopPaymentLinkSender::SKIP_COOLDOWN] . ' (1)',
            session('warning'),
        );

        // One stamp and one trail line, from the press that actually sent.
        $this->assertSame(1, $order->fresh()->events()
            ->where('note', 'like', '%Payment link queued%')
            ->count());
    }

    public function test_the_cooldown_is_shared_with_the_per_order_icon(): void
    {
        $order = $this->order();

        // Pressed in bulk first. The envelope on the row must then refuse, because the
        // buyer has already been emailed — the two paths read the same column.
        $this->sendAll();

        $this->actingAs($this->userWith(self::CAN_NOTIFY))
            ->from(route('admin.shop.orders', ['tab' => ShopOrder::FULFILMENT_OFFLINE]))
            ->post(route('admin.shop.orders.payment-link', $order))
            ->assertSessionHas('warning');

        Mail::assertQueued(ShopOrderPaymentLink::class, 1);
    }

    public function test_it_sends_again_once_the_cooldown_has_passed(): void
    {
        $order = $this->order();

        $this->sendAll();

        $order->forceFill([
            'payment_link_sent_at' => now()->subHours(ShopOrder::PAYMENT_LINK_COOLDOWN_HOURS + 1),
        ])->save();

        $this->sendAll()->assertSessionHas('status');

        Mail::assertQueued(ShopOrderPaymentLink::class, 2);
    }

    /* ---------------------------------------------------------------------
     | Who may fire it, and how
     * ------------------------------------------------------------------ */

    public function test_without_the_notify_permission_the_route_refuses(): void
    {
        $this->order();

        $this->sendAll([], self::READ_ONLY)->assertForbidden();

        Mail::assertNothingQueued();
        $this->assertSame(0, ActivityLog::where('action', 'shop.orders.payment-link-all')->count());
    }

    public function test_a_get_cannot_fire_it(): void
    {
        $order = $this->order();

        /*
         | Refused, and the status code is a 404 rather than a 405: the read route
         | orders/{order} is declared first, so a GET to this address is read as an
         | order reference that does not exist. Either way nothing is sent, which is
         | what matters for a crawler or a prefetching browser following a link.
         */
        $response = $this->actingAs($this->userWith(self::CAN_NOTIFY))
            ->get(route('admin.shop.orders.payment-links'));

        $this->assertTrue(
            $response->status() >= 400,
            'A GET to the bulk payment-link address must be refused, not obeyed.',
        );

        Mail::assertNothingQueued();
        $this->assertNull($order->fresh()->payment_link_sent_at);
    }

    /* ---------------------------------------------------------------------
     | The record
     * ------------------------------------------------------------------ */

    public function test_one_activity_entry_records_the_press_with_the_filter_and_counts(): void
    {
        $this->order();
        $this->order();

        $paid = $this->order();
        $paid->forceFill(['status' => ShopOrder::STATUS_PAID, 'paid_at' => now()])->save();

        $this->sendAll(['status' => ShopOrder::STATUS_PENDING_PAYMENT]);

        $entries = ActivityLog::where('action', 'shop.orders.payment-link-all')->get();

        // One press, one line. Not one per email.
        $this->assertCount(1, $entries);

        $entry = $entries->first();

        $this->assertStringContainsString('Queued 2 payment links', $entry->description);
        $this->assertStringContainsString('status Pending Payment', $entry->description);
        $this->assertStringContainsString('Skipped 0', $entry->description);

        // Who fired it, so a bulk outbound action is answerable for later.
        $this->assertNotNull($entry->user_id);
    }

    /* ---------------------------------------------------------------------
     | The control itself
     * ------------------------------------------------------------------ */

    public function test_the_button_counts_what_it_would_send_and_is_gated_on_the_permission(): void
    {
        $this->order();
        $this->order();
        $this->order(null, ['payment_method' => ShopOrder::METHOD_COD]);

        $allowed = $this->actingAs($this->userWith(self::CAN_NOTIFY))
            ->get(route('admin.shop.orders', ['tab' => ShopOrder::FULFILMENT_OFFLINE]));

        $allowed->assertOk();

        // Two, not three: the cash on delivery order has no online link to send.
        $allowed->assertSee('Send payment link to all 2');
        $allowed->assertSee(route('admin.shop.orders.payment-links'), false);

        $readOnly = $this->actingAs($this->userWith(self::READ_ONLY))
            ->get(route('admin.shop.orders', ['tab' => ShopOrder::FULFILMENT_OFFLINE]));

        $readOnly->assertOk();
        $readOnly->assertDontSee('Send payment link to all');
    }

    public function test_the_button_is_gone_once_everybody_has_been_chased(): void
    {
        $this->order();

        $this->sendAll();

        $response = $this->actingAs($this->userWith(self::CAN_NOTIFY))
            ->get(route('admin.shop.orders', ['tab' => ShopOrder::FULFILMENT_OFFLINE]));

        $response->assertOk();

        // Nobody left to chase, so the control goes rather than sitting there inviting
        // a second press that would be refused.
        $response->assertDontSee('Send payment link to all');

        // And the row says why.
        $response->assertSee('Sent');
    }
}
