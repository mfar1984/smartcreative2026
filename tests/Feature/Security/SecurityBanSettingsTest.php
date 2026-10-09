<?php

namespace Tests\Feature\Security;

use App\Models\Setting;
use App\Support\SecuritySettings;

/**
 * Saving the Part 2 settings on the Security tab.
 *
 * Covers: the tab draws the new fields with today's defaults; a save persists and
 * reads back; every number is bounded so no saved value can lock anybody out
 * instantly; a stray stored value is clamped by the readers; and a post that leaves
 * the new fields out keeps what is stored rather than switching bans off.
 */
class SecurityBanSettingsTest extends SecurityTestCase
{
    /**
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

    private function save(array $overrides = [])
    {
        return $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.security.update'), $this->payload($overrides));
    }

    public function test_the_tab_shows_the_new_fields_with_todays_defaults(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.settings.general', ['tab' => 'security']))
            ->assertOk()
            ->assertSee('Failed Sign-in Bans')
            ->assertSee('Rate Limits')
            ->assertSee('IP Allowlist')
            ->assertSee('name="ban_enabled"', false)
            ->assertSee('id="ban_after_failures" name="ban_after_failures"', false)
            ->assertSee('value="10"', false)
            ->assertSee('value="15"', false)
            ->assertSee('value="30"', false)
            ->assertSee('name="admin_requests_per_minute"', false);
    }

    public function test_a_save_persists_and_reads_back(): void
    {
        $this->save([
            'ban_enabled' => '0',
            'ban_after_failures' => 5,
            'ban_window_minutes' => 20,
            'ban_duration_minutes' => 60,
            'login_attempts_per_minute' => 20,
            'admin_requests_per_minute' => 120,
            'ip_allowlist' => "203.0.113.5\n198.51.100.0/24",
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.settings.general', ['tab' => 'security']));

        SecuritySettings::flush();

        $this->assertFalse(SecuritySettings::banEnabled());
        $this->assertSame(5, SecuritySettings::banAfterFailures());
        $this->assertSame(20, SecuritySettings::banWindowMinutes());
        $this->assertSame(60, SecuritySettings::banDurationMinutes());
        $this->assertSame(20, SecuritySettings::loginAttemptsPerMinute());
        $this->assertSame(120, SecuritySettings::adminRequestsPerMinute());
        $this->assertSame(['203.0.113.5', '198.51.100.0/24'], SecuritySettings::ipAllowlist());

        $this->assertDatabaseHas('activity_logs', ['action' => 'settings.security.update']);
    }

    public function test_numbers_that_could_lock_somebody_out_are_refused(): void
    {
        $this->save(['ban_after_failures' => 2])->assertSessionHasErrors('ban_after_failures');
        $this->save(['ban_window_minutes' => 0])->assertSessionHasErrors('ban_window_minutes');
        $this->save(['ban_duration_minutes' => 0])->assertSessionHasErrors('ban_duration_minutes');
        $this->save(['login_attempts_per_minute' => 2])->assertSessionHasErrors('login_attempts_per_minute');
        $this->save(['admin_requests_per_minute' => 59])->assertSessionHasErrors('admin_requests_per_minute');
        $this->save(['admin_requests_per_minute' => -1])->assertSessionHasErrors('admin_requests_per_minute');
        $this->save(['ban_duration_minutes' => SecuritySettings::MAX_BAN_DURATION_MINUTES + 1])->assertSessionHasErrors('ban_duration_minutes');
        $this->save(['ban_after_failures' => 'ten'])->assertSessionHasErrors('ban_after_failures');

        // None of those saves wrote anything.
        $this->assertNull(Setting::read('security.ban_after_failures'));
        $this->assertNull(Setting::read('security.admin_requests_per_minute'));

        // The edges themselves are accepted.
        $this->save([
            'ban_after_failures' => SecuritySettings::MIN_BAN_AFTER_FAILURES,
            'ban_window_minutes' => 1,
            'ban_duration_minutes' => 1,
            'login_attempts_per_minute' => SecuritySettings::MIN_LOGIN_ATTEMPTS_PER_MINUTE,
            'admin_requests_per_minute' => SecuritySettings::MIN_ADMIN_REQUESTS_PER_MINUTE,
        ])->assertSessionHasNoErrors();
    }

    public function test_a_stray_stored_value_is_clamped_by_the_readers(): void
    {
        $this->security([
            'ban_after_failures' => '1',
            'ban_window_minutes' => '0',
            'ban_duration_minutes' => '999999',
            'login_attempts_per_minute' => 'abc',
            'admin_requests_per_minute' => '7',
        ]);

        $this->assertSame(SecuritySettings::MIN_BAN_AFTER_FAILURES, SecuritySettings::banAfterFailures());
        $this->assertSame(SecuritySettings::MIN_BAN_WINDOW_MINUTES, SecuritySettings::banWindowMinutes());
        $this->assertSame(SecuritySettings::MAX_BAN_DURATION_MINUTES, SecuritySettings::banDurationMinutes());
        $this->assertSame(SecuritySettings::MIN_LOGIN_ATTEMPTS_PER_MINUTE, SecuritySettings::loginAttemptsPerMinute());
        $this->assertSame(SecuritySettings::MIN_ADMIN_REQUESTS_PER_MINUTE, SecuritySettings::adminRequestsPerMinute());
    }

    public function test_a_post_without_the_new_fields_keeps_what_is_stored(): void
    {
        $this->security(['ban_after_failures' => '7', 'ip_allowlist' => '203.0.113.5']);

        // Only the Part 1 fields, as an older form would post them.
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.security.update'), [
                'password_min' => 12,
                'password_require_number' => '1',
                'password_require_symbol' => '1',
                'password_expiry_days' => 0,
                'session_timeout_minutes' => 120,
                'destroy_session_on_logout' => '1',
            ])
            ->assertSessionHasNoErrors();

        SecuritySettings::flush();

        $this->assertSame(12, SecuritySettings::passwordMin());
        $this->assertTrue(SecuritySettings::banEnabled());
        $this->assertSame(7, SecuritySettings::banAfterFailures());
        $this->assertSame(['203.0.113.5'], SecuritySettings::ipAllowlist());
    }
}
