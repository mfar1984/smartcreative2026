<?php

namespace Tests\Feature\Security;

use App\Models\ActivityLog;
use App\Models\BannedIp;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\SecuritySettings;
use Illuminate\Support\Facades\Auth;

/**
 * Blocking is driven by REPETITION, and it ships disarmed.
 *
 * A prober trips ten refusals in a minute; a real person trips none. So the block
 * is counted off the Security Log rather than decided by a pattern, and it reuses
 * the ban that already exists — the same banned_ips row, the same Banned IPs list
 * on the Security tab, the same Remove and Clear all buttons.
 *
 * The two exemptions matter as much as the banning does. Locking the office out of
 * its own admin in the middle of an event is a worse outcome than anything this
 * prevents, so a super admin and an allowlisted address can never be blocked by it.
 */
class SecurityEventBanTest extends SecurityTestCase
{
    private const PROBER = '198.51.100.44';

    private function arm(int $after = 10, int $window = 10): void
    {
        $this->security([
            'security_ban_enabled' => '1',
            'security_ban_after_events' => (string) $after,
            'security_ban_window_minutes' => (string) $window,
        ]);
    }

    /**
     * Walk the admin URL space, which is what a prober does and what a real person
     * never does. Separate paths, so each refusal is its own row.
     */
    private function probe(User $user, int $times, string $ip = self::PROBER): void
    {
        $paths = [
            'admin.settings.logging',
            'admin.settings.roles',
            'admin.settings.users',
            'admin.payments.overview',
            'admin.coupons.index',
        ];

        for ($i = 0; $i < $times; $i++) {
            $this->actingAs($user)
                ->fromIp($ip)
                ->get(route($paths[$i % count($paths)], ['probe' => $i]));
        }
    }

    /* ------------------------------------------------------------------ */

    public function test_banning_is_off_by_default_so_a_flood_of_refusals_bans_nobody(): void
    {
        // THE SHIPPED DEFAULT. Counting runs; nothing is barred. The owner reads the
        // log for a week before arming it.
        $this->assertFalse(SecuritySettings::securityBanEnabled());

        $user = $this->userWith(['admin.access']);

        $this->probe($user, 30);

        $this->assertGreaterThanOrEqual(20, (int) SecurityEvent::query()->sum('hits'));
        $this->assertSame(0, BannedIp::query()->count());
    }

    public function test_n_refusals_inside_the_window_ban_the_address_once_armed(): void
    {
        $this->arm(after: 10, window: 10);

        $user = $this->userWith(['admin.access']);

        $this->probe($user, 10);

        $ban = BannedIp::query()->where('ip_address', self::PROBER)->sole();

        $this->assertStringContainsString('refused requests', $ban->reason);
        $this->assertStringContainsString('Security Log', $ban->reason);
        $this->assertSame(
            SecuritySettings::banDurationMinutes(),
            (int) $ban->expires_at->diffInMinutes($ban->banned_at, true),
        );

        // The ban is itself an event, at the top severity, so the screen says when
        // the system started blocking.
        $this->assertSame(1, SecurityEvent::query()->where('type', SecurityEvent::TYPE_IP_BANNED)->count());

        // And an activity line under the `security.` prefix, which
        // AdminLogger::ALWAYS_RECORDED keeps regardless of the logging switches.
        $this->assertSame(1, ActivityLog::query()->where('action', 'security.banned')->count());
    }

    public function test_fewer_refusals_than_the_threshold_ban_nobody(): void
    {
        $this->arm(after: 10, window: 10);

        $user = $this->userWith(['admin.access']);

        $this->probe($user, 9);

        $this->assertSame(0, BannedIp::query()->count());
    }

    public function test_refusals_outside_the_window_do_not_add_up(): void
    {
        $this->arm(after: 10, window: 10);

        $user = $this->userWith(['admin.access']);

        $this->probe($user, 6);

        // Age the first burst past the window. The second burst alone is under the
        // threshold, so nothing is barred.
        SecurityEvent::query()->update([
            'last_seen_at' => now()->subMinutes(30),
            'first_seen_at' => now()->subMinutes(30),
        ]);

        $this->probe($user, 6);

        $this->assertSame(0, BannedIp::query()->count());
    }

    public function test_one_ban_is_written_however_long_the_flood_runs(): void
    {
        // Bounding the damage: a prober that keeps going after being banned renews
        // nothing and writes one row, not one per request.
        $this->arm(after: 10, window: 10);

        $user = $this->userWith(['admin.access']);

        $this->probe($user, 40);

        $this->assertSame(1, BannedIp::query()->count());
        $this->assertSame(1, SecurityEvent::query()->where('type', SecurityEvent::TYPE_IP_BANNED)->count());
    }

    public function test_a_super_admin_is_never_banned(): void
    {
        // A wrong list or a bad week must never be able to lock the owner out of the
        // admin during an event.
        $this->arm(after: 10, window: 10);

        $owner = $this->superAdmin();

        // A super admin passes the permission middleware, so the refusals here are
        // produced with something nobody is exempt from: a tampered signed link.
        for ($i = 0; $i < 15; $i++) {
            $this->actingAs($owner)
                ->fromIp(self::PROBER)
                ->get(route('shop.order', ['reference' => 'SO-2026-' . $i]) . '?signature=tampered')
                ->assertForbidden();
        }

        $this->assertGreaterThanOrEqual(10, (int) SecurityEvent::query()->sum('hits'));
        $this->assertSame(0, BannedIp::query()->count());
    }

    public function test_an_allowlisted_address_is_never_banned(): void
    {
        $this->arm(after: 10, window: 10);
        $this->security(['ip_allowlist' => self::PROBER]);

        $user = $this->userWith(['admin.access']);

        $this->probe($user, 20);

        // Recorded — an allowlisted address is trusted, not invisible — but never
        // banned, exactly as the sign-in ban treats it.
        $this->assertGreaterThanOrEqual(20, (int) SecurityEvent::query()->sum('hits'));
        $this->assertSame(0, BannedIp::query()->count());
    }

    public function test_an_observed_pattern_never_bans_anybody_however_often_it_repeats(): void
    {
        // The guarantee that keeps the pattern set from becoming a gate by the back
        // door: an observation is not a refusal, and is never counted here.
        $this->arm(after: 10, window: 10);

        for ($i = 0; $i < 40; $i++) {
            SecurityEvent::create([
                'severity' => SecurityEvent::SEVERITY_INFO,
                'type' => SecurityEvent::TYPE_SUSPICIOUS_INPUT,
                'description' => 'Observed a sql-tautology pattern.',
                'ip_address' => self::PROBER,
                'path' => '/registration/' . $i,
                'hits' => 1,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        }

        // One real refusal on top, which is what triggers the count.
        $this->probe($this->userWith(['admin.access']), 1);

        $this->assertSame(0, BannedIp::query()->count());
    }

    public function test_a_ban_written_by_the_security_log_bars_the_sign_in(): void
    {
        // It has to be the same ban, not a parallel list: the row created here is
        // read by LoginBanService exactly as a sign-in ban is.
        $this->arm(after: 10, window: 10);

        // One account does the probing; the office administrator then tries to sign
        // in from the same network, which is the collateral the owner has to accept
        // when arming this — and the reason it ships off.
        $this->probe($this->userWith(['admin.access']), 12);

        $administrator = $this->administrator();

        $this->assertSame(1, BannedIp::query()->active()->count());

        // The sweep above ran signed in; the sign-in screen is for guests.
        Auth::logout();
        $this->flushSession();

        $this->fromIp(self::PROBER)
            ->signIn($administrator->username)
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    /* ------------------------------------------------------------------
     | The Security tab
     * ------------------------------------------------------------------ */

    public function test_the_tab_draws_the_new_fields_shipped_off(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.settings.general', ['tab' => 'security']));

        $response->assertOk()
            ->assertSee('Security Log Bans')
            ->assertSee('name="security_ban_enabled"', false)
            ->assertSee('id="security_ban_after_events" name="security_ban_after_events"', false)
            ->assertSee('id="security_ban_window_minutes" name="security_ban_window_minutes"', false);

        // Drawn OFF, with the numbers ready for the day somebody arms it.
        $security = $response->viewData('security');

        $this->assertSame('0', $security['security_ban_enabled']);
        $this->assertSame('20', $security['security_ban_after_events']);
        $this->assertSame('10', $security['security_ban_window_minutes']);
    }

    public function test_a_save_persists_the_three_new_values(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.security.update'), [
                'password_min' => 10,
                'password_require_upper' => '0',
                'password_require_number' => '1',
                'password_require_symbol' => '1',
                'password_expiry_days' => 0,
                'session_timeout_minutes' => 120,
                'single_session' => '0',
                'destroy_session_on_logout' => '1',
                'security_ban_enabled' => '1',
                'security_ban_after_events' => 25,
                'security_ban_window_minutes' => 5,
            ])
            ->assertSessionHasNoErrors();

        SecuritySettings::flush();

        $this->assertTrue(SecuritySettings::securityBanEnabled());
        $this->assertSame(25, SecuritySettings::securityBanAfterEvents());
        $this->assertSame(5, SecuritySettings::securityBanWindowMinutes());
    }

    public function test_a_threshold_below_the_floor_is_refused(): void
    {
        // The floor is the lockout guard: one colleague fumbling a permission trips
        // a handful of 403s, and a threshold of five would block them.
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.security.update'), [
                'password_min' => 10,
                'password_require_upper' => '0',
                'password_require_number' => '1',
                'password_require_symbol' => '1',
                'password_expiry_days' => 0,
                'session_timeout_minutes' => 120,
                'single_session' => '0',
                'destroy_session_on_logout' => '1',
                'security_ban_after_events' => 2,
            ])
            ->assertSessionHasErrors('security_ban_after_events');
    }

    public function test_an_older_form_leaving_the_new_fields_out_changes_nothing(): void
    {
        $this->security(['security_ban_enabled' => '1']);

        // Only the Part 1 fields, as an older cached form would post them.
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.security.update'), [
                'password_min' => 12,
                'password_require_upper' => '0',
                'password_require_number' => '1',
                'password_require_symbol' => '1',
                'password_expiry_days' => 0,
                'session_timeout_minutes' => 120,
                'single_session' => '0',
                'destroy_session_on_logout' => '1',
            ])
            ->assertSessionHasNoErrors();

        SecuritySettings::flush();

        $this->assertTrue(SecuritySettings::securityBanEnabled());
    }

    public function test_the_existing_sign_in_ban_still_behaves_exactly_as_before(): void
    {
        // Regression guard. The failed-sign-in ban is untouched by any of this: ten
        // wrong passwords inside the window still ban, and the reason still reads
        // the way it always did.
        $this->security(['login_attempts_per_minute' => '600']);

        $this->fromIp(self::PROBER);

        for ($i = 0; $i < 10; $i++) {
            $this->failedSignIn();
        }

        $ban = BannedIp::query()->where('ip_address', self::PROBER)->sole();

        $this->assertStringContainsString('failed sign-in attempts', $ban->reason);
        $this->assertSame(1, ActivityLog::query()->where('action', 'auth.banned')->count());
    }
}
