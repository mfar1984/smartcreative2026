<?php

namespace Tests\Feature\Payment;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationCheckout;
use App\Models\Setting;
use App\Support\ParticipantOptions;
use App\Support\PaymentSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Fixtures shared by the gateway hand-off tests.
 *
 * Abstract, so PHPUnit does not try to run it. No factories: this project has none
 * beyond UserFactory, so every row is an explicit Model::create() with a uniqid()
 * suffix, following tests/Feature/Registration and tests/Feature/Shop.
 */
abstract class PaymentTestCase extends TestCase
{
    /** Where CHIP's hosted checkout lives. */
    protected const GATE = 'https://gate.chip-in.asia/p/';

    /** CHIP's "public key", generated per test run. Never a real credential. */
    protected string $webhookPublicKey = '';

    /** Its private half, used only to sign the bodies a test posts. */
    protected string $webhookPrivateKey = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing here has anything to prove about mail, and settling an entry sends
        // a receipt. phpunit.xml sets MAIL_MAILER=array; this is the belt to that.
        Mail::fake();
    }

    /* ---------------------------------------------------------------------
     | Settings
     * ------------------------------------------------------------------ */

    /** CHIP configured, with a webhook this test can sign for. */
    protected function configureGateway(): void
    {
        [$this->webhookPrivateKey, $this->webhookPublicKey] = $this->keypair();

        Setting::write('integration.payments.provider', PaymentSettings::PROVIDER_CHIP, 'integration.payments');
        Setting::write('integration.payments.chip_brand_id', 'brand-' . uniqid(), 'integration.payments');
        Setting::write('integration.payments.chip_api_key', 'key-' . uniqid(), 'integration.payments');
        Setting::write('integration.payments.chip_webhook_public_key', $this->webhookPublicKey, 'integration.payments');
        Setting::write('integration.payments.currency', 'MYR', 'integration.payments');
    }

    /* ---------------------------------------------------------------------
     | Rows
     * ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function event(array $overrides = []): Event
    {
        return Event::create($overrides + [
            'slug' => 'event-' . uniqid(),
            'title' => 'HARI SUKAN NEGARA 2026',
            'category' => 'Community',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'location' => 'Sibu',
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_INDIVIDUAL,
            'fee' => 100,
            'seats_total' => 0,
            'min_players' => 1,
        ]);
    }

    /**
     * An entry as the public controller would have written it, with one payer on it.
     *
     * The participant is not decoration: ChipGateway needs an email to raise the
     * purchase against, and refuses without one.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function registration(?Event $event = null, array $overrides = []): EventRegistration
    {
        $event ??= $this->event();

        $registration = EventRegistration::create($overrides + [
            'event_id' => $event->id,
            'reference' => EventRegistration::nextReference(),
            'mode' => $event->registration_mode,
            'status' => EventRegistration::STATUS_PENDING,
            'payment_status' => EventRegistration::PAYMENT_UNPAID,
            'registration_fee' => 100,
            'addons_total' => 0,
            'amount' => 100,
            'amount_paid' => 0,
        ]);

        $registration->participants()->create([
            'role' => ParticipantOptions::ROLE_PARTICIPANT,
            'full_name' => 'Ahmad Bin Ali',
            'ic_number' => '9001010' . random_int(10000, 99999),
            'address_line_1' => '1 Jalan Sibu',
            'city' => 'Sibu',
            'state' => 'Sarawak',
            'country' => 'Malaysia',
            'phone' => '0140000001',
            'email' => 'payer-' . uniqid() . '@example.com',
            'gender' => 'male',
            'race' => 'malay',
        ]);

        return $registration->fresh(['participants', 'event']);
    }

    /**
     * One checkout already opened at the gateway for this entry.
     *
     * Written the way markPending() writes it, including the column on the entry, so
     * the row under test is the shape the live site produces.
     */
    protected function attempt(
        EventRegistration $registration,
        string $purchaseId,
        ?CarbonInterface $openedAt = null,
    ): EventRegistrationCheckout {
        $checkout = $registration->checkouts()->create([
            'purchase_id' => $purchaseId,
            'checkout_url' => self::GATE . $purchaseId,
            'gateway' => 'chip',
            'opened_at' => $openedAt ?? now(),
        ]);

        $registration->forceFill([
            'payment_reference' => $purchaseId,
            'payment_status' => EventRegistration::PAYMENT_PENDING,
        ])->save();

        return $checkout;
    }

    /* ---------------------------------------------------------------------
     | The gateway
     * ------------------------------------------------------------------ */

    /**
     * CHIP reports $purchaseId as $status, and accepts any new purchase as $fresh.
     *
     * Two stubs, and the order of them is the point: the create endpoint is keyed
     * without a wildcard so it matches only the exact collection URL, leaving a
     * lookup of one purchase to fall through to the second.
     *
     * @param  CarbonInterface|null  $statusAt  when the purchase entered that status
     */
    protected function fakeChip(
        string $purchaseId,
        string $status,
        ?CarbonInterface $statusAt = null,
        string $fresh = 'pur_fresh',
    ): void {
        Http::fake([
            'gate.chip-in.asia/api/v1/purchases/' => Http::response([
                'id' => $fresh,
                'checkout_url' => self::GATE . $fresh,
            ]),

            'gate.chip-in.asia/api/v1/purchases/*' => Http::response(
                $this->purchaseObject($purchaseId, $status, $statusAt),
            ),
        ]);
    }

    /** CHIP accepts a purchase and knows nothing about any other. */
    protected function fakeChipPurchase(string $fresh = 'pur_fresh'): void
    {
        Http::fake([
            'gate.chip-in.asia/api/v1/purchases/' => Http::response([
                'id' => $fresh,
                'checkout_url' => self::GATE . $fresh,
            ]),
        ]);
    }

    /**
     * A CHIP purchase object in CHIP's own shape.
     *
     * `status_history` is oldest first, which is how CHIP sends it, and carries unix
     * timestamps. It is where the age of an attempt is read from.
     *
     * @return array<string, mixed>
     */
    protected function purchaseObject(string $purchaseId, string $status, ?CarbonInterface $statusAt = null): array
    {
        $at = ($statusAt ?? now())->copy();
        $created = $at->copy()->subMinutes(2);

        return [
            'id' => $purchaseId,
            'status' => $status,
            'checkout_url' => self::GATE . $purchaseId,
            'reference' => 'REG-UNKNOWN',
            'created_on' => $created->timestamp,
            'updated_on' => $at->timestamp,
            'purchase' => ['total' => 10000, 'currency' => 'MYR'],
            'status_history' => [
                ['status' => 'created', 'timestamp' => $created->timestamp],
                ['status' => $status, 'timestamp' => $at->timestamp],
            ],
        ];
    }

    /**
     * A webhook body in CHIP's shape, carrying what it took in cents.
     *
     * @return array<string, mixed>
     */
    protected function webhookPayload(
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
            'payment' => ['amount' => $cents, 'currency' => 'MYR', 'paid_on' => now()->timestamp],
        ];
    }

    /* ---------------------------------------------------------------------
     | Requests
     * ------------------------------------------------------------------ */

    /** Press Pay Now, on a properly signed link. */
    protected function pressPay(EventRegistration $registration)
    {
        return $this->post(URL::temporarySignedRoute(
            'registration.payment.pay',
            now()->addDay(),
            ['reference' => $registration->reference],
        ));
    }

    /** The payment page itself. */
    protected function paymentPage(EventRegistration $registration)
    {
        return $this->get(URL::temporarySignedRoute(
            'registration.payment',
            now()->addDay(),
            ['reference' => $registration->reference],
        ));
    }

    /**
     * POST a body to the webhook, signed over the exact bytes sent.
     *
     * json_encode once and post that same string: the controller verifies against the
     * raw body, so signing a re-encoded array would break the signature.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function postWebhook(array $payload)
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
     * The config file is written because openssl_pkey_new() reads openssl.cnf and
     * will not generate a key without one, and plenty of PHP builds — the Windows one
     * this was written on among them — ship neither it nor OPENSSL_CONF. Signing and
     * verifying do not read it.
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
}
