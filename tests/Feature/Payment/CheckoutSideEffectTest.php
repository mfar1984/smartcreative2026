<?php

namespace Tests\Feature\Payment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * A side effect must never cost somebody their payment.
 *
 * THE SECOND SYMPTOM THE OWNER REPORTED
 *
 * Some registrants got a blank page on the way to payment while others on the same
 * event were fine, and sending them the gate.chip-in.asia link by hand let them pay
 * normally. That the gate link WORKS is the clue: the purchase was created, so the
 * gateway call, the payload and the amount were all correct. Whatever failed, failed
 * AFTER the purchase existed and BEFORE the redirect was returned.
 *
 * Everything in that window is bookkeeping — the checkout row, the reference on the
 * entry, an activity line — and unwrapped, any one of those failing took the whole
 * response with it: a 500 where a redirect should have been, which is what these
 * tests measure. The payer got nothing while a perfectly good checkout sat waiting at
 * CHIP.
 *
 * WHAT THIS PROVES, AND WHAT IT DOES NOT
 *
 * The thrower here is a missing activity_logs table, which is a stand-in: no
 * deterministic cause could be reproduced from this end, and guessing at one would
 * have been worse than isolating the window. What is asserted is the property that
 * matters whatever the cause — the purchase exists, so the payer reaches it, and the
 * failure is recorded instead of being paid for by the person trying to give us
 * money.
 */
class CheckoutSideEffectTest extends PaymentTestCase
{
    use RefreshDatabase;

    private const FRESH = 'pur_after_failure';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureGateway();
    }

    /**
     * Break the last thing markPending() does.
     *
     * AdminLogger::activity() writes to this table on every opened checkout, and it
     * is the final statement of the method, so the rows written before it are still
     * there to assert on. DDL inside the test transaction, which SQLite rolls back
     * with everything else.
     */
    private function breakTheActivityLog(): void
    {
        Schema::drop('activity_logs');
    }

    public function test_a_failing_side_effect_still_sends_the_payer_to_the_gateway(): void
    {
        $registration = $this->registration();

        $this->fakeChipPurchase(self::FRESH);
        $this->breakTheActivityLog();

        // THE regression. Before this was wrapped, the response here was a 500 with
        // nothing in it and the payer had no way to the purchase at all.
        $this->pressPay($registration)->assertRedirect(self::GATE . self::FRESH);
    }

    public function test_the_bookkeeping_written_before_the_failure_is_kept(): void
    {
        $registration = $this->registration();

        $this->fakeChipPurchase(self::FRESH);
        $this->breakTheActivityLog();

        $this->pressPay($registration)->assertRedirect(self::GATE . self::FRESH);

        // The attempt row and the reference both land before the log line, so a
        // webhook can still find this entry by the gateway's id.
        $this->assertSame(1, $registration->checkouts()->count());
        $this->assertSame(self::FRESH, $registration->checkouts()->sole()->purchase_id);
        $this->assertSame(self::FRESH, $registration->fresh()->payment_reference);
    }

    public function test_the_failure_is_recorded_with_the_purchase_id(): void
    {
        $registration = $this->registration();

        $this->fakeChipPurchase(self::FRESH);
        $this->breakTheActivityLog();

        Log::spy();

        $this->pressPay($registration)->assertRedirect(self::GATE . self::FRESH);

        /*
         | Swallowed, but never silently. The purchase id is what a reconciliation
         | needs: money can arrive against a purchase this site failed to record, and
         | somebody has to be able to find it.
         */
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'could not be recorded')
                && ($context['purchase_id'] ?? null) === self::FRESH
                && ($context['reference'] ?? null) === $registration->reference)
            ->once();
    }
}
