<?php

namespace Tests\Feature\Shop;

use App\Models\ShopOrder;
use App\Services\ShopOrderWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

/**
 * Paying for something is not the same as receiving it.
 *
 * The owner's report: SO-2026-0023 was paid by card, and the Hand Over column on the
 * Offline orders list read as a green tick saying Delivered — while the counter at the
 * top of the same screen said one waiting to be collected. The goods were still with
 * the organiser; the buyer collects them at an event nearly two weeks later.
 *
 * The state was never wrong. ShopOrderWriter::moveTo() stamps paid_at on the move into
 * paid and touches delivered_at only on the move into delivered, and scopeOpen()
 * counted the order correctly. What the cell rendered was the hand-over BUTTON, whose
 * label was its destination status rather than the order's state. So these tests pin
 * both halves: the state after payment, and what the cell actually says.
 *
 * Mail::fake() in setUp(), because the move into paid queues the collection email.
 */
class AdminConfirmCollectionTest extends ShopPaymentTestCase
{
    use RefreshDatabase;

    /** Somebody who may move an order along, which is what records a handover. */
    private const CAN_UPDATE = ['admin.access', 'shop.orders.view', 'shop.orders.update'];

    /** Read-only: the icon must be invisible and the route must refuse. */
    private const READ_ONLY = ['admin.access', 'shop.orders.view'];

    private const PURCHASE = 'pur_collect_1';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->gatewayShopSettings();
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    /** An offline order settled the way the gateway settles one. */
    private function paidOfflineOrder(): ShopOrder
    {
        $order = $this->order();

        app(ShopOrderWriter::class)->moveTo(
            $order,
            ShopOrder::STATUS_PAID,
            'Paid through chip webhook (Card or online banking).',
        );

        return $order->fresh();
    }

    /** A posted order, for the half of the table that must not change. */
    private function onlineOrder(array $overrides = []): ShopOrder
    {
        return $this->order(null, $overrides + [
            'fulfilment' => ShopOrder::FULFILMENT_ONLINE,
            'collection_label' => null,
            'collection_location' => null,
            'collection_at' => null,
        ]);
    }

    private function offlineList(array $permissions = self::CAN_UPDATE)
    {
        return $this->actingAs($this->userWith($permissions))
            ->get(route('admin.shop.orders', ['tab' => ShopOrder::FULFILMENT_OFFLINE]));
    }

    private function confirm(ShopOrder $order, array $permissions = self::CAN_UPDATE, array $body = [])
    {
        return $this->actingAs($this->userWith($permissions))
            ->from(route('admin.shop.orders', ['tab' => ShopOrder::FULFILMENT_OFFLINE]))
            ->post(route('admin.shop.orders.collect', $order), $body);
    }

    /**
     * The Hand Over cell for one row, and only that cell.
     *
     * Asserted on rather than the page, because the page legitimately carries the word
     * Delivered in the status filter dropdown. A page-wide assertDontSee would either
     * fail for the wrong reason or pass for it.
     */
    private function handOverCell(string $html, ShopOrder $order): string
    {
        $start = strpos($html, 'data-hand-over="' . $order->reference . '"');

        $this->assertNotFalse($start, 'No hand-over cell was rendered for ' . $order->reference . '.');

        $end = strpos($html, '</td>', $start);

        $this->assertNotFalse($end, 'The hand-over cell for ' . $order->reference . ' was never closed.');

        return substr($html, $start, $end - $start);
    }

    /* ---------------------------------------------------------------------
     | The regression
     * ------------------------------------------------------------------ */

    public function test_a_paid_offline_order_reads_paid_and_awaiting_collection_not_delivered(): void
    {
        $order = $this->paidOfflineOrder();

        // The state, first. This is what the counter reads and what the cell must
        // agree with.
        $this->assertSame(ShopOrder::STATUS_PAID, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertNull($order->delivered_at);
        $this->assertFalse($order->isCollected());
        $this->assertTrue($order->awaitsCollection());

        $response = $this->offlineList();
        $response->assertOk();

        $cell = $this->handOverCell($response->getContent(), $order);

        $this->assertStringContainsString('Awaiting collection', $cell);

        // The exact fault: the cell must not say the goods have gone, in any wording.
        $this->assertStringNotContainsString('Delivered', $cell);
        $this->assertStringNotContainsString('Collected ', $cell);
    }

    public function test_a_gateway_payment_leaves_an_offline_order_awaiting_collection(): void
    {
        $order = $this->order();

        $order->forceFill(['payment_reference' => self::PURCHASE])->save();
        $order->checkouts()->create([
            'purchase_id' => self::PURCHASE,
            'checkout_url' => 'https://gate.chip-in.asia/p/' . self::PURCHASE,
            'gateway' => 'chip',
            'opened_at' => now(),
        ]);

        $this->postWebhook($this->purchasePayload($order, self::PURCHASE))->assertOk();

        $order->refresh();

        // Settling the money must not hand anything over. The real path, not just the
        // writer: this is the one the owner's order went through.
        $this->assertSame(ShopOrder::STATUS_PAID, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertNull($order->delivered_at);
        $this->assertTrue($order->awaitsCollection());
    }

    public function test_the_counter_and_the_row_badge_agree_for_a_paid_but_uncollected_order(): void
    {
        $order = $this->paidOfflineOrder();

        $response = $this->offlineList();
        $response->assertOk();

        // The figure at the top of the screen, counted through scopeOpen().
        $response->assertSee('waiting to be collected');
        $response->assertSee('>1</span>', false);

        // And the row says the same thing, which is the whole complaint.
        $this->assertStringContainsString(
            'Awaiting collection',
            $this->handOverCell($response->getContent(), $order),
        );
    }

    /* ---------------------------------------------------------------------
     | Confirming the handover
     * ------------------------------------------------------------------ */

    public function test_the_icon_and_its_dialog_are_offered_for_a_paid_offline_order(): void
    {
        $order = $this->paidOfflineOrder();

        $response = $this->offlineList();

        $response->assertOk();
        $response->assertSee('Actions');
        $response->assertSee('Confirm collection of ' . $order->reference);
        $response->assertSee(route('admin.shop.orders.collect', $order), false);

        // The dialog itself, not a bare glyph with a confirm() behind it.
        $response->assertSee('id="collect-' . $order->id . '"', false);
        $response->assertSee('Confirm Collection');
    }

    public function test_confirming_flips_it_to_collected_and_records_who_and_when(): void
    {
        $order = $this->paidOfflineOrder();
        $admin = $this->userWith(self::CAN_UPDATE);

        $response = $this->actingAs($admin)
            ->from(route('admin.shop.orders', ['tab' => ShopOrder::FULFILMENT_OFFLINE]))
            ->post(route('admin.shop.orders.collect', $order), ['note' => 'Picked up by her brother.']);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $after = $order->fresh();

        $this->assertSame(ShopOrder::STATUS_DELIVERED, $after->status);
        $this->assertTrue($after->isCollected());
        $this->assertNotNull($after->delivered_at);
        $this->assertFalse($after->awaitsCollection());

        // Who, and when. The trail entry carries both, and it is the panel somebody
        // opens when a buyer rings up.
        $event = $after->events()->reorder()->latest('id')->first();

        $this->assertSame(ShopOrder::STATUS_DELIVERED, $event->status);
        $this->assertSame('Picked up by her brother.', $event->note);
        $this->assertSame($admin->id, $event->user_id);
        $this->assertSame($admin->logLabel(), $event->actor_label);

        // And the activity log, following the pattern every other order action uses.
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'shop.orders.collected',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => ShopOrder::class,
            'auditable_id' => $order->id,
            'event' => 'collected',
        ]);
    }

    public function test_the_row_reads_collected_once_it_has_been_handed_over(): void
    {
        $order = $this->paidOfflineOrder();

        $this->confirm($order)->assertSessionHas('status');

        $response = $this->offlineList();
        $response->assertOk();

        $cell = $this->handOverCell($response->getContent(), $order->fresh());

        $this->assertStringContainsString('Collected', $cell);

        // The action is gone with it: there is nothing left to hand over.
        $response->assertDontSee('Confirm collection of ' . $order->reference);
    }

    public function test_confirming_twice_is_idempotent(): void
    {
        $order = $this->paidOfflineOrder();

        $this->confirm($order)->assertSessionHas('status');

        $first = $order->fresh();
        $deliveredAt = $first->delivered_at;
        $events = $first->events()->count();

        // A double press, a reload, or two people working the same counter queue.
        $this->confirm($order->fresh())->assertSessionHas('warning');

        $second = $order->fresh();

        $this->assertSame(ShopOrder::STATUS_DELIVERED, $second->status);
        $this->assertEquals($deliveredAt, $second->delivered_at);

        // No second trail entry, no second activity line.
        $this->assertSame($events, $second->events()->count());
        $this->assertSame(1, \App\Models\ActivityLog::query()
            ->where('action', 'shop.orders.collected')
            ->count());
    }

    /* ---------------------------------------------------------------------
     | Who it is offered to, and for what
     * ------------------------------------------------------------------ */

    public function test_the_icon_is_absent_for_an_unpaid_offline_order(): void
    {
        $order = $this->order();

        $response = $this->offlineList();

        $response->assertOk();
        $response->assertSee('Actions');
        $response->assertDontSee('Confirm collection of ' . $order->reference);

        $this->assertStringContainsString(
            'Not paid yet',
            $this->handOverCell($response->getContent(), $order),
        );
    }

    public function test_the_route_refuses_an_unpaid_offline_order(): void
    {
        $order = $this->order();

        $this->confirm($order)->assertSessionHas('warning');

        $after = $order->fresh();

        $this->assertSame(ShopOrder::STATUS_PENDING_PAYMENT, $after->status);
        $this->assertNull($after->delivered_at);
        $this->assertSame(0, \App\Models\ActivityLog::query()
            ->where('action', 'shop.orders.collected')
            ->count());
    }

    public function test_the_icon_is_absent_for_an_online_order(): void
    {
        $online = $this->onlineOrder();

        app(ShopOrderWriter::class)->moveTo($online, ShopOrder::STATUS_PAID, 'Paid.');

        $response = $this->actingAs($this->userWith(self::CAN_UPDATE))
            ->get(route('admin.shop.orders', ['tab' => ShopOrder::FULFILMENT_ONLINE]));

        $response->assertOk();
        $response->assertSee('Actions');
        $response->assertDontSee('Confirm collection of ' . $online->reference);

        // The column itself only exists on the Offline tab.
        $response->assertDontSee('Hand Over');
    }

    public function test_the_route_refuses_an_online_order(): void
    {
        $online = $this->onlineOrder();

        app(ShopOrderWriter::class)->moveTo($online, ShopOrder::STATUS_PAID, 'Paid.');

        $this->confirm($online->fresh())->assertSessionHas('warning');

        $after = $online->fresh();

        // Still paid, still owed a parcel. Nothing about the courier flow moved.
        $this->assertSame(ShopOrder::STATUS_PAID, $after->status);
        $this->assertNull($after->delivered_at);
    }

    public function test_an_online_order_keeps_its_existing_delivered_flow(): void
    {
        $online = $this->onlineOrder();

        app(ShopOrderWriter::class)->moveTo($online, ShopOrder::STATUS_PAID, 'Paid.');

        $admin = $this->userWith(self::CAN_UPDATE);

        foreach ([ShopOrder::STATUS_PACKING, ShopOrder::STATUS_SHIPPED, ShopOrder::STATUS_DELIVERED] as $status) {
            $this->actingAs($admin)
                ->put(route('admin.shop.orders.status', $online), [
                    'status' => $status,
                    'courier_name' => 'J&T Express',
                    'tracking_number' => 'JT123456789',
                ])
                ->assertRedirect();
        }

        $after = $online->fresh();

        $this->assertSame(ShopOrder::STATUS_DELIVERED, $after->status);
        $this->assertNotNull($after->shipped_at);
        $this->assertNotNull($after->delivered_at);
        $this->assertSame('JT123456789', $after->tracking_number);
    }

    public function test_the_action_is_refused_without_the_permission(): void
    {
        $order = $this->paidOfflineOrder();

        $this->confirm($order, self::READ_ONLY)->assertForbidden();

        $after = $order->fresh();

        $this->assertSame(ShopOrder::STATUS_PAID, $after->status);
        $this->assertNull($after->delivered_at);
    }

    public function test_the_icon_is_absent_without_the_permission(): void
    {
        $order = $this->paidOfflineOrder();

        $response = $this->offlineList(self::READ_ONLY);

        $response->assertOk();

        // The column header stays, so the table does not reshape per user. The control
        // inside it is what goes.
        $response->assertSee('Hand Over');
        $response->assertDontSee('Confirm collection of ' . $order->reference);

        // The state still reads truthfully for somebody who may only look.
        $this->assertStringContainsString(
            'Awaiting collection',
            $this->handOverCell($response->getContent(), $order),
        );
    }

    public function test_a_get_is_refused(): void
    {
        $order = $this->paidOfflineOrder();

        $this->actingAs($this->userWith(self::CAN_UPDATE))
            ->get(route('admin.shop.orders.collect', $order))
            ->assertStatus(405);

        $this->assertNull($order->fresh()->delivered_at);
    }

    /* ---------------------------------------------------------------------
     | The single-order screen
     * ------------------------------------------------------------------ */

    public function test_the_order_page_offers_the_handover_and_not_a_bare_delivered_option(): void
    {
        $order = $this->paidOfflineOrder();

        $response = $this->actingAs($this->userWith(self::CAN_UPDATE))
            ->get(route('admin.shop.orders.show', $order));

        $response->assertOk();
        $response->assertSee('Hand Over At The Counter');
        $response->assertSee('Confirm collection');
        $response->assertSee(route('admin.shop.orders.collect', $order), false);

        // Delivered is no longer a word in the Move This Order Along select for a
        // counter order: two ways to record one handover is one too many.
        $response->assertDontSee('value="' . ShopOrder::STATUS_DELIVERED . '"', false);
    }

    public function test_the_order_page_still_offers_delivered_for_a_posted_order(): void
    {
        $online = $this->onlineOrder();

        app(ShopOrderWriter::class)->moveTo($online, ShopOrder::STATUS_PAID, 'Paid.');
        app(ShopOrderWriter::class)->moveTo($online->fresh(), ShopOrder::STATUS_PACKING, 'Packing.');
        app(ShopOrderWriter::class)->moveTo($online->fresh(), ShopOrder::STATUS_SHIPPED, 'Shipped.');

        $response = $this->actingAs($this->userWith(self::CAN_UPDATE))
            ->get(route('admin.shop.orders.show', $online->fresh()));

        $response->assertOk();
        $response->assertSee('value="' . ShopOrder::STATUS_DELIVERED . '"', false);
        $response->assertDontSee('Hand Over At The Counter');
    }
}
