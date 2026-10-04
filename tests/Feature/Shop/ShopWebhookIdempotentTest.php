<?php

namespace Tests\Feature\Shop;

use App\Mail\ShopOrderCollectionReady;
use App\Models\ActivityLog;
use App\Models\ShopOrder;
use App\Services\AdminLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

/**
 * A replayed callback cannot double-credit, and a second purchase cannot pass as one.
 *
 * These two are the same code path and opposite verdicts, which is exactly why they are
 * in one file: the test that separates them is paid_purchase_id, and a reading that got
 * it wrong would either shout on every CHIP retry or stay silent while a buyer was
 * charged twice.
 *
 * CHIP retries. A replay therefore has to be a quiet no-op: no second paid_at, no
 * second stock decrement, no second collection email.
 *
 * A DIFFERENT purchase arriving on an already-paid order is the opposite. It means the
 * buyer's money was taken twice — an administrator pressed Confirm Payment while a CHIP
 * purchase was still open and the buyer then completed it, or two purchases both got
 * paid. Nothing may be rewritten, and somebody has to be told.
 */
class ShopWebhookIdempotentTest extends ShopPaymentTestCase
{
    use RefreshDatabase;

    /** The purchase that legitimately settles the order. */
    private const SETTLED = 'pur_settled';

    /** A second one, which must never be mistaken for the first. */
    private const OTHER = 'pur_other';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->gatewayShopSettings();
    }

    /**
     * An order already settled by self::SETTLED, as the webhook would have left it.
     */
    private function settledOrder(): ShopOrder
    {
        $order = $this->order($this->offlineProduct());
        $order->forceFill(['payment_reference' => self::SETTLED])->save();

        $order->checkouts()->create([
            'purchase_id' => self::SETTLED,
            'checkout_url' => 'https://gate.chip-in.asia/p/' . self::SETTLED,
            'gateway' => 'chip',
            'opened_at' => now(),
        ]);

        $this->postWebhook($this->purchasePayload($order, self::SETTLED))->assertOk();

        return $order->fresh();
    }

    public function test_a_replay_of_the_same_purchase_changes_nothing(): void
    {
        $product = $this->offlineProduct();
        $order = $this->order($product);
        $order->forceFill(['payment_reference' => self::SETTLED])->save();

        $this->postWebhook($this->purchasePayload($order, self::SETTLED))->assertOk();

        $first = $order->fresh();
        $paidAt = $first->paid_at;

        $this->assertSame(ShopOrder::STATUS_PAID, $first->status);
        $this->assertSame(1, $product->fresh()->stock_taken);

        // The same body again, exactly as CHIP would retry it.
        $this->postWebhook($this->purchasePayload($order, self::SETTLED))->assertOk();

        $second = $order->fresh();

        $this->assertSame(ShopOrder::STATUS_PAID, $second->status);
        $this->assertEquals($paidAt, $second->paid_at, 'A replay must not move paid_at.');
        $this->assertSame(self::SETTLED, $second->paid_purchase_id);
        $this->assertSame(self::SETTLED, $second->payment_reference);

        // Stock off once, not twice.
        $this->assertSame(1, $product->fresh()->stock_taken);

        // Told once, and only once.
        Mail::assertSent(ShopOrderCollectionReady::class, 1);

        // And quietly: an error-level row for every retry would bury the real ones.
        $this->assertSame(0, ActivityLog::query()
            ->where('action', 'shop.orders.payment-unapplied')
            ->count());
    }

    public function test_a_different_purchase_on_a_paid_order_is_recorded_as_a_double_charge(): void
    {
        $order = $this->settledOrder();
        $product = $order->items->first()->shop_product_id;

        $paidAt = $order->paid_at;

        $this->postWebhook($this->purchasePayload($order, self::OTHER))->assertOk();

        $after = $order->fresh();

        // Nothing about the settlement is rewritten. payment_reference in particular:
        // it is what the refund button reads, so moving it to the second purchase
        // would send a refund at the wrong one.
        $this->assertSame(ShopOrder::STATUS_PAID, $after->status);
        $this->assertEquals($paidAt, $after->paid_at);
        $this->assertSame(self::SETTLED, $after->payment_reference);
        $this->assertSame(self::SETTLED, $after->paid_purchase_id);

        // Stock is not taken a second time.
        $this->assertSame(1, \App\Models\ShopProduct::findOrFail($product)->stock_taken);
        Mail::assertSent(ShopOrderCollectionReady::class, 1);

        // An error-level incident, not an info line.
        $incident = ActivityLog::query()
            ->where('action', 'shop.orders.payment-unapplied')
            ->latest('id')
            ->first();

        $this->assertNotNull($incident, 'A second collection must raise an incident.');
        $this->assertSame(AdminLogger::LEVEL_ERROR, $incident->level);
        $this->assertStringContainsString(self::OTHER, $incident->description);
        $this->assertStringContainsString('double charge', $incident->description);

        // And on the order's own history, where somebody handling the buyer's call
        // would look.
        $this->assertSame(1, $after->events()
            ->where('note', 'like', '%second gateway payment%')
            ->count());
    }

    public function test_a_short_second_collection_is_filed_as_a_double_charge_not_a_shortfall(): void
    {
        $order = $this->settledOrder();

        // Half the charge, on a purchase this order was not settled by. The amount is
        // beside the point: the fact a person needs is that money was taken twice.
        $this->postWebhook($this->purchasePayload(
            $order,
            self::OTHER,
            collectedCents: (int) round((float) $order->grand_total * 100 / 2),
        ))->assertOk();

        $after = $order->fresh();

        $this->assertSame(self::SETTLED, $after->paid_purchase_id);
        $this->assertSame(self::SETTLED, $after->payment_reference);

        $this->assertSame(0, ActivityLog::query()
            ->where('action', 'shop.orders.payment-short')
            ->count(), 'A double collection must not be filed as a shortfall.');

        $this->assertSame(1, ActivityLog::query()
            ->where('action', 'shop.orders.payment-unapplied')
            ->count());
    }

    public function test_a_payment_arriving_for_a_cancelled_order_is_recorded_and_not_applied(): void
    {
        $order = $this->order();
        $order->forceFill([
            'payment_reference' => self::SETTLED,
            'status' => ShopOrder::STATUS_CANCELLED,
        ])->save();

        $this->postWebhook($this->purchasePayload($order, self::SETTLED))->assertOk();

        $after = $order->fresh();

        $this->assertSame(ShopOrder::STATUS_CANCELLED, $after->status);
        $this->assertNull($after->paid_at);
        $this->assertNull($after->paid_purchase_id);

        // The payload is kept, so the admin screen can say money is sitting at CHIP
        // against an order that ended.
        $this->assertNotEmpty($after->payment_details);

        $incident = ActivityLog::query()
            ->where('action', 'shop.orders.payment-unapplied')
            ->latest('id')
            ->first();

        $this->assertNotNull($incident);
        $this->assertSame(AdminLogger::LEVEL_ERROR, $incident->level);
        $this->assertStringContainsString('cancelled', $incident->description);
    }

    public function test_a_short_collection_leaves_the_order_unpaid_and_shouts(): void
    {
        $product = $this->offlineProduct();
        $order = $this->order($product);
        $order->forceFill(['payment_reference' => self::SETTLED])->save();

        $this->postWebhook($this->purchasePayload(
            $order,
            self::SETTLED,
            collectedCents: 1,
        ))->assertOk();

        $after = $order->fresh();

        $this->assertSame(ShopOrder::STATUS_PENDING_PAYMENT, $after->status);
        $this->assertNull($after->paid_at);
        $this->assertSame(0, $product->fresh()->stock_taken);
        Mail::assertNothingSent();

        $incident = ActivityLog::query()
            ->where('action', 'shop.orders.payment-short')
            ->latest('id')
            ->first();

        $this->assertNotNull($incident);
        $this->assertSame(AdminLogger::LEVEL_ERROR, $incident->level);

        // Visible on the order itself, not only in a log nobody opens.
        $this->assertSame(2499, $after->gatewayShortfallCents());
    }

    public function test_an_amount_the_rule_cannot_corroborate_is_paid_rather_than_refused(): void
    {
        $order = $this->order();
        $order->forceFill(['payment_reference' => self::SETTLED])->save();

        // purchase.total disagreeing with our own figure means we are reading a field
        // we have misunderstood, not watching an underpayment. Refusing money that
        // really arrived is the expensive mistake.
        $payload = $this->purchasePayload($order, self::SETTLED);
        $payload['purchase']['total'] = 999999;

        $this->postWebhook($payload)->assertOk();

        $after = $order->fresh();

        $this->assertSame(ShopOrder::STATUS_PAID, $after->status);
        $this->assertSame(1, $after->events()
            ->where('note', 'like', '%figure was not checked%')
            ->count());
    }
}
