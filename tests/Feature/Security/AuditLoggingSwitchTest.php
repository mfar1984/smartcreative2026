<?php

namespace Tests\Feature\Security;

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\AdminLogger;
use App\Support\SecuritySettings;

/**
 * The two logging switches on the Security tab.
 *
 * The point of these tests is not that the switches work — it is that they cannot
 * be used to hide their own use. AdminLogger::ALWAYS_RECORDED lists the prefixes a
 * switch may not silence (auth. and settings.security.), and the tests below cover
 * exactly that list: the Security save itself, sign in, sign out, and ban/unban.
 */
class AuditLoggingSwitchTest extends SecurityTestCase
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
            'activity_log_enabled' => '1',
            'audit_log_enabled' => '1',
            'new_location_warning' => '1',
        ], $overrides);
    }

    /** An ordinary audited change: a maintenance save, which writes to both logs. */
    private function ordinaryChange(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.maintenance.update'), [
                'enabled' => '0',
                'heading' => 'Back shortly',
                'message' => 'The site is being worked on.',
            ])
            ->assertSessionHasNoErrors();
    }

    /* ---------------------------------------------------------------------
     | Defaults
     * ------------------------------------------------------------------ */

    public function test_defaults_on_an_empty_settings_table_leave_behaviour_as_it_is(): void
    {
        $this->assertSame(0, Setting::query()->where('group', 'security')->count());

        $this->assertTrue(SecuritySettings::activityLogEnabled());
        $this->assertTrue(SecuritySettings::auditLogEnabled());

        // Part 3's third switch. ON, so the warning is already running the first
        // time anybody needs it; its first-deploy noise is handled by the migration
        // seeding users.last_login_ip, not by shipping it switched off.
        $this->assertTrue(SecuritySettings::newLocationWarningEnabled());

        $this->ordinaryChange();

        $this->assertDatabaseHas('activity_logs', ['action' => 'settings.maintenance.update']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'settings.updated']);
    }

    public function test_the_tab_shows_the_new_controls(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.settings.general', ['tab' => 'security']))
            ->assertOk()
            ->assertSee('Activity and Audit Logging')
            ->assertSee('New Sign-in Location Warning')
            ->assertSee('id="activity_log_enabled" name="activity_log_enabled"', false)
            ->assertSee('id="audit_log_enabled" name="audit_log_enabled"', false)
            ->assertSee('id="new_location_warning" name="new_location_warning"', false);
    }

    public function test_the_switches_persist_and_read_back(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.security.update'), $this->payload([
                'activity_log_enabled' => '0',
                'audit_log_enabled' => '0',
                'new_location_warning' => '0',
            ]))
            ->assertSessionHasNoErrors();

        SecuritySettings::flush();

        $this->assertFalse(SecuritySettings::activityLogEnabled());
        $this->assertFalse(SecuritySettings::auditLogEnabled());
        $this->assertFalse(SecuritySettings::newLocationWarningEnabled());
    }

    public function test_a_post_without_the_new_switches_keeps_them_on(): void
    {
        // An older form, posting only the Part 1 fields, must not switch logging off.
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.security.update'), [
                'password_min' => 10,
                'password_require_number' => '1',
                'password_require_symbol' => '1',
                'password_expiry_days' => 0,
                'session_timeout_minutes' => 120,
                'destroy_session_on_logout' => '1',
            ])
            ->assertSessionHasNoErrors();

        SecuritySettings::flush();

        $this->assertTrue(SecuritySettings::activityLogEnabled());
        $this->assertTrue(SecuritySettings::auditLogEnabled());
        $this->assertTrue(SecuritySettings::newLocationWarningEnabled());
    }

    /* ---------------------------------------------------------------------
     | One off, the other on
     * ------------------------------------------------------------------ */

    public function test_with_audit_logging_off_an_ordinary_change_writes_no_audit_row(): void
    {
        $this->security(['audit_log_enabled' => '0']);

        $this->ordinaryChange();

        $this->assertDatabaseMissing('audit_logs', ['event' => 'settings.updated']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'settings.maintenance.update']);
    }

    public function test_with_activity_logging_off_an_ordinary_change_writes_no_activity_row(): void
    {
        $this->security(['activity_log_enabled' => '0']);

        $this->ordinaryChange();

        $this->assertDatabaseMissing('activity_logs', ['action' => 'settings.maintenance.update']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'settings.updated']);
    }

    /* ---------------------------------------------------------------------
     | What a switch may not silence
     * ------------------------------------------------------------------ */

    /**
     * The important one. The save that turns both switches off is itself recorded,
     * on both logs, so disabling the trail cannot be done invisibly.
     */
    public function test_the_security_save_is_recorded_even_when_it_switches_logging_off(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.security.update'), $this->payload([
                'activity_log_enabled' => '0',
                'audit_log_enabled' => '0',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('activity_logs', ['action' => 'settings.security.update']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'settings.security.updated']);
    }

    /** And turning it back on is recorded too, with both switches already off. */
    public function test_turning_logging_back_on_is_recorded_as_well(): void
    {
        $this->security(['activity_log_enabled' => '0', 'audit_log_enabled' => '0']);

        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.security.update'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('activity_logs', ['action' => 'settings.security.update']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'settings.security.updated']);

        // The before/after values are there, so the switch flip is readable.
        $audit = AuditLog::where('event', 'settings.security.updated')->firstOrFail();

        $this->assertSame('0', $audit->old_values['audit_log_enabled']);
        $this->assertSame('1', $audit->new_values['audit_log_enabled']);
    }

    public function test_sign_in_and_sign_out_are_recorded_with_logging_off(): void
    {
        $this->security(['activity_log_enabled' => '0', 'audit_log_enabled' => '0']);

        $user = $this->administrator();

        $this->signIn($user->username)->assertRedirect();
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.login', 'user_id' => $user->id]);

        $this->post(route('admin.logout'))->assertRedirect(route('admin.login'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.logout', 'user_id' => $user->id]);
    }

    public function test_a_ban_and_an_unban_are_recorded_with_logging_off(): void
    {
        $this->security([
            'activity_log_enabled' => '0',
            'audit_log_enabled' => '0',
            'ban_after_failures' => '3',
        ]);

        $this->fromIp('203.0.113.77');

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->failedSignIn();
        }

        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.banned']);
        $this->assertDatabaseHas('banned_ips', ['ip_address' => '203.0.113.77']);

        $this->actingAs($this->superAdmin())
            ->delete(route('admin.settings.security.bans.clear'))
            ->assertRedirect();

        $this->assertDatabaseHas('activity_logs', ['action' => 'settings.security.unban_all']);
    }

    public function test_an_account_refused_admin_access_is_recorded_with_logging_off(): void
    {
        $this->security(['activity_log_enabled' => '0']);

        // A role with no admin access at all: authentication succeeds, the door does not.
        $user = $this->userWith([]);
        $user->forceFill(['is_active' => false])->save();

        $this->signIn($user->username)->assertSessionHasErrors('username');

        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.denied']);
    }

    public function test_the_exemption_list_is_the_one_documented(): void
    {
        // `security.` joined the list with the Security Log: the one activity line
        // saying the system started blocking an address has to survive the switches
        // for the same reason the sign-in trail does.
        $this->assertSame(['auth.', 'settings.security.', 'security.'], AdminLogger::ALWAYS_RECORDED);

        $this->assertTrue(AdminLogger::isAlwaysRecorded('auth.login'));
        $this->assertTrue(AdminLogger::isAlwaysRecorded('auth.new_location'));
        $this->assertTrue(AdminLogger::isAlwaysRecorded('settings.security.update'));
        $this->assertTrue(AdminLogger::isAlwaysRecorded('settings.security.updated'));
        $this->assertTrue(AdminLogger::isAlwaysRecorded('security.banned'));

        // Everything else obeys the switches, which is the point of having them.
        $this->assertFalse(AdminLogger::isAlwaysRecorded('settings.general.update'));
        $this->assertFalse(AdminLogger::isAlwaysRecorded('settings.updated'));
        $this->assertFalse(AdminLogger::isAlwaysRecorded('users.update'));
        $this->assertFalse(AdminLogger::isAlwaysRecorded('payments.record'));
    }

    /* ---------------------------------------------------------------------
     | The return value
     * ------------------------------------------------------------------ */

    /**
     * Both methods have always returned a model, and a suppressed write still
     * returns one rather than null, so no caller can fall over on a disabled log.
     *
     * Nothing in the codebase currently captures either return value — every one of
     * the 230-odd call sites is a bare statement — but the contract is kept anyway,
     * because a null here would surface as a fatal error in whatever gets written
     * next, a long way from the switch that caused it.
     */
    public function test_a_suppressed_write_still_returns_a_usable_model(): void
    {
        $this->security(['activity_log_enabled' => '0', 'audit_log_enabled' => '0']);

        $user = $this->administrator();

        $activity = AdminLogger::activity('users.update', 'Changed something.');
        $audit = AdminLogger::audit($user, 'user.updated', ['name' => 'Old'], ['name' => 'New']);

        $this->assertInstanceOf(ActivityLog::class, $activity);
        $this->assertInstanceOf(AuditLog::class, $audit);

        $this->assertFalse($activity->exists);
        $this->assertFalse($audit->exists);
        $this->assertNull($activity->getKey());
        $this->assertNull($audit->getKey());

        // The attributes a caller might read are still there.
        $this->assertSame('users.update', $activity->action);
        $this->assertSame('Changed something.', $activity->description);
        $this->assertSame('user.updated', $audit->event);
        $this->assertSame(['name' => 'New'], $audit->new_values);

        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_a_written_row_is_still_returned_saved(): void
    {
        $this->administrator();

        $activity = AdminLogger::activity('users.update', 'Changed something.');

        $this->assertTrue($activity->exists);
        $this->assertNotNull($activity->getKey());
    }

    /** Secrets are still redacted on the rows that are written. */
    public function test_redaction_is_unaffected_by_the_switches(): void
    {
        $this->security(['audit_log_enabled' => '0']);

        $user = $this->administrator();

        // An exempt event, so it is written despite the switch.
        $audit = AdminLogger::audit($user, 'auth.credentials', null, ['password' => 'secret-value']);

        $this->assertTrue($audit->exists);
        $this->assertSame('[redacted]', $audit->new_values['password']);
    }
}
