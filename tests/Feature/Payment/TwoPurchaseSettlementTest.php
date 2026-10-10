<?php

namespace Tests\Feature\Payment;

use App\Models\EventRegistration;
use App\Models\EventRegistrationPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Two purchases, one registration: each settles only itself.
 *
 * THE RM 250.00 FAULT, AND WHY THIS FILE EXISTS
 *
 * `payment_reference` holds ONE attempt. A payer who presses Pay twice creates a
 * second purchase, the column moves to the second, and when the first is the one that
 * gets paid its webhook arrives describing a purchase nothing points at any more. It
 * happened: a real payment went unmatched while its registration read "failed".
 *
 * A stale attempt can now be replaced with a fresh purchase, which means two live
 * purchases for one entry is a state this site can reach on purpose. So the question
 * "does a payment land on the purchase that took it" stops being theoretical and
 * becomes the whole ballgame. It is asserted in both directions here, because a
 * webhook that settled "the latest checkout" rather than the purchase it names would
 * pass one of them and fail the other.
 */
class TwoPurchaseSettlementTest extends PaymentTestCase
{
    use RefreshDatabase;

    /** The abandoned attempt: opened first, left at a bank. */
    private const FIRST = 'b4d65a39-2503-474c-9a50-851af012bf16';

    /** The one opened after it went stale, and the one the column points at. */
    private const SECOND = 'd06279af-5d27-4e3c-af16-02ab1de79923';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureGateway();
    }

    /**
     * An entry with both attempts on record, pointing at the second.
     *
     * Exactly what pressing Pay after a stale attempt leaves behind.
     */
    private function withTwoAttempts(): EventRegistration
    {
        $registration = $this->registration();

        $this->attempt($registration, self::FIRST, now()->subHour());
        $this->attempt($registration, self::SECOND, now()->subMinutes(2));

        $this->assertSame(2, $registration->checkouts()->count());
        $this->assertSame(self::SECOND, $registration->fresh()->payment_reference);

        return $registration->fresh();
    }

    /** The one gateway receipt on the ledger. */
    private function receipt(EventRegistration $registration): EventRegistrationPayment
    {
        return $registration->fresh()->payments()
            ->where('source', EventRegistrationPayment::SOURCE_GATEWAY)
            ->sole();
    }

    public function test_a_webhook_naming_the_first_purchase_settles_the_first(): void
    {
        $registration = $this->withTwoAttempts();

        $this->postWebhook($this->webhookPayload($registration, self::FIRST, 10000))->assertOk();

        // Credited to the purchase that took the money, by its own id.
        $this->assertSame(self::FIRST, $this->receipt($registration)->reference);
        $this->assertSame('100.00', $this->receipt($registration)->amount);

        // Nothing against the other one.
        $this->assertSame(0, $registration->fresh()->payments()->where('reference', self::SECOND)->count());

        // And the entry now points at the purchase that settled it, which is what a
        // refund would have to target.
        $after = $registration->fresh();

        $this->assertSame(self::FIRST, $after->payment_reference);
        $this->assertSame(EventRegistration::PAYMENT_PAID, $after->payment_status);
        $this->assertSame(EventRegistration::STATUS_CONFIRMED, $after->status);
        $this->assertSame(100.0, $after->amountPaid());
    }

    public function test_a_webhook_naming_the_second_purchase_settles_the_second(): void
    {
        $registration = $this->withTwoAttempts();

        $this->postWebhook($this->webhookPayload($registration, self::SECOND, 10000))->assertOk();

        $this->assertSame(self::SECOND, $this->receipt($registration)->reference);
        $this->assertSame(0, $registration->fresh()->payments()->where('reference', self::FIRST)->count());

        $after = $registration->fresh();

        $this->assertSame(self::SECOND, $after->payment_reference);
        $this->assertSame(EventRegistration::PAYMENT_PAID, $after->payment_status);
        $this->assertSame(100.0, $after->amountPaid());
    }

    public function test_the_abandoned_purchase_is_still_on_record_after_the_other_settles(): void
    {
        $registration = $this->withTwoAttempts();

        $this->postWebhook($this->webhookPayload($registration, self::FIRST, 10000))->assertOk();

        // Both attempts are kept. The history is how the webhook found the first one
        // at all, and CHIP has no cancel call for closing the other.
        $this->assertSame(2, $registration->checkouts()->count());
        $this->assertTrue($registration->checkouts()->where('purchase_id', self::SECOND)->exists());
    }

    public function test_a_replayed_webhook_for_the_same_purchase_adds_nothing(): void
    {
        $registration = $this->withTwoAttempts();

        $this->postWebhook($this->webhookPayload($registration, self::FIRST, 10000))->assertOk();
        $this->postWebhook($this->webhookPayload($registration, self::FIRST, 10000))->assertOk();

        $this->assertSame(1, $registration->fresh()->payments()->count());
        $this->assertSame(100.0, $registration->fresh()->amountPaid());
    }
}
