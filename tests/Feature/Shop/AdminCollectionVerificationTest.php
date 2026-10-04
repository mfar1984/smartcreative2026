<?php

namespace Tests\Feature\Shop;

use App\Models\ActivityLog;
use App\Models\CollectionHandover;
use App\Models\CollectionVerification;
use App\Models\Setting;
use App\Models\ShopOrder;
use App\Models\ShopProduct;
use App\Services\Collection\CollectionVerifier;
use App\Services\ShopOrderWriter;
use App\Support\SmsSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Proving who took the goods.
 *
 * The owner's objection to a free-text note was the right one: it is optional, it is
 * unstructured, and it proves nothing when a shirt goes missing and nobody admits to
 * having collected it. So a third party now reads a texted code back to the counter,
 * and the record says whether they did.
 *
 * Nothing in here sends a real message. Http::fake() stands in for Infobip and
 * Http::preventStrayRequests() turns any unfaked outbound call into a failure, so a
 * future change that bypasses the gateway cannot quietly start texting real people
 * from a test run. Mail::fake() for the same reason on the mail side.
 *
 * The code itself is read out of the intercepted gateway payload and never from the
 * application: there is no method anywhere that will hand it back, which is half the
 * point of the design and is asserted on directly below.
 */
class AdminCollectionVerificationTest extends ShopPaymentTestCase
{
    use RefreshDatabase;

    /** The permission the handover has always been gated on. */
    private const CAN_UPDATE = ['admin.access', 'shop.orders.view', 'shop.orders.update'];

    private const READ_ONLY = ['admin.access', 'shop.orders.view'];

    private const CAN_SETTINGS = ['admin.access', 'settings.integration.view', 'settings.integration.update'];

    private const COLLECTOR = [
        'collector' => CollectionHandover::KIND_OTHER,
        'collector_name' => 'Siti Nurhaliza',
        'collector_ic' => '880202105566',
        'collector_phone' => '0178591411',
    ];

    /** Where a captured log is written, so it can be deleted again. */
    private ?string $logPath = null;

    /** Whether the stand-in gateway refuses. Flipped by fakeInfobipFailure(). */
    private bool $gatewayRefuses = false;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::preventStrayRequests();
        $this->gatewayShopSettings();
        $this->infobipSettings();
        $this->fakeInfobip();
    }

    protected function tearDown(): void
    {
        if ($this->logPath !== null && is_file($this->logPath)) {
            @unlink($this->logPath);
        }

        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    /** A complete Infobip profile. Credentials that exist and reach nothing. */
    private function infobipSettings(): void
    {
        Setting::write('integration.sms.enabled', '1', 'integration.sms');
        Setting::write('integration.sms.provider', SmsSettings::PROVIDER_INFOBIP, 'integration.sms');
        Setting::write('integration.sms.sender_id', '62033', 'integration.sms');
        Setting::write('integration.sms.base_url', 'fake.api.infobip.test', 'integration.sms');
        Setting::write('integration.sms.api_key', 'not-a-real-key-' . uniqid(), 'integration.sms');
    }

    /**
     * The stand-in gateway.
     *
     * One closure consulted per request rather than two calls to Http::fake(), because
     * fake() adds to the stub list and the first match wins — so a later call cannot
     * replace an earlier stub, and a test asking for a failure would have been handed
     * the success set up in setUp().
     */
    private function fakeInfobip(): void
    {
        $this->gatewayRefuses = false;

        Http::fake(function () {
            if ($this->gatewayRefuses) {
                return Http::response(
                    ['requestError' => ['serviceException' => ['text' => 'Invalid login details']]],
                    401,
                );
            }

            return Http::response([
                'messages' => [[
                    'messageId' => 'msg-' . uniqid(),
                    'status' => ['groupName' => 'PENDING', 'description' => 'Message sent to next instance'],
                ]],
            ]);
        });
    }

    /** What a dead gateway looks like from here. */
    private function fakeInfobipFailure(): void
    {
        $this->gatewayRefuses = true;
    }

    private function setting(string $key, string $value): void
    {
        Setting::write('integration.sms.' . $key, $value, 'integration.sms');
    }

    /** An offline order settled the way the gateway settles one. */
    private function paidOfflineOrder(?ShopProduct $product = null): ShopOrder
    {
        $order = $this->order($product);

        app(ShopOrderWriter::class)->moveTo(
            $order,
            ShopOrder::STATUS_PAID,
            'Paid through chip webhook (Card or online banking).',
        );

        return $order->fresh();
    }

    private function admin()
    {
        return $this->userWith(self::CAN_UPDATE);
    }

    /** Ask for a code the way the dialog's Send Code button does. */
    private function sendCode(ShopOrder $order, array $overrides = [], array $permissions = self::CAN_UPDATE)
    {
        // $overrides on the left, because array + keeps the left-hand keys.
        return $this->actingAs($this->userWith($permissions))
            ->postJson(route('admin.shop.orders.collection-code', $order), $overrides + [
                'collector_name' => self::COLLECTOR['collector_name'],
                'collector_ic' => self::COLLECTOR['collector_ic'],
                'collector_phone' => self::COLLECTOR['collector_phone'],
            ]);
    }

    private function confirm(ShopOrder $order, array $body = [], array $permissions = self::CAN_UPDATE)
    {
        return $this->actingAs($this->userWith($permissions))
            ->from(route('admin.shop.orders', ['tab' => ShopOrder::FULFILMENT_OFFLINE]))
            ->post(route('admin.shop.orders.collect', $order), $body);
    }

    /**
     * The code out of the last message handed to the gateway.
     *
     * Read from the intercepted HTTP payload on purpose. Nothing in the application
     * exposes it: not the service's return value, not the model, not the response.
     * Taking it from the wire is the only way a test can know it, which is exactly
     * the property being relied on everywhere else in this file.
     */
    private function sentCode(): string
    {
        $text = $this->lastSentText();

        $this->assertMatchesRegularExpression('/^\d{6} /', $text, 'The message did not start with a six digit code.');

        return substr($text, 0, 6);
    }

    /** The whole text of the last message handed to the gateway. */
    private function lastSentText(): string
    {
        $recorded = Http::recorded();

        $this->assertNotEmpty($recorded, 'No message was handed to the gateway.');

        $last = $recorded[count($recorded) - 1];

        return (string) data_get($last[0]->data(), 'messages.0.text', '');
    }

    /** How many messages have been handed to the gateway so far. */
    private function messagesSent(): int
    {
        return count(Http::recorded());
    }

    /**
     * Point the logger at a throwaway file so its contents can be read back.
     *
     * In sys_get_temp_dir rather than storage/logs: nothing in a test run belongs in
     * the application's own log directory, and tearDown deletes it either way.
     */
    private function captureLog(): string
    {
        $this->logPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sc-collection-' . uniqid() . '.log';

        config([
            'logging.default' => 'single',
            'logging.channels.single.path' => $this->logPath,
            'logging.channels.single.level' => 'debug',
        ]);

        app()->forgetInstance('log');
        Log::clearResolvedInstances();

        return $this->logPath;
    }

    private function loggedText(): string
    {
        return $this->logPath !== null && is_file($this->logPath)
            ? (string) file_get_contents($this->logPath)
            : '';
    }

    /**
     * The money and stock figures on an order and its products, for comparing.
     *
     * @return array<string, mixed>
     */
    private function figures(ShopOrder $order): array
    {
        $order = $order->fresh('items');

        return [
            'items_total' => (string) $order->items_total,
            'shipping_total' => (string) $order->shipping_total,
            'grand_total' => (string) $order->grand_total,
            'refunded_amount' => (string) $order->refunded_amount,
            'paid_at' => (string) $order->paid_at,
            'payment_method' => $order->payment_method,
            'payment_reference' => $order->payment_reference,
            'stock_taken' => ShopProduct::query()->orderBy('id')->pluck('stock_taken')->all(),
            'stock_quantity' => ShopProduct::query()->orderBy('id')->pluck('stock_quantity')->all(),
        ];
    }

    /* ---------------------------------------------------------------------
     | The buyer: nothing changes
     * ------------------------------------------------------------------ */

    public function test_the_buyer_collecting_their_own_order_needs_no_code(): void
    {
        $order = $this->paidOfflineOrder();
        $admin = $this->admin();
        $before = $this->figures($order);

        $response = $this->actingAs($admin)
            ->from(route('admin.shop.orders', ['tab' => ShopOrder::FULFILMENT_OFFLINE]))
            ->post(route('admin.shop.orders.collect', $order), ['note' => 'Picked up by her brother.']);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $response->assertSessionHasNoErrors();

        $after = $order->fresh();

        $this->assertSame(ShopOrder::STATUS_DELIVERED, $after->status);
        $this->assertNotNull($after->delivered_at);

        // Word for word what it has always written, note included.
        $event = $after->events()->reorder()->latest('id')->first();
        $this->assertSame('Picked up by her brother.', $event->note);
        $this->assertSame($admin->id, $event->user_id);

        // Not one message, and not one code row.
        $this->assertSame(0, $this->messagesSent());
        $this->assertSame(0, CollectionVerification::query()->count());

        // And the structured record says it was the buyer, carrying the order's own
        // snapshot of who that is.
        $handover = $after->handover;

        $this->assertNotNull($handover);
        $this->assertTrue($handover->byBuyer());
        $this->assertTrue($handover->isAssured());
        $this->assertFalse($handover->wasOverridden());
        $this->assertSame($order->customer_name, $handover->collector_name);
        $this->assertSame($order->identity_card, $handover->collector_ic);
        $this->assertSame($admin->logLabel(), $handover->confirmed_by_label);

        $this->assertSame($before, $this->figures($order));
    }

    public function test_the_buyer_default_applies_when_no_collector_is_posted(): void
    {
        $order = $this->paidOfflineOrder();

        $this->confirm($order)->assertSessionHas('status');

        $this->assertSame(
            CollectionHandover::KIND_BUYER,
            $order->fresh()->handover->collector_kind,
        );
    }

    /* ---------------------------------------------------------------------
     | Somebody else: a code or nothing
     * ------------------------------------------------------------------ */

    public function test_a_third_party_collection_without_a_code_is_refused(): void
    {
        $order = $this->paidOfflineOrder();
        $before = $this->figures($order);

        $this->confirm($order, self::COLLECTOR)->assertSessionHasErrors('code');

        $after = $order->fresh();

        $this->assertSame(ShopOrder::STATUS_PAID, $after->status);
        $this->assertNull($after->delivered_at);
        $this->assertNull($after->handover);
        $this->assertSame(0, CollectionHandover::query()->count());
        $this->assertSame($before, $this->figures($order));
    }

    public function test_a_third_party_collection_is_refused_without_the_collectors_details(): void
    {
        $order = $this->paidOfflineOrder();

        $this->confirm($order, ['collector' => CollectionHandover::KIND_OTHER])
            ->assertSessionHasErrors(['collector_name', 'collector_ic', 'collector_phone']);

        $this->assertSame(ShopOrder::STATUS_PAID, $order->fresh()->status);
    }

    public function test_the_happy_path_issues_one_code_and_completes_on_it(): void
    {
        $order = $this->paidOfflineOrder();
        $before = $this->figures($order);

        $this->sendCode($order)->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(1, $this->messagesSent());
        $this->assertSame(1, CollectionVerification::query()->count());

        $code = $this->sentCode();

        $response = $this->confirm($order, self::COLLECTOR + ['code' => $code, 'note' => 'Wife, same address.']);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status');

        $after = $order->fresh();

        $this->assertSame(ShopOrder::STATUS_DELIVERED, $after->status);
        $this->assertNotNull($after->delivered_at);

        $handover = $after->handover;

        $this->assertNotNull($handover);
        $this->assertTrue($handover->byThirdParty());
        $this->assertTrue($handover->isVerified());
        $this->assertFalse($handover->wasOverridden());
        $this->assertSame(self::COLLECTOR['collector_name'], $handover->collector_name);
        $this->assertSame(self::COLLECTOR['collector_ic'], $handover->collector_ic);
        $this->assertSame(self::COLLECTOR['collector_phone'], $handover->collector_phone);
        $this->assertNotNull($handover->collection_verification_id);

        // The history says who took it and that a code backed it up.
        $note = $after->events()->reorder()->latest('id')->first()->note;

        $this->assertStringContainsString(self::COLLECTOR['collector_name'], $note);
        $this->assertStringContainsString('SMS code verified', $note);
        $this->assertStringContainsString('Wife, same address.', $note);

        // The code is spent, and recorded as such against the collection.
        $verification = CollectionVerification::query()->latest('id')->first();

        $this->assertNotNull($verification->verified_at);
        $this->assertNotNull($verification->burned_at);
        $this->assertSame(ShopOrder::class, $verification->verifiable_type);
        $this->assertSame($order->id, (int) $verification->verifiable_id);

        // Every attempt is on the record, the successful one included.
        $this->assertSame(1, $verification->attemptLog()->count());
        $this->assertSame('verified', $verification->attemptLog()->first()->outcome);

        $this->assertSame($before, $this->figures($order));
    }

    public function test_a_wrong_code_is_refused_and_counts_against_the_attempt_limit(): void
    {
        $order = $this->paidOfflineOrder();

        $this->sendCode($order)->assertOk();

        $code = $this->sentCode();
        $wrong = str_pad((string) ((((int) $code) + 1) % 1000000), 6, '0', STR_PAD_LEFT);

        $this->confirm($order, self::COLLECTOR + ['code' => $wrong])
            ->assertSessionHasErrors('code');

        $this->assertSame(ShopOrder::STATUS_PAID, $order->fresh()->status);

        $verification = CollectionVerification::query()->latest('id')->first();

        $this->assertSame(1, $verification->attempts);
        $this->assertNull($verification->burned_at);
        $this->assertSame('wrong', $verification->attemptLog()->latest('id')->first()->outcome);

        // And the right one still works afterwards.
        $this->confirm($order, self::COLLECTOR + ['code' => $code])->assertSessionHas('status');

        $this->assertSame(ShopOrder::STATUS_DELIVERED, $order->fresh()->status);
    }

    public function test_an_empty_code_does_not_spend_an_attempt(): void
    {
        $order = $this->paidOfflineOrder();

        $this->sendCode($order)->assertOk();

        $this->confirm($order, self::COLLECTOR + ['code' => ''])->assertSessionHasErrors('code');

        $this->assertSame(0, CollectionVerification::query()->latest('id')->first()->attempts);
    }

    public function test_the_configured_attempt_limit_burns_the_code(): void
    {
        $this->setting('collection_code_max_attempts', '2');

        $order = $this->paidOfflineOrder();

        $this->sendCode($order)->assertOk();

        $code = $this->sentCode();

        // The setting drives it: the row carries the limit as it stood on issue.
        $this->assertSame(2, CollectionVerification::query()->latest('id')->first()->max_attempts);

        foreach (['000000', '999999'] as $guess) {
            $this->confirm($order, self::COLLECTOR + ['code' => $guess === $code ? '111111' : $guess])
                ->assertSessionHasErrors('code');
        }

        $verification = CollectionVerification::query()->latest('id')->first();

        $this->assertSame(2, $verification->attempts);
        $this->assertNotNull($verification->burned_at);

        // Burned means burned. The correct code is no longer a key.
        $this->confirm($order, self::COLLECTOR + ['code' => $code])->assertSessionHasErrors('code');

        $after = $order->fresh();

        $this->assertSame(ShopOrder::STATUS_PAID, $after->status);
        $this->assertNull($after->delivered_at);
        $this->assertNull($after->handover);
    }

    public function test_an_expired_code_is_refused(): void
    {
        $this->setting('collection_code_expiry_minutes', '10');

        $order = $this->paidOfflineOrder();

        $this->sendCode($order)->assertOk();

        $code = $this->sentCode();

        $this->assertEqualsWithDelta(
            now()->addMinutes(10)->timestamp,
            CollectionVerification::query()->latest('id')->first()->expires_at->timestamp,
            60,
        );

        $this->travel(11)->minutes();

        $this->confirm($order, self::COLLECTOR + ['code' => $code])->assertSessionHasErrors('code');

        $after = $order->fresh();

        $this->assertSame(ShopOrder::STATUS_PAID, $after->status);
        $this->assertNull($after->handover);
        $this->assertNotNull(CollectionVerification::query()->latest('id')->first()->burned_at);
    }

    public function test_a_code_issued_for_one_order_cannot_complete_another(): void
    {
        $product = $this->offlineProduct();
        $orderA = $this->paidOfflineOrder($product);
        $orderB = $this->paidOfflineOrder($product);

        $this->sendCode($orderA)->assertOk();

        $code = $this->sentCode();

        // Same collector, same number, right code. Wrong order.
        $this->confirm($orderB, self::COLLECTOR + ['code' => $code])->assertSessionHasErrors('code');

        $this->assertSame(ShopOrder::STATUS_PAID, $orderB->fresh()->status);
        $this->assertNull($orderB->fresh()->handover);

        // And it is still good for the order it was issued for.
        $this->confirm($orderA, self::COLLECTOR + ['code' => $code])->assertSessionHas('status');

        $this->assertSame(ShopOrder::STATUS_DELIVERED, $orderA->fresh()->status);
    }

    public function test_a_code_is_single_use(): void
    {
        $product = $this->offlineProduct();
        $orderA = $this->paidOfflineOrder($product);
        $orderB = $this->paidOfflineOrder($product);

        $this->sendCode($orderA)->assertOk();

        $code = $this->sentCode();

        $this->confirm($orderA, self::COLLECTOR + ['code' => $code])->assertSessionHas('status');

        // The same digits again, on a different order this time, because the first
        // one is already collected and would be refused for that reason instead.
        $this->confirm($orderB, self::COLLECTOR + ['code' => $code])->assertSessionHasErrors('code');

        $this->assertSame(ShopOrder::STATUS_PAID, $orderB->fresh()->status);
    }

    public function test_the_record_phone_must_match_the_number_the_code_went_to(): void
    {
        $order = $this->paidOfflineOrder();

        $this->sendCode($order)->assertOk();

        $code = $this->sentCode();

        // Left operand first: array + keeps the left-hand keys, so the replacement
        // number has to be on that side or it would be silently ignored.
        $response = $this->confirm($order, [
            'collector_phone' => '0199998888',
            'code' => $code,
        ] + self::COLLECTOR);

        $response->assertSessionHasErrors('collector_phone');

        $this->assertSame(ShopOrder::STATUS_PAID, $order->fresh()->status);

        // Refused before verify(), so the code is untouched and still usable.
        $verification = CollectionVerification::query()->latest('id')->first();

        $this->assertSame(0, $verification->attempts);
        $this->assertNull($verification->burned_at);
    }

    /* ---------------------------------------------------------------------
     | The cooldown
     * ------------------------------------------------------------------ */

    public function test_a_resend_inside_the_cooldown_is_refused_and_allowed_outside_it(): void
    {
        $this->setting('collection_code_cooldown_minutes', '2');

        $order = $this->paidOfflineOrder();

        $this->sendCode($order)->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(1, $this->messagesSent());

        $second = $this->sendCode($order);

        $second->assertStatus(422);
        $second->assertJson(['ok' => false]);

        // Refused by us, not by the gateway: nothing went out.
        $this->assertSame(1, $this->messagesSent());
        $this->assertSame(1, CollectionVerification::query()->count());

        $this->travel(3)->minutes();

        $this->sendCode($order)->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(2, $this->messagesSent());
        $this->assertSame(2, CollectionVerification::query()->count());

        // And the newer code is the only live one: the older is burned on issue, so
        // "the code" is never two things at once.
        $this->assertSame(1, CollectionVerification::query()->whereNull('burned_at')->count());
    }

    public function test_the_cooldown_setting_drives_the_window(): void
    {
        $this->setting('collection_code_cooldown_minutes', '30');

        $order = $this->paidOfflineOrder();

        $this->sendCode($order)->assertOk();

        // Comfortably outside a two minute cooldown, well inside a thirty minute one.
        $this->travel(5)->minutes();

        $this->sendCode($order)->assertStatus(422);

        $this->travel(26)->minutes();

        $this->sendCode($order)->assertOk();
    }

    /* ---------------------------------------------------------------------
     | Nothing readable, nothing leaked
     * ------------------------------------------------------------------ */

    public function test_the_stored_code_is_not_plaintext(): void
    {
        $order = $this->paidOfflineOrder();

        $this->sendCode($order)->assertOk();

        $code = $this->sentCode();
        $row = (array) \Illuminate\Support\Facades\DB::table('collection_verifications')->latest('id')->first();

        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString(
                $code,
                (string) $value,
                sprintf('collection_verifications.%s holds the code.', $column),
            );
        }

        // Hashed, not merely absent: the stored value still has to be checkable.
        $this->assertTrue(Hash::check($code, $row['code_hash']));
        $this->assertStringStartsWith('$2y$', $row['code_hash']);
    }

    public function test_no_code_appears_in_a_response_body_or_in_the_log(): void
    {
        $this->captureLog();

        $order = $this->paidOfflineOrder();

        $send = $this->sendCode($order);
        $send->assertOk();

        $code = $this->sentCode();

        // The response that issued it names where it went and what the gateway said,
        // and nothing else.
        $this->assertStringNotContainsString($code, $send->getContent());

        // A wrong entry, then the right one. Both responses, and the pages after.
        $wrong = $this->confirm($order, self::COLLECTOR + ['code' => '000001']);
        $this->assertStringNotContainsString($code, $wrong->getContent());

        $good = $this->confirm($order, self::COLLECTOR + ['code' => $code]);
        $this->assertStringNotContainsString($code, $good->getContent());

        $list = $this->actingAs($this->admin())
            ->get(route('admin.shop.orders', ['tab' => ShopOrder::FULFILMENT_OFFLINE]));
        $list->assertOk();
        $this->assertStringNotContainsString($code, $list->getContent());

        $show = $this->actingAs($this->admin())->get(route('admin.shop.orders.show', $order));
        $show->assertOk();
        $this->assertStringNotContainsString($code, $show->getContent());

        // Neither the application log nor the trail somebody with database access
        // would read.
        $this->assertStringNotContainsString($code, $this->loggedText());

        foreach (ActivityLog::query()->pluck('description') as $description) {
            $this->assertStringNotContainsString($code, (string) $description);
        }

        foreach ($order->fresh()->events as $event) {
            $this->assertStringNotContainsString($code, (string) $event->note);
        }

        foreach (\App\Models\AuditLog::query()->get() as $audit) {
            $this->assertStringNotContainsString($code, json_encode($audit->new_values));
            $this->assertStringNotContainsString($code, json_encode($audit->old_values));
        }
    }

    /* ---------------------------------------------------------------------
     | The override
     * ------------------------------------------------------------------ */

    public function test_the_override_completes_the_handover_and_marks_it_unverified(): void
    {
        $order = $this->paidOfflineOrder();
        $admin = $this->admin();
        $before = $this->figures($order);

        $response = $this->actingAs($admin)
            ->from(route('admin.shop.orders', ['tab' => ShopOrder::FULFILMENT_OFFLINE]))
            ->post(route('admin.shop.orders.collect', $order), self::COLLECTOR + [
                'override_reason' => 'No signal inside the hall, three codes never arrived.',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('warning');

        $after = $order->fresh();

        $this->assertSame(ShopOrder::STATUS_DELIVERED, $after->status);
        $this->assertNotNull($after->delivered_at);

        // Nothing was texted, so nothing was issued.
        $this->assertSame(0, $this->messagesSent());
        $this->assertSame(0, CollectionVerification::query()->count());

        $handover = $after->handover;

        $this->assertNotNull($handover);
        $this->assertTrue($handover->byThirdParty());
        $this->assertFalse($handover->isVerified());
        $this->assertTrue($handover->wasOverridden());
        $this->assertFalse($handover->isAssured());
        $this->assertSame('No signal inside the hall, three codes never arrived.', $handover->override_reason);
        $this->assertSame($admin->id, $handover->confirmed_by);
        $this->assertNull($handover->collection_verification_id);

        // Said out loud on the history, on the row and on the order page.
        $this->assertStringContainsString(
            'HANDED OVER WITHOUT SMS VERIFICATION',
            $after->events()->reorder()->latest('id')->first()->note,
        );

        $this->actingAs($admin)
            ->get(route('admin.shop.orders.show', $order))
            ->assertOk()
            ->assertSee('Handed over without SMS verification')
            ->assertSee('No signal inside the hall, three codes never arrived.');

        $this->actingAs($admin)
            ->get(route('admin.shop.orders', ['tab' => ShopOrder::FULFILMENT_OFFLINE]))
            ->assertOk()
            ->assertSee('No SMS verification');

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'shop.orders.collected',
        ]);

        $this->assertSame($before, $this->figures($order));
    }

    public function test_the_override_needs_a_reason(): void
    {
        $order = $this->paidOfflineOrder();

        // An empty reason is not an override: it is a third party with no code, and
        // it is refused as one.
        $this->confirm($order, self::COLLECTOR + ['override_reason' => '   '])
            ->assertSessionHasErrors('code');

        $this->assertSame(ShopOrder::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(0, CollectionHandover::query()->count());
    }

    /* ---------------------------------------------------------------------
     | A gateway that will not play
     * ------------------------------------------------------------------ */

    public function test_a_gateway_failure_surfaces_at_once_and_collects_nothing(): void
    {
        $this->fakeInfobipFailure();

        $order = $this->paidOfflineOrder();
        $before = $this->figures($order);

        $response = $this->sendCode($order);

        $response->assertStatus(422);
        $response->assertJson(['ok' => false]);
        $response->assertJsonFragment(['ok' => false]);

        $after = $order->fresh();

        $this->assertSame(ShopOrder::STATUS_PAID, $after->status);
        $this->assertNull($after->delivered_at);
        $this->assertNull($after->handover);

        // The row stays as the record that an issue was attempted, and it is dead:
        // never sent, and burned so it cannot shadow a later code.
        $verification = CollectionVerification::query()->latest('id')->first();

        $this->assertNotNull($verification);
        $this->assertNull($verification->sent_at);
        $this->assertNotNull($verification->burned_at);
        $this->assertFalse($verification->isLive());

        // A failed send holds nothing shut: the counter can try again immediately.
        $this->fakeInfobip();
        $this->sendCode($order)->assertOk();

        $this->assertSame($before, $this->figures($order));
    }

    public function test_an_unconfigured_gateway_is_reported_rather_than_silently_passing(): void
    {
        Setting::write('integration.sms.api_key', '', 'integration.sms');

        $order = $this->paidOfflineOrder();

        $response = $this->sendCode($order);

        $response->assertStatus(422);
        $response->assertJson(['ok' => false]);

        $this->assertSame(0, $this->messagesSent());
        $this->assertSame(ShopOrder::STATUS_PAID, $order->fresh()->status);
    }

    /* ---------------------------------------------------------------------
     | Pressing it twice
     * ------------------------------------------------------------------ */

    public function test_a_collected_order_cannot_be_collected_again_and_issues_no_second_code(): void
    {
        $order = $this->paidOfflineOrder();

        $this->sendCode($order)->assertOk();

        $code = $this->sentCode();

        $this->confirm($order, self::COLLECTOR + ['code' => $code])->assertSessionHas('status');

        $first = $order->fresh();
        $deliveredAt = $first->delivered_at;
        $events = $first->events()->count();

        // The second press of Collected.
        $this->confirm($order->fresh(), self::COLLECTOR + ['code' => $code])
            ->assertSessionHas('warning');

        $second = $order->fresh();

        $this->assertSame(ShopOrder::STATUS_DELIVERED, $second->status);
        $this->assertEquals($deliveredAt, $second->delivered_at);
        $this->assertSame($events, $second->events()->count());
        $this->assertSame(1, CollectionHandover::query()->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'shop.orders.collected')->count());

        // And a second press of Send Code on an order that has already gone.
        $this->sendCode($order->fresh())->assertStatus(422);

        $this->assertSame(1, $this->messagesSent());
        $this->assertSame(1, CollectionVerification::query()->count());
    }

    public function test_sending_a_code_is_refused_without_the_permission(): void
    {
        $order = $this->paidOfflineOrder();

        $this->sendCode($order, [], self::READ_ONLY)->assertForbidden();

        $this->assertSame(0, $this->messagesSent());
        $this->assertSame(0, CollectionVerification::query()->count());
    }

    public function test_a_get_on_the_code_route_is_refused(): void
    {
        $order = $this->paidOfflineOrder();

        $this->actingAs($this->admin())
            ->get(route('admin.shop.orders.collection-code', $order))
            ->assertStatus(405);

        $this->assertSame(0, $this->messagesSent());
    }

    /* ---------------------------------------------------------------------
     | The dialog itself
     * ------------------------------------------------------------------ */

    public function test_the_dialog_offers_the_buyer_by_default_and_a_way_to_name_somebody_else(): void
    {
        $order = $this->paidOfflineOrder();

        $response = $this->actingAs($this->admin())
            ->get(route('admin.shop.orders', ['tab' => ShopOrder::FULFILMENT_OFFLINE]));

        $response->assertOk();
        $response->assertSee('Who is collecting?');
        $response->assertSee('The buyer, ' . $order->customer_name);
        $response->assertSee("Somebody else, on the buyer's behalf", false);
        $response->assertSee('Their IC Number');
        $response->assertSee('Code They Read Out');
        $response->assertSee('The code will not go through');
        $response->assertSee(route('admin.shop.orders.collection-code', $order), false);

        // The buyer is the one pre-selected, so the common case is still two presses.
        $this->assertStringContainsString(
            'value="buyer" checked',
            $response->getContent(),
        );
    }

    /* ---------------------------------------------------------------------
     | The three settings
     * ------------------------------------------------------------------ */

    public function test_the_settings_screen_carries_the_three_collection_numbers(): void
    {
        $response = $this->actingAs($this->userWith(self::CAN_SETTINGS))
            ->get(route('admin.settings.integration', ['tab' => 'sms']));

        $response->assertOk();
        $response->assertSee('Collection Codes');
        $response->assertSee('Code Expires After');
        $response->assertSee('Tries Before A Code Is Burned');
        $response->assertSee('Wait Before Sending Another');

        // Expiry and cooldown are the pair that gets confused, so each says it is
        // not the other.
        $response->assertSee('Not the same as the cooldown below');
        $response->assertSee('Not the same as the expiry above');

        // The defaults are on screen rather than three empty boxes somebody has to
        // guess at. The attributes are on separate lines in the markup.
        foreach (['collection_code_expiry_minutes' => 10, 'collection_code_max_attempts' => 5, 'collection_code_cooldown_minutes' => 2] as $name => $default) {
            $this->assertMatchesRegularExpression(
                '/name="' . $name . '"\s+value="' . $default . '"/',
                $response->getContent(),
            );
        }
    }

    public function test_the_three_settings_default_to_ten_five_and_two(): void
    {
        $this->assertSame(10, SmsSettings::collectionCodeExpiryMinutes());
        $this->assertSame(5, SmsSettings::collectionCodeMaxAttempts());
        $this->assertSame(2, SmsSettings::collectionCodeCooldownMinutes());
    }

    public function test_the_three_settings_are_validated_as_positive_integers_with_a_ceiling(): void
    {
        $user = $this->userWith(self::CAN_SETTINGS);
        $valid = [
            'provider' => SmsSettings::PROVIDER_INFOBIP,
            'collection_code_expiry_minutes' => 10,
            'collection_code_max_attempts' => 5,
            'collection_code_cooldown_minutes' => 2,
        ];

        $cases = [
            ['collection_code_expiry_minutes' => 0],
            ['collection_code_expiry_minutes' => -5],
            ['collection_code_expiry_minutes' => 'soon'],
            ['collection_code_expiry_minutes' => SmsSettings::CODE_EXPIRY_CEILING + 1],
            ['collection_code_max_attempts' => 0],
            ['collection_code_max_attempts' => SmsSettings::CODE_ATTEMPTS_CEILING + 1],
            ['collection_code_cooldown_minutes' => 0],
            ['collection_code_cooldown_minutes' => SmsSettings::CODE_COOLDOWN_CEILING + 1],
        ];

        foreach ($cases as $bad) {
            $this->actingAs($user)
                ->from(route('admin.settings.integration', ['tab' => 'sms']))
                ->put(route('admin.settings.integration.update', ['tab' => 'sms']), $bad + $valid)
                ->assertSessionHasErrors(array_keys($bad));
        }

        // Nothing was written by any of those.
        $this->assertSame(10, SmsSettings::collectionCodeExpiryMinutes());
        $this->assertSame(5, SmsSettings::collectionCodeMaxAttempts());
        $this->assertSame(2, SmsSettings::collectionCodeCooldownMinutes());
    }

    public function test_saving_the_three_settings_drives_the_service(): void
    {
        $this->actingAs($this->userWith(self::CAN_SETTINGS))
            ->put(route('admin.settings.integration.update', ['tab' => 'sms']), [
                'provider' => SmsSettings::PROVIDER_INFOBIP,
                'collection_code_expiry_minutes' => 15,
                'collection_code_max_attempts' => 3,
                'collection_code_cooldown_minutes' => 7,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(15, SmsSettings::collectionCodeExpiryMinutes());
        $this->assertSame(3, SmsSettings::collectionCodeMaxAttempts());
        $this->assertSame(7, SmsSettings::collectionCodeCooldownMinutes());

        // Saving the tab must not have dropped the gateway profile with it.
        $this->infobipSettings();

        $order = $this->paidOfflineOrder();

        $this->sendCode($order)->assertOk();

        $verification = CollectionVerification::query()->latest('id')->first();

        $this->assertSame(3, $verification->max_attempts);
        $this->assertEqualsWithDelta(
            now()->addMinutes(15)->timestamp,
            $verification->expires_at->timestamp,
            60,
        );

        $this->travel(5)->minutes();
        $this->sendCode($order)->assertStatus(422);
    }

    /* ---------------------------------------------------------------------
     | The service, on its own
     * ------------------------------------------------------------------ */

    public function test_the_service_is_generic_over_what_is_being_collected(): void
    {
        $order = $this->paidOfflineOrder();
        $verifier = app(CollectionVerifier::class);

        // Handed a model and a subject line. Nothing shop-shaped reaches it, which is
        // what lets the event Collection screen pass one participant instead.
        $this->actingAs($this->admin());

        $issued = $verifier->issue($order, '0178591411', 'one team jersey');

        $this->assertSame('60178591411', $issued->verification->phone);
        $this->assertSame(ShopOrder::class, $issued->verification->verifiable_type);
        $this->assertStringContainsString('one team jersey', $this->lastSentText());

        // And the only thing it hands back about the message is the gateway's answer.
        $this->assertStringNotContainsString($this->sentCode(), $issued->summary());
    }

    public function test_one_number_cannot_be_flooded_across_different_orders(): void
    {
        $this->setting('collection_code_cooldown_minutes', '5');

        $product = $this->offlineProduct();
        $orders = [];

        for ($i = 0; $i <= CollectionVerifier::NUMBER_BURST_LIMIT; $i++) {
            $orders[] = $this->paidOfflineOrder($product);
        }

        // Up to the limit goes out: one person really does collect for a group.
        for ($i = 0; $i < CollectionVerifier::NUMBER_BURST_LIMIT; $i++) {
            $this->sendCode($orders[$i])->assertOk();
        }

        $this->assertSame(CollectionVerifier::NUMBER_BURST_LIMIT, $this->messagesSent());

        // One more to the same handset, on an order with no cooldown of its own.
        $this->sendCode($orders[CollectionVerifier::NUMBER_BURST_LIMIT])->assertStatus(422);

        $this->assertSame(CollectionVerifier::NUMBER_BURST_LIMIT, $this->messagesSent());
    }

    public function test_an_unusable_telephone_number_sends_nothing(): void
    {
        $order = $this->paidOfflineOrder();

        $this->sendCode($order, ['collector_phone' => '12'])->assertStatus(422);

        $this->assertSame(0, $this->messagesSent());
        $this->assertSame(0, CollectionVerification::query()->count());
    }
}
