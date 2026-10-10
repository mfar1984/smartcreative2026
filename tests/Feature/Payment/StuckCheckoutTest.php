<?php

namespace Tests\Feature\Payment;

use App\Models\EventRegistration;
use App\Services\Payment\OpenCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A payer who pressed the wrong bank must not be locked out of paying.
 *
 * WHAT HAPPENED
 *
 * A registrant reached CHIP, pressed the wrong bank, pressed Back, and from then on
 * every route to payment handed him the same purchase — which CHIP had already closed
 * to new attempts. He saw "Payment is being processed" and nothing else, including
 * from the link in his confirmation email. The office had to send him a fresh
 * gate.chip-in.asia link by hand before he could pay.
 *
 * The rule treated `pending_execute` and `pending_charge` as states the payer could
 * go back to. They are the opposite: a purchase enters them once an attempt has been
 * STARTED at a bank.
 *
 * WHAT MUST NOT BE BROKEN WHILE FIXING IT
 *
 * The reuse exists because pressing Pay twice once opened two purchases, the
 * registration was left pointing at the wrong one, and a real RM 250.00 payment went
 * unmatched. So a pending attempt that might genuinely be live is left alone, and
 * only a stale one is replaced. Both halves are asserted here.
 *
 * THE CLOCK
 *
 * Every staleness assertion runs at 16:30 UTC, which is already the next day in
 * Asia/Kuching. This project has fixed UTC-versus-local faults four times, and a
 * window measured in minutes is exactly where a fifth would hide: a comparison done
 * on local dates rather than on instants passes at noon and fails at midnight.
 */
class StuckCheckoutTest extends PaymentTestCase
{
    use RefreshDatabase;

    /** The purchase the payer is already sitting on. */
    private const OPEN = 'b4d65a39-2503-474c-9a50-851af012bf16';

    /** The one CHIP hands back when a new checkout is opened. */
    private const FRESH = 'pur_fresh_1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureGateway();

        /*
         | 16:30 UTC on the 10th is 00:30 on the 11th in Kuching. Asserted rather than
         | asserted-in-a-comment, so the hour cannot be "tidied" into one where the
         | two agree and quietly stop testing anything.
         */
        Carbon::setTestNow(Carbon::parse('2026-11-10 16:30:00', 'UTC'));

        $this->assertNotSame(
            now()->toDateString(),
            now()->timezone('Asia/Kuching')->toDateString(),
            'The pinned hour must straddle the local date boundary.',
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Did this press open a purchase at CHIP? */
    private function assertPurchaseOpened(): void
    {
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/purchases/'));
    }

    private function assertNoPurchaseOpened(): void
    {
        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/purchases/'));
    }

    /* ---------------------------------------------------------------------
     | Nobody has committed to a bank: unchanged behaviour
     * ------------------------------------------------------------------ */

    public function test_a_created_purchase_is_handed_back_rather_than_duplicated(): void
    {
        $registration = $this->registration();
        $this->attempt($registration, self::OPEN);

        $this->fakeChip(self::OPEN, 'created', fresh: self::FRESH);

        $this->pressPay($registration)->assertRedirect(self::GATE . self::OPEN);

        $this->assertNoPurchaseOpened();
        $this->assertSame(1, $registration->checkouts()->count());
        $this->assertSame(self::OPEN, $registration->fresh()->payment_reference);
    }

    public function test_a_viewed_purchase_is_handed_back_rather_than_duplicated(): void
    {
        $registration = $this->registration();
        $this->attempt($registration, self::OPEN);

        $this->fakeChip(self::OPEN, 'viewed', fresh: self::FRESH);

        $this->pressPay($registration)->assertRedirect(self::GATE . self::OPEN);

        $this->assertNoPurchaseOpened();
        $this->assertSame(1, $registration->checkouts()->count());
    }

    public function test_an_attempt_whose_url_was_cleared_opens_a_fresh_purchase(): void
    {
        // What a corrected charge leaves behind: the purchase id is kept so a late
        // payment can still be matched, the page is not, because it quotes the old
        // figure. RegistrationTotalsRecalculator does this deliberately.
        $registration = $this->registration();
        $this->attempt($registration, self::OPEN)->forceFill(['checkout_url' => null])->save();

        $this->fakeChip(self::OPEN, 'created', fresh: self::FRESH);

        $this->pressPay($registration)->assertRedirect(self::GATE . self::FRESH);

        $this->assertPurchaseOpened();
    }

    /* ---------------------------------------------------------------------
     | An attempt is at a bank, and recent. The money half.
     * ------------------------------------------------------------------ */

    public function test_a_recent_attempt_at_a_bank_does_not_open_a_second_purchase(): void
    {
        $registration = $this->registration();
        $this->attempt($registration, self::OPEN, now()->subMinutes(10));

        // Three minutes in: logging into the bank, waiting for a TAC. He may well be
        // about to succeed, and a second purchase here is the RM 250.00 fault.
        $this->fakeChip(self::OPEN, 'pending_execute', now()->subMinutes(3), self::FRESH);

        $response = $this->pressPay($registration);

        $this->assertNoPurchaseOpened();
        $this->assertSame(1, $registration->checkouts()->count());
        $this->assertSame(self::OPEN, $registration->fresh()->payment_reference);

        // And he is not dumped on the wedged URL either.
        $this->assertStringNotContainsString(self::GATE, (string) $response->headers->get('Location'));
        $response->assertSessionHas('payment_in_progress');
    }

    public function test_a_recent_pending_charge_behaves_the_same_way(): void
    {
        $registration = $this->registration();
        $this->attempt($registration, self::OPEN, now()->subMinutes(10));

        $this->fakeChip(self::OPEN, 'pending_charge', now()->subMinutes(2), self::FRESH);

        $this->pressPay($registration)->assertSessionHas('payment_in_progress');

        $this->assertNoPurchaseOpened();
        $this->assertSame(1, $registration->checkouts()->count());
    }

    public function test_the_payer_is_told_what_is_happening_and_what_to_do(): void
    {
        $registration = $this->registration();
        $this->attempt($registration, self::OPEN, now()->subMinutes(10));

        $this->fakeChip(self::OPEN, 'pending_execute', now()->subMinutes(3), self::FRESH);

        $page = $this->followRedirects($this->pressPay($registration));

        $page->assertOk();
        $page->assertSee('A payment is already in progress');
        $page->assertSee('already in progress at the bank');

        // The two ways out, in words: finish it, or come back shortly.
        $page->assertSee('finish it in your banking app');
        $page->assertSee('press Pay again');
        $page->assertSee('Nothing is being charged twice.');

        // Named rather than hardcoded, so the sentence and the rule cannot disagree.
        $page->assertSee('wait about ' . OpenCheckout::STALE_AFTER_MINUTES . ' minutes');
    }

    public function test_an_attempt_still_inside_the_window_is_left_alone(): void
    {
        $registration = $this->registration();
        $this->attempt($registration, self::OPEN, now()->subMinutes(30));

        // One minute inside the window. The boundary is what the constant means.
        $this->fakeChip(
            self::OPEN,
            'pending_execute',
            now()->subMinutes(OpenCheckout::STALE_AFTER_MINUTES - 1),
            self::FRESH,
        );

        $this->pressPay($registration)->assertSessionHas('payment_in_progress');

        $this->assertNoPurchaseOpened();
    }

    /* ---------------------------------------------------------------------
     | An attempt is at a bank, and stale. The stranded-payer half.
     * ------------------------------------------------------------------ */

    public function test_a_stale_attempt_opens_a_fresh_purchase(): void
    {
        $registration = $this->registration();
        $this->attempt($registration, self::OPEN, now()->subHour());

        /*
         | Forty minutes ago, which in Kuching is on YESTERDAY'S date while now() is
         | on today's. Stale by any honest reading, and only by an honest one: a
         | comparison made on local date strings would call this attempt live.
         */
        $this->fakeChip(self::OPEN, 'pending_execute', now()->subMinutes(40), self::FRESH);

        $this->pressPay($registration)->assertRedirect(self::GATE . self::FRESH);

        $this->assertPurchaseOpened();
    }

    public function test_a_fresh_purchase_keeps_the_abandoned_one_on_record(): void
    {
        $registration = $this->registration();
        $this->attempt($registration, self::OPEN, now()->subHour());

        $this->fakeChip(self::OPEN, 'pending_execute', now()->subMinutes(40), self::FRESH);

        $this->pressPay($registration)->assertRedirect(self::GATE . self::FRESH);

        /*
         | Two rows, not one replaced. This history is what saved the books from the
         | RM 250.00 fault: it is how the webhook recognises a payment that arrives
         | against the earlier purchase, and CHIP has no cancel call for us to close
         | that purchase with.
         */
        $this->assertSame(2, $registration->checkouts()->count());

        $old = $registration->checkouts()->where('purchase_id', self::OPEN)->sole();

        $this->assertSame(self::GATE . self::OPEN, $old->checkout_url);
        $this->assertTrue($registration->checkouts()->where('purchase_id', self::FRESH)->exists());

        // The column follows the live attempt, as it always has.
        $this->assertSame(self::FRESH, $registration->fresh()->payment_reference);
    }

    public function test_a_stale_attempt_is_measured_from_the_bank_not_from_when_we_opened_it(): void
    {
        $registration = $this->registration();

        // Opened an hour ago, so our own row looks ancient.
        $this->attempt($registration, self::OPEN, now()->subHour());

        // But he only reached the bank two minutes ago, after sitting on the hosted
        // page. CHIP's status history is the figure that matters.
        $this->fakeChip(self::OPEN, 'pending_execute', now()->subMinutes(2), self::FRESH);

        $this->pressPay($registration)->assertSessionHas('payment_in_progress');

        $this->assertNoPurchaseOpened();
    }

    /* ---------------------------------------------------------------------
     | Finished with, exactly as before
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, array<int, string>>
     */
    public static function finishedStatuses(): array
    {
        return [
            'paid' => ['paid'],
            'settled' => ['settled'],
            'failed' => ['error'],
            'expired' => ['expired'],
            'cancelled' => ['cancelled'],
            'refunded' => ['refunded'],
        ];
    }

    #[DataProvider('finishedStatuses')]
    public function test_a_finished_purchase_is_not_reused(string $status): void
    {
        $registration = $this->registration();
        $this->attempt($registration, self::OPEN, now()->subMinutes(5));

        $this->fakeChip(self::OPEN, $status, now()->subMinutes(5), self::FRESH);

        $this->pressPay($registration)->assertRedirect(self::GATE . self::FRESH);

        $this->assertPurchaseOpened();
        $this->assertSame(2, $registration->checkouts()->count());
    }

    public function test_a_gateway_that_cannot_be_reached_still_opens_a_checkout(): void
    {
        $registration = $this->registration();
        $this->attempt($registration, self::OPEN, now()->subMinutes(5));

        // The worst case stays the old behaviour: a new checkout, not a payer stuck
        // on a page that cannot help them.
        Http::fake([
            'gate.chip-in.asia/api/v1/purchases/' => Http::response([
                'id' => self::FRESH,
                'checkout_url' => self::GATE . self::FRESH,
            ]),
            'gate.chip-in.asia/api/v1/purchases/*' => Http::response(['detail' => 'nope'], 500),
        ]);

        $this->pressPay($registration)->assertRedirect(self::GATE . self::FRESH);

        $this->assertPurchaseOpened();
    }

    public function test_an_entry_with_no_attempt_at_all_opens_one(): void
    {
        $registration = $this->registration();

        $this->fakeChipPurchase(self::FRESH);

        $this->pressPay($registration)->assertRedirect(self::GATE . self::FRESH);

        $this->assertSame(1, $registration->checkouts()->count());
        $this->assertSame(EventRegistration::PAYMENT_PENDING, $registration->fresh()->payment_status);
    }
}
