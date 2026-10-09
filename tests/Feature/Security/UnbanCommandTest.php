<?php

namespace Tests\Feature\Security;

use App\Models\BannedIp;

/**
 * `php artisan security:unban`, the way back in from SSH (safety net D).
 *
 * Covers: one address is unbanned and its failure count cleared, other bans kept;
 * --all lifts every ban; each prints exactly what it cleared; nothing to lift is
 * said plainly; a missing or malformed argument fails with guidance and changes
 * nothing.
 */
class UnbanCommandTest extends SecurityTestCase
{
    public function test_one_address_is_unbanned_and_its_count_cleared(): void
    {
        $this->activeBan('203.0.113.80');
        $this->activeBan('203.0.113.81');
        $this->bans()->recordFailure('203.0.113.80');
        $this->bans()->recordFailure('203.0.113.80');

        $this->artisan('security:unban', ['ip' => '203.0.113.80'])
            ->expectsOutputToContain('Lifted the ban on 203.0.113.80')
            ->expectsOutputToContain('Cleared the failed sign-in count for 203.0.113.80')
            ->assertSuccessful();

        $this->assertFalse($this->bans()->isBanned('203.0.113.80'));
        $this->assertSame(0, $this->bans()->failures('203.0.113.80'));
        $this->assertDatabaseMissing('banned_ips', ['ip_address' => '203.0.113.80']);

        // Only the address named.
        $this->assertTrue($this->bans()->isBanned('203.0.113.81'));

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'settings.security.unban',
            'actor_label' => 'Console',
        ]);
    }

    public function test_an_address_with_no_ban_is_reported_as_such(): void
    {
        $this->bans()->recordFailure('203.0.113.82');

        $this->artisan('security:unban', ['ip' => '203.0.113.82'])
            ->expectsOutputToContain('203.0.113.82 had no active ban')
            ->expectsOutputToContain('Cleared the failed sign-in count for 203.0.113.82')
            ->assertSuccessful();

        $this->assertSame(0, $this->bans()->failures('203.0.113.82'));
    }

    public function test_all_lifts_every_ban_and_lists_each_one(): void
    {
        $this->activeBan('203.0.113.90');
        $this->activeBan('2001:db8::1');

        BannedIp::create([
            'ip_address' => '203.0.113.91',
            'failed_attempts' => 10,
            'reason' => 'expired',
            'banned_at' => now()->subDay(),
            'expires_at' => now()->subHours(23),
        ]);

        $this->artisan('security:unban', ['--all' => true])
            ->expectsOutputToContain('Lifted the ban on 203.0.113.90')
            ->expectsOutputToContain('Lifted the ban on 2001:db8::1')
            ->expectsOutputToContain('Lifted 2 bans')
            ->doesntExpectOutputToContain('203.0.113.91')
            ->assertSuccessful();

        $this->assertSame(0, BannedIp::query()->count());
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'settings.security.unban_all',
            'actor_label' => 'Console',
        ]);
    }

    public function test_all_with_nothing_banned_says_so(): void
    {
        $this->artisan('security:unban', ['--all' => true])
            ->expectsOutputToContain('Nothing to lift')
            ->assertSuccessful();
    }

    public function test_no_argument_fails_and_shows_what_is_banned(): void
    {
        $this->activeBan('203.0.113.95');

        $this->artisan('security:unban')
            ->expectsOutputToContain('Pass the IP address to unban, or --all')
            ->expectsOutputToContain('203.0.113.95')
            ->assertFailed();

        $this->assertTrue($this->bans()->isBanned('203.0.113.95'));
    }

    public function test_a_malformed_address_fails_and_changes_nothing(): void
    {
        $this->activeBan('203.0.113.96');

        $this->artisan('security:unban', ['ip' => 'not-an-ip'])
            ->expectsOutputToContain('is not a valid IP address')
            ->assertFailed();

        $this->artisan('security:unban', ['ip' => '203.0.113.96', '--all' => true])
            ->expectsOutputToContain('not both')
            ->assertFailed();

        $this->assertTrue($this->bans()->isBanned('203.0.113.96'));
    }
}
