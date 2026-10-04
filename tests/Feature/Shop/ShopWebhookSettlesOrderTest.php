<?php

namespace Tests\Feature\Shop;

use App\Mail\ShopOrderCollectionReady;
use App\Models\ShopOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

/**
 * A card order is marked paid automatically, with no administrator ticking anything.
 *
 * The owner's second complaint: six orders sat in Pending Payment and the only way out
 * was somebody asserting by hand that money had arrived. For a card or online-banking
 * order that assertion should never be needed — the gateway knows, and it tells us.
 *
 * The three lookups are each exercised separately because they are each the only one
 * that works in a particular real situation: the current reference, an earlier attempt
 * the reference has since moved away from, and our own reference echoed back.
 */
class ShopWebhookSettlesOrderTest extends ShopPaymentTestCase
{
    use RefreshDatabase;

    private const PURCHASE = 'pur_settles_1';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->gatewayShopSettings();
    }

    public function test_a_signed_success_callback_marks_the_order_paid_and_takes_stock_once(): void
    {
        $product = $this->offlineProduct();
        $order = $this->order($product);

        $order->forceFill(['payment_reference' => self::PURCHASE])->save();
        $order->checkouts()->create([
            'purchase_id' => self::PURCHASE,
            'checkout_url' => 'https://gate.chip-in.asia/p/' . self::PURCHASE,
            'gateway' => 'chip',
            'opened_at' => now(),
        ]);

        $response = $this->postWebhook($this->purchasePayload($order, self::PURCHASE));

        $response->assertOk();

        $order->refresh();

        $this->assertSame(ShopOrder::STATUS_PAID, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(self::PURCHASE, $order->paid_purchase_id);
        $this->assertNotNull($order->payment_synced_at);

        // Stock comes off exactly once, on the move into paid, because the webhook
        // writes through ShopOrderWriter::moveTo() rather than touching the model.
        $this->assertSame(1, $product->fresh()->stock_taken);

        // The collection email these six orders never had: collectionReady() is gated
        // on isOffline() && isPaid().
        Mail::assertSent(ShopOrderCollectionReady::class, 1);
    }

    public function test_it_matches_an_earlier_checkout_attempt_the_reference_has_moved_away_from(): void
    {
        $order = $this->order();

        // The buyer pressed Pay twice. The column holds the second purchase; the first
        // is the one that got paid.
        $order->forceFill(['payment_reference' => 'pur_second'])->save();

        foreach (['pur_first', 'pur_second'] as $purchase) {
            $order->checkouts()->create([
                'purchase_id' => $purchase,
                'checkout_url' => 'https://gate.chip-in.asia/p/' . $purchase,
                'gateway' => 'chip',
                'opened_at' => now(),
            ]);
        }

        $this->postWebhook($this->purchasePayload($order, 'pur_first'))->assertOk();

        $order->refresh();

        $this->assertSame(ShopOrder::STATUS_PAID, $order->status);

        // Re-pointed at the purchase that actually holds the money, which is what a
        // refund has to target.
        $this->assertSame('pur_first', $order->payment_reference);
        $this->assertSame('pur_first', $order->paid_purchase_id);
    }

    public function test_it_matches_on_our_own_reference_when_a_purchase_was_opened(): void
    {
        $order = $this->order();

        $order->checkouts()->create([
            'purchase_id' => 'pur_opened',
            'checkout_url' => 'https://gate.chip-in.asia/p/pur_opened',
            'gateway' => 'chip',
            'opened_at' => now(),
        ]);

        // A purchase id nothing points at, but our own reference in the body.
        $this->postWebhook($this->purchasePayload($order, 'pur_unknown'))->assertOk();

        $order->refresh();

        $this->assertSame(ShopOrder::STATUS_PAID, $order->status);
        $this->assertSame('pur_unknown', $order->paid_purchase_id);
    }

    public function test_it_refuses_to_settle_an_order_no_purchase_was_ever_opened_for(): void
    {
        // Exactly the shape of SO-2026-0001..0006: gateway method, pending, but
        // payment_reference NULL and no checkout row, so nothing was ever quoted to
        // CHIP. A purchase carrying this reference did not come from here.
        $order = $this->order();

        $this->postWebhook($this->purchasePayload($order, 'pur_fabricated'))->assertOk();

        $order->refresh();

        $this->assertSame(ShopOrder::STATUS_PENDING_PAYMENT, $order->status);
        $this->assertNull($order->paid_at);
        $this->assertNull($order->paid_purchase_id);
        Mail::assertNothingSent();
    }

    public function test_a_cash_on_delivery_order_is_never_settled_by_a_callback(): void
    {
        $order = $this->order(null, ['payment_method' => ShopOrder::METHOD_COD]);

        // Even with a reference on the row, which is what an administrator typing a
        // bank reference into Confirm Payment produces.
        $order->forceFill(['payment_reference' => 'pur_cod'])->save();

        $this->postWebhook($this->purchasePayload($order, 'pur_cod'))->assertOk();

        $order->refresh();

        $this->assertSame(ShopOrder::STATUS_PENDING_PAYMENT, $order->status);
        $this->assertNull($order->paid_at);
        $this->assertTrue($order->awaitsManualPayment());
        Mail::assertNothingSent();
    }

    public function test_a_failed_event_leaves_the_order_payable_and_writes_one_note(): void
    {
        $order = $this->order();
        $order->forceFill(['payment_reference' => self::PURCHASE])->save();

        $payload = $this->purchasePayload($order, self::PURCHASE, 'purchase.payment_failure', 'error');

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $order->refresh();

        // Still pending, so the buyer can retry from the same signed link.
        $this->assertSame(ShopOrder::STATUS_PENDING_PAYMENT, $order->status);
        $this->assertNull($order->paid_at);
        $this->assertTrue($order->awaitsGatewayPayment());

        // Two identical events, one note: CHIP decides how often it calls.
        $this->assertSame(1, $order->events()
            ->where('note', 'like', '%failed or cancelled payment%')
            ->count());
    }
}
