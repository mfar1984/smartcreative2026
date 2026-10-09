<?php

namespace Tests\Feature\Security;

use App\Models\BannedIp;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Setting;
use App\Support\ParticipantOptions;
use App\Support\SecuritySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Shop\ShopPaymentTestCase;

/**
 * The ban, the sign-in limiter and the allowlist stop at the admin door.
 *
 * On event day hundreds of participants on the stadium Wi-Fi share one public IP,
 * and the payment gateway calls back from fixed addresses. So the address used here
 * is the worst case: banned AND outside a non-empty allowlist, at the same time.
 * From it, the public homepage, a public registration and a signed CHIP webhook must
 * all behave exactly as they would from anywhere else, and none of those requests
 * may so much as query the ban table or the security settings.
 *
 * Built on the shop payment fixtures for the CHIP keypair and gateway settings.
 * Mail, the queue and outbound HTTP are faked, so nothing can reach a participant.
 */
class SecurityScopeTest extends ShopPaymentTestCase
{
    use RefreshDatabase;

    private const VENUE_IP = '203.0.113.200';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
        Http::fake();

        SecuritySettings::flush();
    }

    protected function tearDown(): void
    {
        SecuritySettings::flush();

        parent::tearDown();
    }

    public function test_the_public_site_registration_and_the_chip_webhook_ignore_bans_and_the_allowlist(): void
    {
        $this->gatewayShopSettings();

        BannedIp::create([
            'ip_address' => self::VENUE_IP,
            'failed_attempts' => 10,
            'reason' => '10 failed sign-in attempts within 15 minutes',
            'banned_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);

        Setting::write('security.ip_allowlist', '198.51.100.0/24', 'security');
        Setting::write('security.login_attempts_per_minute', '3', 'security');
        SecuritySettings::flush();

        $event = $this->groupingEvent();

        $this->withServerVariables(['REMOTE_ADDR' => self::VENUE_IP]);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql . ' ' . json_encode($query->bindings);
        });

        // The homepage.
        $this->get('/')->assertOk();

        // A public registration, more often than the admin sign-in limit of 3 allows.
        foreach (range(1, 4) as $n) {
            $this->post(route('registration.store', ['event' => $event->slug]), [
                'team_name' => 'Squad ' . $n,
                'participants' => [$this->person($n * 10 + 1), $this->person($n * 10 + 2), $this->person($n * 10 + 3)],
            ])->assertSessionHasNoErrors()->assertRedirect();
        }

        $this->assertSame(4, EventRegistration::query()->count());

        // The gateway telling us one of them paid.
        $registration = EventRegistration::query()->orderBy('id')->firstOrFail();
        $registration->forceFill(['payment_reference' => 'pur_venue'])->save();

        $body = json_encode([
            'event_type' => 'purchase.paid',
            'id' => 'pur_venue',
            'reference' => $registration->reference,
            'status' => 'paid',
            'purchase' => ['total' => (int) round((float) $registration->fresh()->amount * 100)],
            'payment' => ['amount' => (int) round((float) $registration->fresh()->amount * 100)],
        ]);

        openssl_sign($body, $raw, $this->webhookPrivateKey, OPENSSL_ALGO_SHA256);

        $this->call(
            'POST',
            route('payments.chip.webhook'),
            [],
            [],
            [],
            ['HTTP_X_SIGNATURE' => base64_encode($raw), 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => self::VENUE_IP],
            $body,
        )->assertOk();

        $this->assertSame(EventRegistration::PAYMENT_PAID, $registration->fresh()->payment_status);

        // Not one of those requests looked at a ban or a security setting.
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('banned_ips', $sql);
            $this->assertStringNotContainsString('"security"', $sql);
        }

        // The ban itself is still there for the admin sign in.
        $this->get(route('admin.login'))->assertSee('temporarily blocked');
    }

    private function groupingEvent(): Event
    {
        return Event::create([
            'slug' => 'event-' . uniqid(),
            'title' => 'Stadium Cup',
            'category' => 'Community',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_GROUPING,
            'fee' => 10,
            'seats_total' => 100,
            'min_players' => 3,
            'max_players' => 5,
        ]);
    }

    /** @return array<string, string> */
    private function person(int $n): array
    {
        return [
            'role' => ParticipantOptions::ROLE_PARTICIPANT,
            'full_name' => 'Member ' . $n,
            'ic_number' => '900101' . str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'address_line_1' => $n . ' Jalan Dua',
            'city' => 'Shah Alam',
            'state' => 'Selangor',
            'country' => 'Malaysia',
            'phone' => '01200000' . str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => 'member' . $n . '@example.test',
            'gender' => 'female',
            'race' => 'malay',
        ];
    }
}
