<?php

namespace Tests\Feature\Registration;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\EventRegistrationPayment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Payment\GatewayReceiptAudit;
use App\Services\Payment\RegistrationBalanceCharge;
use App\Services\Payment\RegistrationPaymentUpdater;
use App\Support\PaymentSettings;
use App\Support\ParticipantOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The ledger records what the gateway took, and the badge follows the ledger.
 *
 * WHAT WENT WRONG ON THE LIVE SITE
 *
 * settleLedger() inserted "whatever is left of the charge" whenever a gateway event
 * said paid. That is correct for as long as a charge never moves. Rechecking add-on
 * totals moves one: REG-2026-0072 went from RM 40.00 to RM 120.00 after its payer had
 * already paid RM 40.00. The next PAID event for the same purchase — a replayed
 * webhook, a gateway lookup when the admin opened the entry — worked the shortfall out
 * against the NEW figure and wrote itself an RM 80.00 receipt, reusing the same
 * purchase reference. CHIP's stored payload for that purchase says payment.amount
 * 4000, which is RM 40.00, and CHIP never had an RM 80.00 transaction.
 *
 * Two entries on one event ended up reading Paid and Confirmed with RM 120.00 of
 * phantom money in the takings, and the owner was shown a list saying "Partly Paid,
 * RM 40.00 of RM 80.00 outstanding" beside a payments table saying "Settled in full".
 *
 * The fixtures below are those two rows exactly, ids and timestamps included, so the
 * diagnostic is proven against the shape that actually happened rather than a
 * convenient one.
 */
class GatewayReceiptLedgerTest extends TestCase
{
    use RefreshDatabase;

    /** CHIP's "public key", generated per test run. Never a real credential. */
    private string $webhookPublicKey = '';

    /** Its private half, used only to sign the bodies a test posts. */
    private string $webhookPrivateKey = '';

    /** REG-2026-0069's purchase, as it is on the live row. */
    private const PURCHASE_69 = '7a3bb986-fd84-460c-b071-2370ecf9f4d8';

    /** REG-2026-0072's. */
    private const PURCHASE_72 = 'd06279af-5d27-4e3c-af16-02ab1de79923';

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing here has anything to prove about mail, and settling an entry sends
        // a receipt. Nothing reaches a transport: phpunit.xml sets MAIL_MAILER=array
        // and this is the belt to its braces.
        Mail::fake();
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    private function event(): Event
    {
        return Event::create([
            'slug' => 'hsn-' . uniqid(),
            'title' => 'HARI SUKAN NEGARA 2026 PERINGKAT BAHAGIAN SIBU',
            'category' => 'Community',
            'starts_at' => now()->addMonth()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_GROUPING,
            'fee' => null,
            'seats_total' => 0,
            'min_players' => 1,
            'max_players' => 20,
            'charges_addons_per_participant' => true,
        ]);
    }

    /**
     * One entry, with however many people and whatever money state is wanted.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function registration(Event $event, int $people, array $overrides = []): EventRegistration
    {
        $registration = EventRegistration::create($overrides + [
            'event_id' => $event->id,
            'reference' => 'REG-2026-' . str_pad((string) (EventRegistration::query()->count() + 69), 4, '0', STR_PAD_LEFT),
            'mode' => $event->registration_mode,
            'team_name' => 'Group of ' . $people,
            'status' => EventRegistration::STATUS_PENDING,
            'payment_status' => EventRegistration::PAYMENT_PENDING,
            'registration_fee' => 0,
            'addons_total' => 40 * $people,
            'amount' => 40 * $people,
            'amount_paid' => 0,
        ]);

        for ($n = 1; $n <= $people; $n++) {
            EventParticipant::create([
                'event_registration_id' => $registration->id,
                'role' => ParticipantOptions::ROLE_PARTICIPANT,
                'full_name' => 'Member ' . $n . ' of ' . $registration->reference,
                'ic_number' => '9001' . str_pad((string) $registration->id, 4, '0', STR_PAD_LEFT) . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                'phone' => '0142000' . str_pad((string) $n, 3, '0', STR_PAD_LEFT),
                'email' => 'member' . $n . '-' . $registration->id . '@example.com',
                'gender' => 'male',
                'race' => 'malay',
            ]);
        }

        return $registration->fresh(['participants']);
    }

    /**
     * A CHIP purchase object, in CHIP's own shape.
     *
     * `payment.amount` and `purchase.total` are in cents, which is the whole point:
     * 4000 is RM 40.00, and it is the only figure allowed to decide what goes on the
     * ledger.
     *
     * @return array<string, mixed>
     */
    private function purchasePayload(
        EventRegistration $registration,
        string $purchaseId,
        int $cents,
        string $event = 'purchase.paid',
        string $status = 'paid',
    ): array {
        return [
            'event_type' => $event,
            'id' => $purchaseId,
            'reference' => $registration->reference,
            'status' => $status,
            'purchase' => ['total' => $cents, 'currency' => 'MYR'],
            'payment' => ['amount' => $cents, 'currency' => 'MYR', 'paid_on' => now()->subHours(3)->timestamp],
        ];
    }

    /** One receipt row, written exactly as the live table holds it. */
    private function receipt(
        EventRegistration $registration,
        float $amount,
        string $receivedAt,
        ?string $reference,
        string $source = EventRegistrationPayment::SOURCE_GATEWAY,
        array $overrides = [],
    ): EventRegistrationPayment {
        return EventRegistrationPayment::create($overrides + [
            'event_registration_id' => $registration->id,
            'amount' => $amount,
            'received_at' => $receivedAt,
            'reference' => $reference,
            'note' => $source === EventRegistrationPayment::SOURCE_GATEWAY
                ? 'Taken by the payment gateway.'
                : 'Bank transfer.',
            'source' => $source,
        ]);
    }

    /**
     * REG-2026-0069 as it stands on the production dump.
     *
     * Two people, charged RM 80.00, reading Paid and Confirmed with RM 80.00 received.
     * Two gateway rows against one purchase, and a stored payload saying CHIP took
     * RM 40.00.
     */
    private function reg69(Event $event): EventRegistration
    {
        $registration = $this->registration($event, 2, [
            'reference' => 'REG-2026-0069',
            'status' => EventRegistration::STATUS_CONFIRMED,
            'payment_status' => EventRegistration::PAYMENT_PAID,
            'amount_paid' => 80,
            'payment_reference' => self::PURCHASE_69,
        ]);

        $registration->payment_details = $this->purchasePayload($registration, self::PURCHASE_69, 4000);
        $registration->payment_synced_at = now()->subHours(2);
        $registration->save();

        // Genuine: written when the purchase actually settled.
        $this->receipt($registration, 40, '2026-10-04 05:44:32', self::PURCHASE_69);

        // Fabricated: written three hours later, when the charge was corrected.
        $this->receipt($registration, 40, '2026-10-04 08:47:41', self::PURCHASE_69);

        return $registration->fresh(['payments', 'participants']);
    }

    /**
     * REG-2026-0072 as it stands on the production dump.
     *
     * Three people, charged RM 120.00, and the fabricated row is exactly 120 - 40,
     * which is the old shortfall formula showing its working.
     */
    private function reg72(Event $event): EventRegistration
    {
        $registration = $this->registration($event, 3, [
            'reference' => 'REG-2026-0072',
            'status' => EventRegistration::STATUS_CONFIRMED,
            'payment_status' => EventRegistration::PAYMENT_PAID,
            'amount_paid' => 120,
            'payment_reference' => self::PURCHASE_72,
        ]);

        $registration->payment_details = $this->purchasePayload($registration, self::PURCHASE_72, 4000);
        $registration->payment_synced_at = now()->subHours(2);
        $registration->save();

        $this->receipt($registration, 40, '2026-10-04 05:35:38', self::PURCHASE_72);
        $this->receipt($registration, 80, '2026-10-04 08:47:06', self::PURCHASE_72);

        return $registration->fresh(['payments', 'participants']);
    }

    /** An entry with nothing wrong with it: one gateway row, matching the payload. */
    private function clean(Event $event): EventRegistration
    {
        $purchase = 'clean-' . uniqid();

        $registration = $this->registration($event, 1, [
            'status' => EventRegistration::STATUS_CONFIRMED,
            'payment_status' => EventRegistration::PAYMENT_PAID,
            'amount_paid' => 40,
            'payment_reference' => $purchase,
        ]);

        $registration->payment_details = $this->purchasePayload($registration, $purchase, 4000);
        $registration->save();

        $this->receipt($registration, 40, '2026-10-04 05:00:00', $purchase);

        return $registration->fresh(['payments', 'participants']);
    }

    /* ---------------------------------------------------------------------
     | People and the gateway
     * ------------------------------------------------------------------ */

    /**
     * A user holding exactly the named permissions, and nothing else.
     *
     * A real role with real pivot rows rather than the super-admin shortcut, because
     * part of this file is about the control being refused without its permission, and
     * super-admin short-circuits hasPermission() to true.
     *
     * @param  array<int, string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $role = Role::create([
            'slug' => 'staff-' . uniqid(),
            'name' => 'Staff',
            'is_active' => true,
        ]);

        $ids = [];

        foreach (array_unique(['admin.access', ...$permissions]) as $index => $slug) {
            $ids[] = Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'group' => 'Event', 'module' => 'Participants', 'action' => 'view', 'sort_order' => $index],
            )->id;
        }

        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'Staff',
            'username' => 'staff-' . uniqid(),
            'email' => uniqid() . '@example.com',
            'password' => 'secret-password',
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    /** Somebody allowed to read the list and to touch the registration ledger. */
    private function corrector(): User
    {
        return $this->userWith(['participants.view', 'payments.record', 'participants.notify']);
    }

    /** CHIP configured, with a verifiable webhook. */
    private function configureGateway(): void
    {
        [$this->webhookPrivateKey, $this->webhookPublicKey] = $this->keypair();

        Setting::write('integration.payments.provider', PaymentSettings::PROVIDER_CHIP, 'integration.payments');
        Setting::write('integration.payments.chip_brand_id', 'brand-' . uniqid(), 'integration.payments');
        Setting::write('integration.payments.chip_api_key', 'key-' . uniqid(), 'integration.payments');
        Setting::write('integration.payments.chip_webhook_public_key', $this->webhookPublicKey, 'integration.payments');
        Setting::write('integration.payments.currency', 'MYR', 'integration.payments');
    }

    /**
     * POST a body to the webhook, signed over the exact bytes sent.
     *
     * json_encode once and post that same string: the controller verifies against the
     * raw body, so signing a re-encoded array would break the signature.
     *
     * @param  array<string, mixed>  $payload
     */
    private function postWebhook(array $payload)
    {
        $body = json_encode($payload);

        openssl_sign($body, $raw, $this->webhookPrivateKey, OPENSSL_ALGO_SHA256);

        return $this->call(
            'POST',
            route('payments.chip.webhook'),
            [],
            [],
            [],
            ['HTTP_X_SIGNATURE' => base64_encode($raw), 'CONTENT_TYPE' => 'application/json'],
            $body,
        );
    }

    /**
     * A throwaway RSA keypair, generated per call.
     *
     * The config file is written because openssl_pkey_new() reads openssl.cnf and will
     * not generate a key without one, and plenty of PHP builds ship neither it nor
     * OPENSSL_CONF. Signing and verifying do not read it.
     *
     * @return array{0: string, 1: string}  private PEM, public PEM
     */
    private function keypair(): array
    {
        $config = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smartcreative-openssl-test.cnf';

        if (! is_file($config)) {
            file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n");
        }

        $options = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'config' => $config,
        ];

        $key = openssl_pkey_new($options);

        $this->assertNotFalse($key, 'Could not generate a test RSA keypair.');

        openssl_pkey_export($key, $private, null, $options);

        return [$private, openssl_pkey_get_details($key)['key']];
    }

    private function audit(): GatewayReceiptAudit
    {
        return app(GatewayReceiptAudit::class);
    }

    /* =====================================================================
     | FIX 1 — the gateway's figure, and only the gateway's figure
     * ================================================================== */

    public function test_a_paid_event_reporting_forty_records_forty_against_a_charge_of_one_hundred_and_twenty(): void
    {
        $this->configureGateway();

        $event = $this->event();

        // Three people, re-priced to RM 120.00 after RM 40.00 had been taken. Nothing
        // on the ledger yet, which is the moment before the fabrication happened.
        $registration = $this->registration($event, 3, [
            'payment_reference' => self::PURCHASE_72,
        ]);

        $this->postWebhook($this->purchasePayload($registration, self::PURCHASE_72, 4000))
            ->assertOk();

        $rows = $registration->fresh()->payments;

        // THE regression. The old arithmetic wrote 120 - 0 = RM 120.00 here, and after
        // a genuine RM 40.00 it wrote RM 80.00. CHIP reported 4000 cents.
        $this->assertCount(1, $rows);
        $this->assertSame('40.00', $rows->first()->amount);
        $this->assertSame(self::PURCHASE_72, $rows->first()->reference);
        $this->assertSame(EventRegistrationPayment::SOURCE_GATEWAY, $rows->first()->source);

        $after = $registration->fresh();

        $this->assertSame(40.0, $after->amountPaid());
        $this->assertSame(80.0, $after->outstandingAmount());

        // And the badge follows it, rather than the gateway's word.
        $this->assertSame(EventRegistration::PAYMENT_PARTIAL, $after->payment_status);
        $this->assertNotSame(EventRegistration::STATUS_CONFIRMED, $after->status);
        $this->assertTrue($after->owesBalance());
    }

    public function test_a_replayed_webhook_for_the_same_purchase_adds_no_second_row(): void
    {
        $this->configureGateway();

        $event = $this->event();
        $registration = $this->registration($event, 3, ['payment_reference' => self::PURCHASE_72]);

        $payload = $this->purchasePayload($registration, self::PURCHASE_72, 4000);

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        // And a late purchase.settled for the same purchase, which is the other way
        // CHIP says the same thing twice.
        $this->postWebhook($this->purchasePayload($registration, self::PURCHASE_72, 4000, 'purchase.settled', 'settled'))
            ->assertOk();

        $this->assertCount(1, $registration->fresh()->payments);
        $this->assertSame(40.0, $registration->fresh()->amountPaid());
    }

    public function test_a_hand_recorded_receipt_then_a_gateway_payment_records_the_reported_figure(): void
    {
        $this->configureGateway();

        $event = $this->event();

        // The case the method was written for: RM 100.00 of RM 250.00 by hand, then the
        // rest on the gateway.
        $registration = $this->registration($event, 1, [
            'amount' => 250,
            'addons_total' => 250,
            'payment_reference' => 'pur-mixed-1',
        ]);

        $staff = $this->corrector();

        $this->actingAs($staff);

        app(RegistrationPaymentUpdater::class)->recordManualPayment(
            registration: $registration,
            amount: 100,
            receivedAt: now()->subDay()->format('Y-m-d H:i:s'),
            reference: 'TRF-1234',
            note: 'Bank transfer seen at the counter.',
        );

        $registration->refresh();

        $this->assertSame(100.0, $registration->amountPaid());
        $this->assertSame(EventRegistration::PAYMENT_PARTIAL, $registration->payment_status);

        // The gateway then takes RM 150.00 and says so.
        $this->postWebhook($this->purchasePayload($registration, 'pur-mixed-1', 15000))->assertOk();

        $after = $registration->fresh();
        $rows = $after->payments;

        $this->assertCount(2, $rows);

        $hand = $rows->firstWhere('source', EventRegistrationPayment::SOURCE_MANUAL);
        $gateway = $rows->firstWhere('source', EventRegistrationPayment::SOURCE_GATEWAY);

        // The hand row is left exactly as it was: amount, reference and who saw it.
        $this->assertSame('100.00', $hand->amount);
        $this->assertSame('TRF-1234', $hand->reference);
        $this->assertSame($staff->id, $hand->recorded_by);

        // The gateway row is the figure the gateway reported, not 250 and not 150
        // arrived at by subtraction from the charge.
        $this->assertSame('150.00', $gateway->amount);
        $this->assertSame('pur-mixed-1', $gateway->reference);
        $this->assertNull($gateway->recorded_by);

        $this->assertSame(250.0, $after->amountPaid());
        $this->assertSame(EventRegistration::PAYMENT_PAID, $after->payment_status);
        $this->assertSame(EventRegistration::STATUS_CONFIRMED, $after->status);
    }

    public function test_recording_a_payment_by_hand_never_writes_a_gateway_row_from_an_abandoned_checkout(): void
    {
        $event = $this->event();

        // An abandoned checkout, whose purchase.total is the charge. Reading it as a
        // payment would be the same fault wearing a different hat.
        $registration = $this->registration($event, 1, ['payment_reference' => 'pur-abandoned']);

        $registration->payment_details = $this->purchasePayload($registration, 'pur-abandoned', 4000, 'purchase.created', 'created');
        $registration->save();

        $this->actingAs($this->corrector());

        app(RegistrationPaymentUpdater::class)->recordManualPayment(
            registration: $registration,
            amount: 40,
            receivedAt: now()->subDay()->format('Y-m-d H:i:s'),
        );

        $after = $registration->fresh();

        $this->assertCount(1, $after->payments);
        $this->assertSame(EventRegistrationPayment::SOURCE_MANUAL, $after->payments->first()->source);
        $this->assertSame(40.0, $after->amountPaid());
        $this->assertSame(EventRegistration::PAYMENT_PAID, $after->payment_status);
    }

    /* =====================================================================
     | FIX 2 — the badge follows the ledger, and every screen says the same
     * ================================================================== */

    public function test_payment_status_is_re_derived_whenever_the_money_moves(): void
    {
        $this->configureGateway();

        $event = $this->event();

        // Covered: RM 40.00 reported against a RM 40.00 charge.
        $covered = $this->registration($event, 1, ['payment_reference' => 'pur-covered']);
        $this->postWebhook($this->purchasePayload($covered, 'pur-covered', 4000))->assertOk();

        $covered = $covered->fresh();

        $this->assertSame(EventRegistration::PAYMENT_PAID, $covered->payment_status);
        $this->assertSame(EventRegistration::STATUS_CONFIRMED, $covered->status);
        $this->assertTrue($covered->isSettledInFull());
        $this->assertSame('Settled in full.', $covered->paymentPositionLabel());

        // Part covered: the same RM 40.00 against a RM 80.00 charge.
        $part = $this->registration($event, 2, ['payment_reference' => 'pur-part']);
        $this->postWebhook($this->purchasePayload($part, 'pur-part', 4000))->assertOk();

        $part = $part->fresh();

        $this->assertSame(EventRegistration::PAYMENT_PARTIAL, $part->payment_status);
        $this->assertSame(EventRegistration::STATUS_PENDING, $part->status);
        $this->assertFalse($part->isSettledInFull());
        $this->assertSame('RM 40.00 of RM 80.00 still outstanding.', $part->paymentPositionLabel());
    }

    public function test_the_list_badge_and_the_payments_table_footer_agree(): void
    {
        $this->configureGateway();

        $event = $this->event();

        $covered = $this->registration($event, 1, ['payment_reference' => 'pur-covered']);
        $part = $this->registration($event, 2, ['payment_reference' => 'pur-part']);

        $this->postWebhook($this->purchasePayload($covered, 'pur-covered', 4000))->assertOk();
        $this->postWebhook($this->purchasePayload($part, 'pur-part', 4000))->assertOk();

        // The detail screen reads the purchase back on open, which is how the
        // fabrication used to be triggered. It must now change nothing.
        Http::fake([
            'gate.chip-in.asia/api/v1/purchases/pur-covered/' => Http::response(
                $this->purchasePayload($covered->fresh(), 'pur-covered', 4000),
            ),
            'gate.chip-in.asia/api/v1/purchases/pur-part/' => Http::response(
                $this->purchasePayload($part->fresh(), 'pur-part', 4000),
            ),
        ]);

        $admin = $this->userWith(['participants.view', 'payments.record']);

        $list = $this->actingAs($admin)->get(route('admin.event.participants', ['tab' => 'group', 'event' => $event->id]));
        $list->assertOk();
        $list->assertSee('Partly Paid');
        $list->assertSee('RM 40.00 short');

        // The part-paid entry: the badge says Partly Paid and the footer must not say
        // settled. This pair is exactly what the owner was shown disagreeing.
        $partPage = $this->actingAs($admin)->get(route('admin.event.participants.show', $part));
        $partPage->assertOk();
        $partPage->assertSee('Partly Paid');
        $partPage->assertSee('RM 40.00 of RM 80.00 still outstanding.');
        $partPage->assertDontSee('Settled in full.');

        $coveredPage = $this->actingAs($admin)->get(route('admin.event.participants.show', $covered));
        $coveredPage->assertOk();
        $coveredPage->assertSee('Settled in full.');

        // And opening either page left the money alone.
        $this->assertSame(40.0, $part->fresh()->amountPaid());
        $this->assertCount(1, $part->fresh()->payments);
        $this->assertSame(40.0, $covered->fresh()->amountPaid());
        $this->assertCount(1, $covered->fresh()->payments);
    }

    /* =====================================================================
     | FIX 3 — the diagnostic
     * ================================================================== */

    public function test_the_diagnostic_finds_both_fabricated_rows_and_nothing_on_a_clean_entry(): void
    {
        $event = $this->event();

        $sixtyNine = $this->reg69($event);
        $seventyTwo = $this->reg72($event);
        $clean = $this->clean($event);

        $findings = collect($this->audit()->preview($event))
            ->keyBy(fn ($finding) => $finding->registration->reference);

        $this->assertCount(2, $findings);
        $this->assertFalse($findings->has($clean->reference));

        $first = $findings['REG-2026-0069'];

        $this->assertSame(self::PURCHASE_69, $first->purchaseId);
        $this->assertSame(40.0, $first->reported);
        $this->assertSame(80.0, $first->recorded);
        $this->assertCount(1, $first->phantoms);
        $this->assertSame(40.0, $first->phantoms[0]->amount());
        $this->assertSame('2026-10-04 08:47:41', $first->phantoms[0]->payment->received_at->toDateTimeString());
        $this->assertSame(40.0, $first->correctedAmountPaid());
        $this->assertSame(40.0, $first->correctedOutstanding());
        $this->assertSame(EventRegistration::PAYMENT_PARTIAL, $first->correctedPaymentStatus());

        $second = $findings['REG-2026-0072'];

        $this->assertSame(80.0, $second->phantoms[0]->amount());
        $this->assertSame('2026-10-04 08:47:06', $second->phantoms[0]->payment->received_at->toDateTimeString());
        $this->assertSame(40.0, $second->reported);
        $this->assertSame(120.0, $second->recorded);
        $this->assertSame(40.0, $second->correctedAmountPaid());
        $this->assertSame(80.0, $second->correctedOutstanding());

        // The genuine rows are not in the findings, by id.
        $condemned = $findings
            ->flatMap(fn ($finding) => array_map(fn ($receipt) => $receipt->payment->id, $finding->phantoms))
            ->all();

        $this->assertNotContains($sixtyNine->payments->sortBy('id')->first()->id, $condemned);
        $this->assertNotContains($seventyTwo->payments->sortBy('id')->first()->id, $condemned);

        // RM 120.00 of money the gateway never took.
        $this->assertSame(120.0, GatewayReceiptAudit::total($findings->values()->all()));
    }

    public function test_the_diagnostic_finds_nothing_without_a_stored_payload_to_contradict_the_rows(): void
    {
        $event = $this->event();

        $registration = $this->reg69($event);

        // No gateway record kept, so there is no evidence. Arithmetic alone is not
        // enough to delete a payment on.
        $registration->payment_details = null;
        $registration->save();

        $this->assertSame([], $this->audit()->preview($event));
    }

    public function test_the_diagnostic_preview_writes_nothing(): void
    {
        $event = $this->event();

        $sixtyNine = $this->reg69($event);
        $seventyTwo = $this->reg72($event);

        $before = [$sixtyNine->fresh(), $seventyTwo->fresh()];

        $response = $this->actingAs($this->corrector())
            ->get(route('admin.event.participants.receipts', $event));

        $response->assertOk();
        $response->assertSee('REG-2026-0069');
        $response->assertSee('REG-2026-0072');
        $response->assertSee(self::PURCHASE_69);
        $response->assertSee('RM 120.00');

        $this->assertDatabaseCount('event_registration_payments', 4);
        $this->assertDatabaseCount('audit_logs', 0);

        foreach ($before as $was) {
            $now = $was->fresh();

            $this->assertSame($was->amount_paid, $now->amount_paid);
            $this->assertSame($was->payment_status, $now->payment_status);
            $this->assertSame($was->status, $now->status);
            $this->assertEquals($was->updated_at, $now->updated_at);
        }
    }

    /* =====================================================================
     | FIX 3 — the correction
     * ================================================================== */

    public function test_the_correction_removes_only_the_fabricated_rows_and_flips_both_off_paid(): void
    {
        $event = $this->event();

        $sixtyNine = $this->reg69($event);
        $seventyTwo = $this->reg72($event);
        $clean = $this->clean($event);

        $keep69 = $sixtyNine->payments->sortBy('id')->first()->id;
        $keep72 = $seventyTwo->payments->sortBy('id')->first()->id;

        $admin = $this->corrector();

        $this->actingAs($admin)
            ->post(route('admin.event.participants.receipts.apply', $event), ['confirm' => '1'])
            ->assertRedirect(route('admin.event.participants.receipts', $event))
            ->assertSessionHasNoErrors();

        $after69 = $sixtyNine->fresh();

        $this->assertSame('80.00', $after69->amount);
        $this->assertSame(40.0, $after69->amountPaid());
        $this->assertSame(40.0, $after69->outstandingAmount());
        $this->assertSame(EventRegistration::PAYMENT_PARTIAL, $after69->payment_status);
        $this->assertSame(EventRegistration::STATUS_PENDING, $after69->status);
        $this->assertFalse($after69->isPaid());
        $this->assertTrue($after69->owesBalance());
        $this->assertSame([$keep69], $after69->payments->pluck('id')->all());

        $after72 = $seventyTwo->fresh();

        $this->assertSame('120.00', $after72->amount);
        $this->assertSame(40.0, $after72->amountPaid());
        $this->assertSame(80.0, $after72->outstandingAmount());
        $this->assertSame(EventRegistration::PAYMENT_PARTIAL, $after72->payment_status);
        $this->assertSame(EventRegistration::STATUS_PENDING, $after72->status);
        $this->assertSame([$keep72], $after72->payments->pluck('id')->all());

        // The clean entry is not touched at all.
        $afterClean = $clean->fresh();

        $this->assertSame(40.0, $afterClean->amountPaid());
        $this->assertSame(EventRegistration::PAYMENT_PAID, $afterClean->payment_status);
        $this->assertSame(EventRegistration::STATUS_CONFIRMED, $afterClean->status);
        $this->assertCount(1, $afterClean->payments);
    }

    public function test_the_correction_records_the_whole_deleted_row_and_who_did_it(): void
    {
        $event = $this->event();

        $seventyTwo = $this->reg72($event);
        $phantomId = $seventyTwo->payments->sortByDesc('id')->first()->id;

        $admin = $this->corrector();

        $this->actingAs($admin)
            ->post(route('admin.event.participants.receipts.apply', $event), ['confirm' => '1'])
            ->assertSessionHasNoErrors();

        $audit = AuditLog::query()
            ->where('auditable_type', EventRegistration::class)
            ->where('auditable_id', $seventyTwo->id)
            ->where('event', 'payment.phantom-removed')
            ->sole();

        $this->assertSame($admin->id, $audit->user_id);

        // The row is irrecoverable, so the trail holds all of it.
        $removed = $audit->old_values['payments_removed'][0];

        $this->assertSame($phantomId, $removed['id']);
        $this->assertSame(80.0, (float) $removed['amount']);
        $this->assertSame('2026-10-04 08:47:06', $removed['received_at']);
        $this->assertSame(self::PURCHASE_72, $removed['reference']);
        $this->assertSame('Taken by the payment gateway.', $removed['note']);
        $this->assertSame(EventRegistrationPayment::SOURCE_GATEWAY, $removed['source']);
        $this->assertNull($removed['recorded_by']);
        $this->assertSame(40.0, (float) $removed['gateway_reported']);
        $this->assertSame(120.0, (float) $removed['ledger_recorded']);

        $this->assertSame(120.0, (float) $audit->old_values['amount_paid']);
        $this->assertSame(40.0, (float) $audit->new_values['amount_paid']);
        $this->assertSame(EventRegistration::PAYMENT_PARTIAL, $audit->new_values['payment_status']);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'payments.phantom',
        ]);
    }

    public function test_the_correction_never_removes_a_hand_recorded_row_or_one_carrying_proof(): void
    {
        $event = $this->event();

        $purchase = 'pur-mixed-ledger';

        // RM 120.00 owed. CHIP took RM 40.00 and reports it. Beside the genuine gateway
        // row sit a hand-recorded receipt quoting the same purchase reference, a gateway
        // row carrying a transfer slip, and one plain fabrication.
        $registration = $this->registration($event, 3, [
            'status' => EventRegistration::STATUS_CONFIRMED,
            'payment_status' => EventRegistration::PAYMENT_PAID,
            'amount_paid' => 160,
            'payment_reference' => $purchase,
        ]);

        $registration->payment_details = $this->purchasePayload($registration, $purchase, 4000);
        $registration->save();

        $genuine = $this->receipt($registration, 40, '2026-10-04 05:00:00', $purchase);

        $byHand = $this->receipt($registration, 40, '2026-10-04 06:00:00', $purchase, EventRegistrationPayment::SOURCE_MANUAL, [
            'recorded_by' => $this->corrector()->id,
            'actor_label' => 'Counter staff',
        ]);

        $withProof = $this->receipt($registration, 40, '2026-10-04 07:00:00', $purchase, EventRegistrationPayment::SOURCE_GATEWAY, [
            'proof_path' => 'registration-payment-proof/slip.png',
            'proof_name' => 'slip.png',
        ]);

        $fabricated = $this->receipt($registration, 40, '2026-10-04 08:47:00', $purchase);

        $this->actingAs($this->corrector())
            ->post(route('admin.event.participants.receipts.apply', $event), ['confirm' => '1'])
            ->assertSessionHasNoErrors();

        $remaining = $registration->fresh()->payments->pluck('id')->sort()->values()->all();

        // Only the plain gateway fabrication goes. The administrator's receipts stay,
        // whatever CHIP says about its own purchase.
        $this->assertSame([$genuine->id, $byHand->id, $withProof->id], $remaining);
        $this->assertDatabaseHas('event_registration_payments', ['id' => $byHand->id]);
        $this->assertDatabaseHas('event_registration_payments', ['id' => $withProof->id]);
        $this->assertDatabaseMissing('event_registration_payments', ['id' => $fabricated->id]);

        $this->assertSame(120.0, $registration->fresh()->amountPaid());
        $this->assertSame(EventRegistration::PAYMENT_PAID, $registration->fresh()->payment_status);
    }

    public function test_running_the_correction_twice_is_a_no_op(): void
    {
        $event = $this->event();

        $sixtyNine = $this->reg69($event);
        $this->reg72($event);

        $admin = $this->corrector();

        $this->actingAs($admin)
            ->post(route('admin.event.participants.receipts.apply', $event), ['confirm' => '1'])
            ->assertSessionHas('status');

        $firstPass = $sixtyNine->fresh();

        $this->assertSame([], $this->audit()->preview($event));

        $this->actingAs($admin)
            ->post(route('admin.event.participants.receipts.apply', $event), ['confirm' => '1'])
            ->assertSessionHas('warning');

        $secondPass = $sixtyNine->fresh();

        $this->assertSame(40.0, $secondPass->amountPaid());
        $this->assertEquals($firstPass->updated_at, $secondPass->updated_at);
        $this->assertDatabaseCount('event_registration_payments', 2);
    }

    public function test_the_screen_and_the_action_are_refused_without_the_permission(): void
    {
        $event = $this->event();
        $registration = $this->reg69($event);

        // Able to read the participants list, not to touch the ledger.
        $viewer = $this->userWith(['participants.view']);

        $this->actingAs($viewer)
            ->get(route('admin.event.participants.receipts', $event))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('admin.event.participants.receipts.apply', $event), ['confirm' => '1'])
            ->assertForbidden();

        // A GET cannot delete, whoever is asking.
        $this->actingAs($this->corrector())
            ->get(route('admin.event.participants.receipts.apply', $event))
            ->assertStatus(405);

        // And nothing goes without the confirmation.
        $this->actingAs($this->corrector())
            ->post(route('admin.event.participants.receipts.apply', $event))
            ->assertSessionHasErrors('confirm');

        $this->assertCount(2, $registration->fresh()->payments);
        $this->assertSame(80.0, $registration->fresh()->amountPaid());
    }

    public function test_the_participants_screen_offers_the_diagnostic_once_an_event_is_chosen(): void
    {
        $event = $this->event();
        $this->reg69($event);

        $admin = $this->corrector();

        $unfiltered = $this->actingAs($admin)->get(route('admin.event.participants', ['tab' => 'group']));
        $unfiltered->assertOk();
        $unfiltered->assertDontSee('Gateway Receipts');

        $filtered = $this->actingAs($admin)
            ->get(route('admin.event.participants', ['tab' => 'group', 'event' => $event->id]));

        $filtered->assertOk();
        $filtered->assertSee('Gateway Receipts');
        $filtered->assertSee(route('admin.event.participants.receipts', $event), false);

        $viewer = $this->actingAs($this->userWith(['participants.view']))
            ->get(route('admin.event.participants', ['tab' => 'group', 'event' => $event->id]));

        $viewer->assertOk();
        $viewer->assertDontSee('Gateway Receipts');
    }

    /* =====================================================================
     | What happens next: chasing the real balance
     * ================================================================== */

    public function test_the_balance_link_then_charges_forty_and_eighty(): void
    {
        $event = $this->event();

        $sixtyNine = $this->reg69($event);
        $seventyTwo = $this->reg72($event);

        $this->actingAs($this->corrector())
            ->post(route('admin.event.participants.receipts.apply', $event), ['confirm' => '1'])
            ->assertSessionHasNoErrors();

        $charge69 = app(RegistrationBalanceCharge::class)->build($sixtyNine->fresh());
        $charge72 = app(RegistrationBalanceCharge::class)->build($seventyTwo->fresh());

        $this->assertSame(4000, $charge69->amountCents);
        $this->assertSame(8000, $charge72->amountCents);

        // Not the whole charge, which is what the ordinary checkout would ask for.
        $this->assertNotSame(8000, $charge69->amountCents);
        $this->assertNotSame(12000, $charge72->amountCents);
    }

    public function test_the_reminder_skips_a_settled_entry_and_chases_the_corrected_ones(): void
    {
        $this->seed(\Database\Seeders\EventTemplateSeeder::class);

        $event = $this->event();

        $sixtyNine = $this->reg69($event);
        $clean = $this->clean($event);

        $admin = $this->corrector();

        /*
         | Before the correction REG-2026-0069 reads Paid because of the phantom row, so
         | the reminder refuses it and the RM 40.00 would never be chased. That is the
         | practical reason the ledger fix has to land first.
         */
        $this->actingAs($admin)
            ->post(route('admin.event.participants.remind', $sixtyNine))
            ->assertSessionHas('warning');

        $this->assertSame(0, $sixtyNine->fresh()->notifications()->count());

        $this->actingAs($admin)
            ->post(route('admin.event.participants.receipts.apply', $event), ['confirm' => '1'])
            ->assertSessionHasNoErrors();

        // Now it owes RM 40.00, so it is chased, and the email says when the event is.
        $this->actingAs($admin)
            ->post(route('admin.event.participants.remind', $sixtyNine))
            ->assertSessionHas('status');

        $notification = $sixtyNine->fresh()->notifications()->sole();

        $this->assertSame('payment.reminder', $notification->template_key);

        Mail::assertQueued(\App\Mail\EventTemplateMail::class, function ($mail) use ($event) {
            return str_contains($mail->renderedBody, 'RM 40.00')
                // The addition the owner asked for: when the event is, not just which.
                && str_contains($mail->renderedBody, $event->starts_at->format('d M Y'));
        });

        // A settled entry is still refused, which is what keeps a reminder honest.
        $this->actingAs($admin)
            ->post(route('admin.event.participants.remind', $clean))
            ->assertSessionHas('warning');

        $this->assertSame(0, $clean->fresh()->notifications()->count());
    }
}
