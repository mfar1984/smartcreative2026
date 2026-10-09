<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\EnforceIpAllowlist;
use App\Models\Setting;
use App\Services\AdminLogger;
use Illuminate\Support\Facades\DB;

/**
 * The IP allowlist.
 *
 * Covers: with an empty list nobody is restricted; a non-empty list refuses a
 * non-super-admin outside it at sign in, after the password is checked, with a clear
 * message; it signs such a user out mid-session and deletes the sessions row; it lets
 * a user inside it through; a super admin is exempt both at sign in and mid-session
 * (safety net C); and a bad line is refused on save, naming the line.
 */
class IpAllowlistTest extends SecurityTestCase
{
    private const OFFICE = '198.51.100.0/24';

    private const INSIDE = '198.51.100.25';

    private const OUTSIDE = '203.0.113.99';

    public function test_an_empty_list_restricts_nobody(): void
    {
        $admin = $this->administrator();

        $this->fromIp(self::OUTSIDE)
            ->signIn($admin->username)
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_a_non_super_admin_outside_the_list_is_refused_at_sign_in(): void
    {
        $this->security(['ip_allowlist' => self::OFFICE]);
        $admin = $this->administrator();

        $response = $this->fromIp(self::OUTSIDE)->signIn($admin->username);

        $response->assertSessionHasErrors('username');
        $this->assertStringContainsString('is not allowed for this account', session('errors')->first('username'));
        $this->assertStringContainsString(self::OUTSIDE, session('errors')->first('username'));
        $this->assertGuest();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'auth.denied',
            'user_id' => $admin->id,
            'level' => AdminLogger::LEVEL_WARN,
        ]);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'auth.login']);

        // A refusal on a right password is not a failed guess: nothing is counted.
        $this->assertSame(0, $this->bans()->failures(self::OUTSIDE));
    }

    public function test_a_wrong_password_from_outside_the_list_gets_the_ordinary_message(): void
    {
        $this->security(['ip_allowlist' => self::OFFICE]);
        $admin = $this->administrator();

        $this->fromIp(self::OUTSIDE)
            ->signIn($admin->username, 'not-the-password')
            ->assertSessionHasErrors(['username' => __('auth.failed')]);

        $this->assertGuest();
    }

    public function test_a_user_inside_the_list_signs_in(): void
    {
        $this->security(['ip_allowlist' => "203.0.113.5\n" . self::OFFICE]);
        $admin = $this->administrator();

        $this->fromIp(self::INSIDE)
            ->signIn($admin->username)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_ipv6_entries_and_ranges_match(): void
    {
        $this->security(['ip_allowlist' => "2001:db8::/32\n" . self::OFFICE]);
        $admin = $this->administrator();

        $this->fromIp('2001:db8:85a3::8a2e:370:7334')
            ->signIn($admin->username)
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_non_super_admin_who_leaves_the_list_is_signed_out_and_the_session_row_is_deleted(): void
    {
        config(['session.driver' => 'database']);

        $admin = $this->administrator();

        $this->fromIp(self::INSIDE)->signIn($admin->username)->assertRedirect(route('admin.dashboard'));
        $this->assertSame(1, DB::table('sessions')->where('user_id', $admin->id)->count());

        // The list is saved, and the same session carries on from another network.
        $this->security(['ip_allowlist' => self::OFFICE]);

        $this->fromIp(self::OUTSIDE)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.login', ['notice' => EnforceIpAllowlist::NOTICE]));

        $this->assertGuest();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $admin->id)->count());

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'auth.revoked',
            'user_id' => $admin->id,
            'level' => AdminLogger::LEVEL_WARN,
        ]);

        // The sign-in screen says why, without relying on the deleted session.
        $this->get(route('admin.login', ['notice' => EnforceIpAllowlist::NOTICE]))
            ->assertOk()
            ->assertSee('not on the admin IP allowlist')
            ->assertSee(self::OUTSIDE);
    }

    public function test_a_session_inside_the_list_carries_on(): void
    {
        $this->security(['ip_allowlist' => self::OFFICE]);
        $admin = $this->administrator();

        $this->fromIp(self::INSIDE)
            ->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk();

        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_super_admin_is_exempt_at_sign_in_and_mid_session(): void
    {
        $this->security(['ip_allowlist' => self::OFFICE]);
        $owner = $this->superAdmin();

        $this->fromIp(self::OUTSIDE)
            ->signIn($owner->username)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($owner);

        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('admin.settings.general', ['tab' => 'security']))->assertOk();
        $this->assertAuthenticatedAs($owner);
    }

    public function test_the_security_tab_shows_the_current_ip_beside_the_list(): void
    {
        $this->fromIp(self::INSIDE)
            ->actingAs($this->superAdmin())
            ->get(route('admin.settings.general', ['tab' => 'security']))
            ->assertOk()
            ->assertSee('name="ip_allowlist"', false)
            ->assertSee('Your current IP:')
            ->assertSee(self::INSIDE);
    }

    public function test_an_invalid_line_is_refused_on_save_naming_the_line(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.security.update'), $this->payload([
                'ip_allowlist' => "203.0.113.5\n\nnot-an-ip\n198.51.100.0/24",
            ]));

        $response->assertSessionHasErrors('ip_allowlist');

        $message = session('errors')->first('ip_allowlist');
        $this->assertStringContainsString('Line 3', $message);
        $this->assertStringContainsString('not-an-ip', $message);

        // Nothing at all was saved.
        $this->assertNull(Setting::read('security.ip_allowlist'));
        $this->assertNull(Setting::read('security.password_min'));
    }

    public function test_out_of_range_masks_are_refused(): void
    {
        foreach (['203.0.113.0/33', '2001:db8::/129', '203.0.113.0/', '203.0.113.0/abc', '999.1.1.1'] as $bad) {
            $this->actingAs($this->superAdmin())
                ->put(route('admin.settings.security.update'), $this->payload(['ip_allowlist' => $bad]))
                ->assertSessionHasErrors('ip_allowlist');
        }

        $this->assertNull(Setting::read('security.ip_allowlist'));
    }

    public function test_a_valid_list_is_saved_trimmed_one_entry_per_line(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.security.update'), $this->payload([
                'ip_allowlist' => "  203.0.113.5  \r\n\r\n198.51.100.0/24\r\n2001:db8::/32\r\n",
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.settings.general', ['tab' => 'security']));

        $this->assertSame("203.0.113.5\n198.51.100.0/24\n2001:db8::/32", Setting::read('security.ip_allowlist'));
    }

    public function test_an_empty_list_is_saved_as_no_restriction(): void
    {
        $this->security(['ip_allowlist' => self::OFFICE]);

        $this->fromIp(self::INSIDE)
            ->actingAs($this->superAdmin())
            ->put(route('admin.settings.security.update'), $this->payload(['ip_allowlist' => '']))
            ->assertSessionHasNoErrors();

        $this->assertSame('', (string) Setting::read('security.ip_allowlist'));
    }

    /**
     * The whole Security form as the tab posts it.
     *
     * @param  array<string, string|int>  $overrides
     * @return array<string, string|int>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'password_min' => 10,
            'password_require_upper' => '0',
            'password_require_number' => '1',
            'password_require_symbol' => '1',
            'password_expiry_days' => 0,
            'session_timeout_minutes' => 120,
            'single_session' => '0',
            'destroy_session_on_logout' => '1',
            'ban_enabled' => '1',
            'ban_after_failures' => 10,
            'ban_window_minutes' => 15,
            'ban_duration_minutes' => 30,
            'login_attempts_per_minute' => 10,
            'admin_requests_per_minute' => 0,
            'ip_allowlist' => '',
        ], $overrides);
    }
}
