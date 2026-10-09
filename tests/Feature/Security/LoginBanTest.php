<?php

namespace Tests\Feature\Security;

use App\Models\ActivityLog;
use App\Models\BannedIp;
use App\Services\AdminLogger;
use App\Support\LocalTime;
use App\Support\SecuritySettings;

/**
 * Banning an IP after repeated failed admin sign ins, and the safety nets around it.
 *
 * Covers: ten failures from one IP inside the window ban it and nine do not;
 * failures outside the window do not accumulate; a banned IP still gets the sign-in
 * form with the notice; a banned IP's POST is refused alike for right and wrong
 * credentials and counts nothing further; a super admin signs in through a ban,
 * which lifts it and writes a warning (safety net B), with both limiters still in
 * force on that path; an allowlisted IP is never counted or banned (A); a ban ends on
 * its own (E); bans switched off count and block nothing; and with every default an
 * existing admin signs in exactly as before.
 */
class LoginBanTest extends SecurityTestCase
{
    private const IP = '203.0.113.10';

    public function test_ten_failures_from_one_ip_inside_the_window_ban_it_and_nine_do_not(): void
    {
        // Every default: ten failures inside fifteen minutes, banned for thirty.
        $this->fromIp(self::IP);

        for ($i = 1; $i <= 9; $i++) {
            $this->failedSignIn()->assertSessionHasErrors(['username' => __('auth.failed')]);
        }

        $this->assertFalse($this->bans()->isBanned(self::IP));
        $this->assertSame(0, BannedIp::query()->count());
        $this->assertSame(9, $this->bans()->failures(self::IP));

        $this->failedSignIn();

        $this->assertTrue($this->bans()->isBanned(self::IP));

        $ban = BannedIp::query()->sole();
        $this->assertSame(self::IP, $ban->ip_address);
        $this->assertSame(10, $ban->failed_attempts);
        $this->assertSame(30, (int) round($ban->banned_at->diffInMinutes($ban->expires_at)));
        $this->assertTrue($ban->expires_at->isFuture());

        // The count is spent on the ban.
        $this->assertSame(0, $this->bans()->failures(self::IP));

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'auth.banned',
            'level' => AdminLogger::LEVEL_WARN,
            'ip_address' => self::IP,
        ]);

        // Another network is untouched.
        $this->assertFalse($this->bans()->isBanned('203.0.113.11'));
    }

    public function test_failures_outside_the_window_do_not_accumulate(): void
    {
        $this->security(['ban_after_failures' => '3', 'ban_window_minutes' => '15']);
        $this->fromIp(self::IP);

        $this->failedSignIn();
        $this->failedSignIn();

        $this->travel(16)->minutes();

        // The first two have aged out, so these are one and two again.
        $this->failedSignIn();
        $this->failedSignIn();

        $this->assertFalse($this->bans()->isBanned(self::IP));
        $this->assertSame(2, $this->bans()->failures(self::IP));

        // A third inside the new window is the one that bans.
        $this->failedSignIn();

        $this->assertTrue($this->bans()->isBanned(self::IP));
    }

    public function test_a_banned_ip_can_still_open_the_sign_in_form_and_is_told_until_when(): void
    {
        $ban = $this->activeBan(self::IP);

        $this->fromIp(self::IP)
            ->get(route('admin.login'))
            ->assertOk()
            ->assertSee('name="username"', false)
            ->assertSee('temporarily blocked')
            ->assertSee(LocalTime::format($ban->expires_at));

        // Somebody on another network sees no notice at all.
        $this->fromIp('198.51.100.20')
            ->get(route('admin.login'))
            ->assertOk()
            ->assertDontSee('temporarily blocked');
    }

    public function test_a_banned_ip_is_refused_alike_for_right_and_wrong_credentials_and_nothing_more_is_counted(): void
    {
        $admin = $this->administrator();
        $ban = $this->activeBan(self::IP);
        $notice = $this->bans()->blockedMessage($ban);

        $this->fromIp(self::IP);

        $right = $this->signIn($admin->username);
        $right->assertRedirect();
        $right->assertSessionHasErrors(['username' => $notice]);
        $this->assertGuest();

        $wrong = $this->signIn($admin->username, 'not-the-password');
        $wrong->assertRedirect();
        $wrong->assertSessionHasErrors(['username' => $notice]);
        $this->assertGuest();

        // Identical answers, so neither says whether the password was right.
        $this->assertSame($right->headers->get('Location'), $wrong->headers->get('Location'));

        // Nothing counted, the ban untouched, and nobody signed in.
        $this->assertSame(0, $this->bans()->failures(self::IP));
        $this->assertSame(1, BannedIp::query()->count());
        $this->assertSame(10, $ban->fresh()->failed_attempts);
        $this->assertTrue($ban->fresh()->expires_at->eq($ban->expires_at));
        $this->assertDatabaseMissing('activity_logs', ['action' => 'auth.login']);
    }

    public function test_a_super_admin_signs_in_through_a_ban_which_lifts_it_and_logs_a_warning(): void
    {
        $owner = $this->superAdmin();
        $this->activeBan(self::IP);

        $this->fromIp(self::IP)
            ->signIn($owner->username)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($owner);
        $this->assertFalse($this->bans()->isBanned(self::IP));
        $this->assertSame(0, BannedIp::query()->count());

        $entry = ActivityLog::query()->where('action', 'auth.ban_bypass')->sole();
        $this->assertSame(AdminLogger::LEVEL_WARN, $entry->level);
        $this->assertSame(self::IP, $entry->ip_address);
        $this->assertSame($owner->id, $entry->user_id);
    }

    public function test_the_super_admin_exception_is_still_held_to_the_per_account_limiter(): void
    {
        $owner = $this->superAdmin();
        $this->activeBan(self::IP);
        $this->fromIp(self::IP);

        // Five wrong guesses at the owner's password from the banned network...
        for ($i = 1; $i <= 5; $i++) {
            $this->signIn($owner->username, 'guess-' . $i);
        }

        // ...and the right one is now refused by the per-account limiter.
        $this->signIn($owner->username)
            ->assertSessionHasErrors('username');

        $this->assertStringContainsString('Too many login attempts', session('errors')->first('username'));
        $this->assertGuest();
        $this->assertTrue($this->bans()->isBanned(self::IP));
    }

    public function test_the_super_admin_exception_is_still_held_to_the_sign_in_rate_limit(): void
    {
        $this->security(['login_attempts_per_minute' => '3']);

        $owner = $this->superAdmin();
        $this->activeBan(self::IP);
        $this->fromIp(self::IP);

        $this->failedSignIn();
        $this->failedSignIn();
        $this->failedSignIn();

        // The fourth request this minute is turned away before credentials are read.
        $this->signIn($owner->username)->assertStatus(429);

        $this->assertGuest();
        $this->assertTrue($this->bans()->isBanned(self::IP));
    }

    public function test_an_inactive_super_admin_gets_the_ordinary_notice_and_the_ban_stays(): void
    {
        $owner = $this->superAdmin();
        $owner->forceFill(['is_active' => false])->save();
        $ban = $this->activeBan(self::IP);

        $this->fromIp(self::IP)
            ->signIn($owner->username)
            ->assertSessionHasErrors(['username' => $this->bans()->blockedMessage($ban)]);

        $this->assertGuest();
        $this->assertTrue($this->bans()->isBanned(self::IP));
        $this->assertDatabaseMissing('activity_logs', ['action' => 'auth.ban_bypass']);
    }

    public function test_an_allowlisted_ip_is_never_counted_or_banned(): void
    {
        $this->security([
            'ip_allowlist' => "203.0.113.0/24\n198.51.100.7",
            'ban_after_failures' => '3',
        ]);

        $this->fromIp(self::IP);

        for ($i = 1; $i <= 6; $i++) {
            $this->failedSignIn()->assertSessionHasErrors(['username' => __('auth.failed')]);
        }

        $this->assertSame(0, $this->bans()->failures(self::IP));
        $this->assertSame(0, BannedIp::query()->count());

        // A ban left over from before the address was listed no longer bars it.
        $this->activeBan('198.51.100.7');
        $admin = $this->administrator();

        $this->fromIp('198.51.100.7')
            ->get(route('admin.login'))
            ->assertDontSee('temporarily blocked');

        $this->signIn($admin->username)->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_ban_lifts_on_its_own_when_its_time_is_up(): void
    {
        $this->security(['ban_after_failures' => '3', 'ban_duration_minutes' => '5']);
        $admin = $this->administrator();
        $this->fromIp(self::IP);

        $this->failedSignIn();
        $this->failedSignIn();
        $this->failedSignIn();

        $this->assertTrue($this->bans()->isBanned(self::IP));

        $this->travel(4)->minutes();
        $this->assertTrue($this->bans()->isBanned(self::IP));

        $this->travel(2)->minutes();
        $this->assertFalse($this->bans()->isBanned(self::IP));

        // Nothing had to delete the row; it simply no longer counts.
        $this->assertSame(1, BannedIp::query()->count());

        $this->get(route('admin.login'))->assertDontSee('temporarily blocked');

        $this->signIn($admin->username)->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_with_bans_switched_off_nothing_is_counted_and_nothing_is_blocked(): void
    {
        $this->security(['ban_enabled' => '0', 'ban_after_failures' => '3']);
        $this->fromIp(self::IP);

        for ($i = 1; $i <= 6; $i++) {
            $this->failedSignIn();
        }

        $this->assertSame(0, $this->bans()->failures(self::IP));
        $this->assertSame(0, BannedIp::query()->count());

        // A row already in force is ignored too.
        $this->activeBan(self::IP);
        $admin = $this->administrator();

        $this->assertFalse($this->bans()->isBanned(self::IP));
        $this->get(route('admin.login'))->assertDontSee('temporarily blocked');

        $this->signIn($admin->username)->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_with_every_default_an_existing_admin_signs_in_exactly_as_before(): void
    {
        $this->assertTrue(SecuritySettings::banEnabled());
        $this->assertSame(10, SecuritySettings::banAfterFailures());
        $this->assertSame(15, SecuritySettings::banWindowMinutes());
        $this->assertSame(30, SecuritySettings::banDurationMinutes());
        $this->assertSame(10, SecuritySettings::loginAttemptsPerMinute());
        $this->assertSame(0, SecuritySettings::adminRequestsPerMinute());
        $this->assertSame([], SecuritySettings::ipAllowlist());

        $admin = $this->administrator();

        // One wrong password first, as people do.
        $this->signIn($admin->username, 'typo')->assertSessionHasErrors(['username' => __('auth.failed')]);

        $this->signIn($admin->username)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.login', 'user_id' => $admin->id]);
        $this->assertSame(0, BannedIp::query()->count());

        // The success cleared the address's count.
        $this->assertSame(0, $this->bans()->failures('127.0.0.1'));

        $this->get(route('admin.dashboard'))->assertOk();
    }
}
