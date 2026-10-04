<?php

namespace Tests\Feature\Shop;

use App\Models\EventRegistration;
use App\Models\ShopOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

/**
 * A body that cannot be proven to come from CHIP writes nothing.
 *
 * The webhook is the only thing that marks a shop order paid, so it is also the only
 * thing worth forging. Anyone can post to it: there is no session, no CSRF token and no
 * login. The RSA signature over the exact bytes sent is the whole of the defence, and
 * this file is what proves it still runs for shop orders rather than only for
 * registrations.
 *
 * The last test is the regression guard on the other side of the change: the webhook
 * was extended, not rewritten, and the registration money path has to behave exactly as
 * it did before.
 */
class ShopWebhookBadSignatureTest extends ShopPaymentTestCase
{
    use RefreshDatabase;

    private const PURCHASE = 'pur_signature';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->gatewayShopSettings();
    }

    private function payableOrder(): ShopOrder
    {
        $order = $this->order();
        $order->forceFill(['payment_reference' => self::PURCHASE])->save();

        return $order->fresh();
    }

    public function test_a_forged_signature_is_rejected_and_nothing_changes(): void
    {
        $order = $this->payableOrder();

        $response = $this->postWebhook(
            $this->purchasePayload($order, self::PURCHASE),
            base64_encode('not-a-signature'),
        );

        $response->assertUnauthorized();

        $after = $order->fresh();

        $this->assertSame(ShopOrder::STATUS_PENDING_PAYMENT, $after->status);
        $this->assertNull($after->paid_at);
        $this->assertNull($after->paid_purchase_id);
        $this->assertNull($after->payment_synced_at);
        $this->assertEmpty($after->payment_details);
        Mail::assertNothingSent();
    }

    public function test_a_missing_signature_is_rejected_and_nothing_changes(): void
    {
        $order = $this->payableOrder();

        $body = json_encode($this->purchasePayload($order, self::PURCHASE));

        $response = $this->call(
            'POST',
            route('payments.chip.webhook'),
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $body,
        );

        $response->assertUnauthorized();

        $after = $order->fresh();

        $this->assertSame(ShopOrder::STATUS_PENDING_PAYMENT, $after->status);
        $this->assertNull($after->paid_at);
        Mail::assertNothingSent();
    }

    public function test_a_body_edited_after_signing_is_rejected(): void
    {
        $order = $this->payableOrder();

        $payload = $this->purchasePayload($order, self::PURCHASE);
        $body = json_encode($payload);

        openssl_sign($body, $raw, $this->webhookPrivateKey, OPENSSL_ALGO_SHA256);

        // Same signature, one field changed. The signature covers the exact bytes, so
        // this cannot pass.
        $payload['payment']['amount'] = 1;

        $response = $this->postWebhook($payload, base64_encode($raw));

        $response->assertUnauthorized();
        $this->assertSame(ShopOrder::STATUS_PENDING_PAYMENT, $order->fresh()->status);
    }

    public function test_the_registration_payment_path_is_unchanged(): void
    {
        $event = \App\Models\Event::create([
            'slug' => 'event-' . uniqid(),
            'title' => 'Open Day',
            'category' => 'Community',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => \App\Models\Event::STATUS_OPEN,
            'registration_mode' => 'individual',
            'fee' => 25,
            'seats_total' => 100,
        ]);

        $registration = EventRegistration::create([
            'event_id' => $event->id,
            'reference' => EventRegistration::nextReference(),
            'mode' => \App\Models\Event::MODE_INDIVIDUAL,
            'status' => EventRegistration::STATUS_PENDING,
            'payment_status' => EventRegistration::PAYMENT_PENDING,
            'payment_reference' => 'pur_registration',
            'registration_fee' => 25,
            'amount' => 25,
        ]);

        $body = json_encode([
            'event_type' => 'purchase.paid',
            'id' => 'pur_registration',
            'reference' => $registration->reference,
            'status' => 'paid',
            'purchase' => ['total' => 2500],
            'payment' => ['amount' => 2500],
        ]);

        openssl_sign($body, $raw, $this->webhookPrivateKey, OPENSSL_ALGO_SHA256);

        $this->call(
            'POST',
            route('payments.chip.webhook'),
            [],
            [],
            [],
            ['HTTP_X_SIGNATURE' => base64_encode($raw), 'CONTENT_TYPE' => 'application/json'],
            $body,
        )->assertOk();

        $after = $registration->fresh();

        // Registration matching runs first and unchanged, so this is settled by the
        // registration updater exactly as before. No shop order exists to confuse it.
        $this->assertSame(EventRegistration::PAYMENT_PAID, $after->payment_status);
        $this->assertSame(EventRegistration::STATUS_CONFIRMED, $after->status);
        $this->assertSame(0, ShopOrder::query()->count());
    }
}
