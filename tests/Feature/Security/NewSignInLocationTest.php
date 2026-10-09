<?php

namespace Tests\Feature\Security;

use App\Mail\NewSignInLocation;
use App\Models\User;
use App\Models\UserKnownIp;
use App\Services\Security\LoginLocationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * The warning sent when an account signs in from an address it has not been seen
 * from before.
 *
 * The clock is pinned for the whole class, because the recorded moments and the
 * time printed in the email are both asserted, and it is released again in
 * tearDown so nothing after this file inherits it.
 */
class NewSignInLocationTest extends SecurityTestCase
{
    private const NOW = '2026-10-13 02:30:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** An account that has signed in before, from the address given. */
    private function established(string $knownIp = '203.0.113.10'): User
    {
        $user = $this->administrator();

        UserKnownIp::create([
            'user_id' => $user->id,
            'ip_address' => $knownIp,
            'hits' => 1,
            'first_seen_at' => now()->subDays(30),
            'last_seen_at' => now()->subDay(),
        ]);

        return $user;
    }

    /* ---------------------------------------------------------------------
     | Signing in
     * ------------------------------------------------------------------ */

    public function test_a_new_address_for_an_established_account_sends_one_email_and_records_it(): void
    {
        $user = $this->established();

        $this->fromIp('198.51.100.42')
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.42', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Test Browser'])
            ->signIn($user->username)
            ->assertRedirect();

        Mail::assertQueuedCount(1);
        Mail::assertQueued(NewSignInLocation::class, function (NewSignInLocation $mail) use ($user) {
            return $mail->hasTo($user->email)
                && $mail->ipAddress === '198.51.100.42'
                && $mail->userAgent === 'Mozilla/5.0 Test Browser'
                // The office clock, not UTC: 02:30 UTC is 10:30 in Kuala Lumpur.
                && $mail->signedInAt === \App\Support\LocalTime::format(Carbon::parse(self::NOW));
        });

        $this->assertDatabaseHas('user_known_ips', [
            'user_id' => $user->id,
            'ip_address' => '198.51.100.42',
            'hits' => 1,
            'user_agent' => 'Mozilla/5.0 Test Browser',
        ]);

        $recorded = UserKnownIp::where('ip_address', '198.51.100.42')->firstOrFail();
        $this->assertTrue(Carbon::parse(self::NOW)->equalTo($recorded->first_seen_at));
        $this->assertTrue(Carbon::parse(self::NOW)->equalTo($recorded->last_seen_at));

        // Visible on the Logging screen as a warning.
        $this->assertDatabaseHas('activity_logs', [
            'action' => LoginLocationService::ACTION,
            'user_id' => $user->id,
            'level' => 'warn',
            'ip_address' => '198.51.100.42',
        ]);
    }

    public function test_an_address_already_recorded_sends_nothing(): void
    {
        $user = $this->established('203.0.113.10');

        $this->fromIp('203.0.113.10')->signIn($user->username)->assertRedirect();

        Mail::assertNothingQueued();
        Mail::assertNothingSent();

        $this->assertDatabaseMissing('activity_logs', ['action' => LoginLocationService::ACTION]);

        // The sighting is counted and the moment moved on, the first one kept.
        $known = UserKnownIp::where('user_id', $user->id)->sole();

        $this->assertSame(2, $known->hits);
        $this->assertTrue(Carbon::parse(self::NOW)->equalTo($known->last_seen_at));
        $this->assertTrue(Carbon::parse(self::NOW)->subDays(30)->equalTo($known->first_seen_at));
    }

    public function test_a_first_ever_sign_in_records_the_address_and_sends_nothing(): void
    {
        // No known addresses at all: nothing for this one to be new relative to.
        $user = $this->administrator();

        $this->fromIp('198.51.100.7')->signIn($user->username)->assertRedirect();

        Mail::assertNothingQueued();

        $this->assertDatabaseMissing('activity_logs', ['action' => LoginLocationService::ACTION]);
        $this->assertDatabaseHas('user_known_ips', [
            'user_id' => $user->id,
            'ip_address' => '198.51.100.7',
            'hits' => 1,
        ]);
    }

    public function test_the_second_address_after_a_first_ever_sign_in_is_warned_about(): void
    {
        $user = $this->administrator();

        $this->fromIp('198.51.100.7')->signIn($user->username)->assertRedirect();
        Mail::assertNothingQueued();

        $this->post(route('admin.logout'));

        $this->fromIp('198.51.100.8')->signIn($user->username)->assertRedirect();

        Mail::assertQueuedCount(1);
        $this->assertSame(2, UserKnownIp::where('user_id', $user->id)->count());
    }

    /* ---------------------------------------------------------------------
     | The first-deploy trap
     * ------------------------------------------------------------------ */

    /**
     * The migration seeds users.last_login_ip, so the whole admin is not emailed at
     * once the first time everybody signs in after deploy. Re-run here against a
     * user that only has the old column filled in.
     */
    public function test_an_existing_account_signing_in_from_its_stored_last_login_ip_is_not_warned(): void
    {
        $user = $this->administrator();

        $user->forceFill([
            'last_login_at' => now()->subWeek(),
            'last_login_ip' => '203.0.113.200',
        ])->save();

        $this->assertDatabaseCount('user_known_ips', 0);

        // What the migration does, for this one row.
        $this->seedKnownAddressesFromMigration();

        $this->assertDatabaseHas('user_known_ips', [
            'user_id' => $user->id,
            'ip_address' => '203.0.113.200',
        ]);

        $this->fromIp('203.0.113.200')->signIn($user->username)->assertRedirect();

        Mail::assertNothingQueued();
        $this->assertDatabaseMissing('activity_logs', ['action' => LoginLocationService::ACTION]);
    }

    /**
     * Re-runs the migration's backfill, so the seeding behaviour is tested rather
     * than assumed. RefreshDatabase has already migrated, which on a database with
     * no users seeded nothing.
     */
    private function seedKnownAddressesFromMigration(): void
    {
        $migration = require database_path('migrations/2026_10_13_090000_create_user_known_ips_table.php');

        $seed = (new \ReflectionClass($migration))->getMethod('seedKnownAddresses');
        $seed->setAccessible(true);
        $seed->invoke($migration);
    }

    /* ---------------------------------------------------------------------
     | The switch, and failures
     * ------------------------------------------------------------------ */

    public function test_the_warning_respects_its_switch(): void
    {
        $this->security(['new_location_warning' => '0']);

        $user = $this->established();

        $this->fromIp('198.51.100.99')->signIn($user->username)->assertRedirect();

        Mail::assertNothingQueued();
        $this->assertDatabaseMissing('activity_logs', ['action' => LoginLocationService::ACTION]);

        // Still recorded, so switching the warning back on does not then treat every
        // address it was already watching as new.
        $this->assertDatabaseHas('user_known_ips', [
            'user_id' => $user->id,
            'ip_address' => '198.51.100.99',
        ]);
    }

    public function test_a_failing_mailer_does_not_prevent_signing_in(): void
    {
        // Mail::fake() swallows failures, so the real manager is used and pointed at
        // a transport that does not exist. Mail::to() throws on resolving it.
        $this->refreshApplicationWithRealMailer();

        config(['mail.default' => 'this-mailer-does-not-exist']);

        $user = $this->established();

        $this->fromIp('198.51.100.123')
            ->signIn($user->username)
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($user->fresh());

        // The address is still on record and the warning still logged; only the
        // email was lost, which is what the catch is for.
        $this->assertDatabaseHas('user_known_ips', [
            'user_id' => $user->id,
            'ip_address' => '198.51.100.123',
        ]);
        $this->assertDatabaseHas('activity_logs', ['action' => LoginLocationService::ACTION]);
    }

    /** Mail::fake() is set in setUp; this test needs the real manager back. */
    private function refreshApplicationWithRealMailer(): void
    {
        $this->app->forgetInstance('mailer');
        $this->app->forgetInstance('mail.manager');

        \Illuminate\Support\Facades\Mail::clearResolvedInstances();
    }

    public function test_an_account_without_an_email_address_is_still_recorded(): void
    {
        // users.email is NOT NULL, so an account with no address has a blank one.
        $user = $this->established();
        $user->forceFill(['email' => ''])->save();

        $this->fromIp('198.51.100.55')->signIn($user->username)->assertRedirect();

        Mail::assertNothingQueued();

        $this->assertDatabaseHas('user_known_ips', [
            'user_id' => $user->id,
            'ip_address' => '198.51.100.55',
        ]);
        $this->assertDatabaseHas('activity_logs', ['action' => LoginLocationService::ACTION]);
    }

    /* ---------------------------------------------------------------------
     | Interaction with Feature 1
     * ------------------------------------------------------------------ */

    /**
     * The whole reason the addresses live in their own table: with activity logging
     * switched off there would be nothing in activity_logs to compare against, and
     * the warning would silently stop working.
     */
    public function test_the_warning_still_works_with_activity_logging_switched_off(): void
    {
        $this->security(['activity_log_enabled' => '0']);

        $user = $this->established();

        $this->fromIp('198.51.100.21')->signIn($user->username)->assertRedirect();

        Mail::assertQueuedCount(1);

        // auth.new_location is on AdminLogger::ALWAYS_RECORDED, so the warning is
        // written despite the switch.
        $this->assertDatabaseHas('activity_logs', [
            'action' => LoginLocationService::ACTION,
            'level' => 'warn',
        ]);
    }

    /* ---------------------------------------------------------------------
     | The service on its own
     * ------------------------------------------------------------------ */

    public function test_a_blank_address_is_ignored(): void
    {
        $user = $this->established();

        $this->assertFalse(app(LoginLocationService::class)->record($user, ''));
        $this->assertSame(1, UserKnownIp::where('user_id', $user->id)->count());
    }

    public function test_one_row_per_account_per_address(): void
    {
        $user = $this->established('203.0.113.10');
        $other = $this->administrator();

        $service = app(LoginLocationService::class);

        $service->record($user, '203.0.113.10');
        $service->record($user, '203.0.113.10');
        $service->record($other, '203.0.113.10');

        $this->assertSame(1, UserKnownIp::where('user_id', $user->id)->count());
        $this->assertSame(1, UserKnownIp::where('user_id', $other->id)->count());
        $this->assertSame(3, UserKnownIp::where('user_id', $user->id)->sole()->hits);
    }

    public function test_a_long_user_agent_is_trimmed_to_the_column(): void
    {
        $user = $this->established();

        app(LoginLocationService::class)->record($user, '198.51.100.44', str_repeat('a', 900));

        $this->assertSame(512, strlen(UserKnownIp::where('ip_address', '198.51.100.44')->firstOrFail()->user_agent));
    }
}
