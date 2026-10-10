<?php

namespace Tests\Feature\Shop;

use App\Http\Controllers\Payment\ShopOrderPaymentController;
use App\Models\ShopOrder;
use App\Models\ShopOrderCheckout;
use App\Services\Payment\OpenCheckout;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * The shop had the same fault, with a different noun.
 *
 * ShopCheckoutStarter carried its own copy of the four reusable CHIP states —
 * created, viewed, pending_execute, pending_charge — so a buyer who pressed the wrong
 * bank was handed the same wedged purchase every time they pressed Pay, exactly as a
 * registrant was. The rules now live in OpenCheckout, shared by both, and this file
 * is the half of that which proves the shop goes through it.
 *
 * The clock is pinned at 16:30 UTC, which is already the next day in Asia/Kuching,
 * for the same reason it is on the registration side: a window measured in minutes is
 * where a UTC-versus-local comparison hides.
 */
class ShopCheckoutReuseTest extends ShopPaymentTestCase
{
    use RefreshDatabase;

    private const GATE = 'https://gate.chip-in.asia/p/';

    /** The purchase the buyer is already sitting on. */
    private const OPEN = 'pur_open_1';

    /** The one CHIP hands back when a new checkout is opened. */
    private const FRESH = 'pur_fresh_1';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->gatewayShopSettings();

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

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    /** One checkout already opened at the gateway, written the way markPending() does. */
    private function attempt(ShopOrder $order, string $purchaseId, ?CarbonInterface $openedAt = null): ShopOrderCheckout
    {
        $checkout = $order->checkouts()->create([
            'purchase_id' => $purchaseId,
            'checkout_url' => self::GATE . $purchaseId,
            'gateway' => 'chip',
            'opened_at' => $openedAt ?? now(),
        ]);

        $order->forceFill(['payment_reference' => $purchaseId])->save();

        return $checkout;
    }

    /**
     * CHIP reports self::OPEN as $status, and accepts any new purchase as self::FRESH.
     *
     * @param  CarbonInterface|null  $statusAt  when the purchase entered that status
     */
    private function fakeChip(string $status, ?CarbonInterface $statusAt = null): void
    {
        $at = ($statusAt ?? now())->copy();
        $created = $at->copy()->subMinutes(2);

        Http::fake([
            // No wildcard, so only the exact collection URL matches and a lookup of
            // one purchase falls through to the stub below.
            'gate.chip-in.asia/api/v1/purchases/' => Http::response([
                'id' => self::FRESH,
                'checkout_url' => self::GATE . self::FRESH,
            ]),

            'gate.chip-in.asia/api/v1/purchases/*' => Http::response([
                'id' => self::OPEN,
                'status' => $status,
                'checkout_url' => self::GATE . self::OPEN,
                'created_on' => $created->timestamp,
                'updated_on' => $at->timestamp,
                'purchase' => ['total' => 2500, 'currency' => 'MYR'],
                'status_history' => [
                    ['status' => 'created', 'timestamp' => $created->timestamp],
                    ['status' => $status, 'timestamp' => $at->timestamp],
                ],
            ]),
        ]);
    }

    private function pressPay(ShopOrder $order)
    {
        return $this->post(ShopOrderPaymentController::payUrl($order));
    }

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
     | The same three verdicts
     * ------------------------------------------------------------------ */

    public function test_a_created_purchase_is_handed_back_rather_than_duplicated(): void
    {
        $order = $this->order();
        $this->attempt($order, self::OPEN);

        $this->fakeChip('created');

        $this->pressPay($order)->assertRedirect(self::GATE . self::OPEN);

        $this->assertNoPurchaseOpened();
        $this->assertSame(1, $order->checkouts()->count());
    }

    public function test_a_recent_attempt_at_a_bank_does_not_open_a_second_purchase(): void
    {
        $order = $this->order();
        $this->attempt($order, self::OPEN, now()->subMinutes(10));

        $this->fakeChip('pending_execute', now()->subMinutes(3));

        $response = $this->pressPay($order);

        $this->assertNoPurchaseOpened();
        $this->assertSame(1, $order->checkouts()->count());
        $this->assertSame(self::OPEN, $order->fresh()->payment_reference);

        // Not sent to the wedged URL, and told why.
        $this->assertStringNotContainsString(self::GATE, (string) $response->headers->get('Location'));
        $response->assertSessionHas('payment_in_progress');
    }

    public function test_the_buyer_is_told_what_is_happening_on_their_own_page(): void
    {
        $order = $this->order();
        $this->attempt($order, self::OPEN, now()->subMinutes(10));

        $this->fakeChip('pending_charge', now()->subMinutes(4));

        $page = $this->followRedirects($this->pressPay($order));

        $page->assertOk();
        $page->assertSee('already in progress at the bank');
        $page->assertSee('wait about ' . OpenCheckout::STALE_AFTER_MINUTES . ' minutes');
        $page->assertSee('Nothing is being charged twice.');

        // The Pay button is still there for when they come back.
        $page->assertSee('Pay ' . $order->grandTotalLabel() . ' now');
    }

    public function test_a_stale_attempt_opens_a_fresh_purchase_and_keeps_the_old_row(): void
    {
        $order = $this->order();
        $this->attempt($order, self::OPEN, now()->subHour());

        // Forty minutes ago, which in Kuching falls on yesterday's date while now()
        // is on today's.
        $this->fakeChip('pending_execute', now()->subMinutes(40));

        $this->pressPay($order)->assertRedirect(self::GATE . self::FRESH);

        $this->assertPurchaseOpened();
        $this->assertSame(2, $order->checkouts()->count());
        $this->assertSame(
            self::GATE . self::OPEN,
            $order->checkouts()->where('purchase_id', self::OPEN)->sole()->checkout_url,
        );
        $this->assertSame(self::FRESH, $order->fresh()->payment_reference);
    }

    public function test_a_settled_purchase_is_not_reused(): void
    {
        $order = $this->order();
        $this->attempt($order, self::OPEN, now()->subMinutes(5));

        $this->fakeChip('expired', now()->subMinutes(5));

        $this->pressPay($order)->assertRedirect(self::GATE . self::FRESH);

        $this->assertPurchaseOpened();
    }

    /* ---------------------------------------------------------------------
     | And the same isolation
     * ------------------------------------------------------------------ */

    public function test_a_failing_side_effect_still_sends_the_buyer_to_the_gateway(): void
    {
        $order = $this->order();

        $this->fakeChipPurchase(self::FRESH);

        // The last thing markPending() does is write an activity line. Before it was
        // wrapped, that failing gave the buyer a server error instead of the gateway
        // while a good checkout sat waiting at CHIP.
        Schema::drop('activity_logs');

        $this->pressPay($order)->assertRedirect(self::GATE . self::FRESH);

        // The rows written before the failure are still there, so a callback can find
        // this order by the gateway's id.
        $this->assertSame(1, $order->checkouts()->count());
        $this->assertSame(self::FRESH, $order->fresh()->payment_reference);
    }
}
