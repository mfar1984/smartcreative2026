<?php

namespace Tests\Feature\Settings;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\SecuritySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The session half of the Security tab.
 *
 * The owner's complaint is the heart of this file: logging out used to leave a
 * stale sessions row behind. These tests drive the real database session driver
 * so they can assert the row count before and after each logout path.
 *
 * Covers: a manual logout deletes the session row (count for that user drops to
 * zero); with one-session ON a second login removes the first session's row; the
 * inactivity middleware logs a user out after the configured gap and deletes the
 * row, and does NOT log out within the window; with one-session OFF and a
 * generous timeout, nothing changes.
 */
class SecuritySessionControlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SecuritySettings::flush();

        // Drive the real database session driver so there are rows to delete.
        config(['session.driver' => 'database']);
    }

    private function role(): Role
    {
        return Role::firstOrCreate(
            ['slug' => Role::SUPER_ADMIN],
            ['name' => 'Super Admin', 'is_active' => true],
        );
    }

    private function user(string $password = 'Abcdefghij1!'): User
    {
        return User::create([
            'name' => 'Admin',
            'username' => 'admin-' . uniqid(),
            'email' => uniqid() . '@example.test',
            'password' => $password,
            'role_id' => $this->role()->id,
            'is_active' => true,
        ]);
    }

    private function sessionRowCount(int $userId): int
    {
        return DB::table('sessions')->where('user_id', $userId)->count();
    }

    public function test_manual_logout_deletes_the_session_row(): void
    {
        $user = $this->user('Login-Pass-123!');

        // Log in through the real flow so a database session row is written.
        $this->post(route('admin.login.attempt'), [
            'username' => $user->username,
            'password' => 'Login-Pass-123!',
        ])->assertRedirect();

        // A row now exists for this user.
        $before = $this->sessionRowCount($user->id);
        $this->assertSame(1, $before, 'Expected exactly one session row after login.');

        $this->post(route('admin.logout'))->assertRedirect(route('admin.login'));

        $after = $this->sessionRowCount($user->id);
        $this->assertSame(0, $after, 'Session row must be deleted on logout.');
    }

    public function test_one_session_on_removes_the_earlier_session_row(): void
    {
        Setting::write('security.single_session', '1', 'security');
        SecuritySettings::flush();

        $user = $this->user('Login-Pass-123!');

        // Stand in for an existing session this user left open in another browser:
        // a row of their own carrying their user_id.
        $staleId = 'stale-session-' . uniqid();
        DB::table('sessions')->insert([
            'id' => $staleId,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'old-browser',
            'payload' => base64_encode('x'),
            'last_activity' => now()->timestamp,
        ]);

        $this->assertSame(1, $this->sessionRowCount($user->id));

        // Logging in now must end that other session.
        $this->post(route('admin.login.attempt'), [
            'username' => $user->username,
            'password' => 'Login-Pass-123!',
        ])->assertRedirect();

        // The stale row is gone; only the row for the login just performed remains.
        $this->assertDatabaseMissing('sessions', ['id' => $staleId]);
        $this->assertSame(1, $this->sessionRowCount($user->id));
    }

    public function test_one_session_off_keeps_other_session_rows(): void
    {
        // Default: single-session OFF.
        SecuritySettings::flush();

        $user = $this->user('Login-Pass-123!');

        $staleId = 'stale-session-' . uniqid();
        DB::table('sessions')->insert([
            'id' => $staleId,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'old-browser',
            'payload' => base64_encode('x'),
            'last_activity' => now()->timestamp,
        ]);

        $this->post(route('admin.login.attempt'), [
            'username' => $user->username,
            'password' => 'Login-Pass-123!',
        ])->assertRedirect();

        // With the toggle off, the earlier session is left alone.
        $this->assertDatabaseHas('sessions', ['id' => $staleId]);
        $this->assertSame(2, $this->sessionRowCount($user->id));
    }

    public function test_inactivity_middleware_logs_out_and_deletes_the_row_after_the_gap(): void
    {
        Setting::write('security.session_timeout_minutes', '10', 'security');
        SecuritySettings::flush();

        $user = $this->user('Login-Pass-123!');

        $this->post(route('admin.login.attempt'), [
            'username' => $user->username,
            'password' => 'Login-Pass-123!',
        ])->assertRedirect();

        $this->assertSame(1, $this->sessionRowCount($user->id));

        // Rewind the stored activity marker well past the 10-minute window.
        $this->withSession(['last_activity_at' => now()->subMinutes(30)->timestamp]);

        $response = $this->get(route('admin.dashboard'));
        $response->assertRedirect(route('admin.login'));

        $this->assertGuest();
        $this->assertSame(0, $this->sessionRowCount($user->id), 'Row must be deleted on inactivity logout.');
    }

    public function test_inactivity_middleware_does_not_log_out_within_the_window(): void
    {
        Setting::write('security.session_timeout_minutes', '30', 'security');
        SecuritySettings::flush();

        $user = $this->user('Login-Pass-123!');

        $this->post(route('admin.login.attempt'), [
            'username' => $user->username,
            'password' => 'Login-Pass-123!',
        ])->assertRedirect();

        // Only five minutes have passed, inside the 30-minute window.
        $this->withSession(['last_activity_at' => now()->subMinutes(5)->timestamp]);

        $this->get(route('admin.dashboard'))->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_defaults_leave_session_behaviour_unchanged(): void
    {
        // Empty settings: single-session OFF, timeout = config lifetime (generous).
        SecuritySettings::flush();

        $this->assertFalse(SecuritySettings::singleSession());
        $this->assertSame((int) config('session.lifetime'), SecuritySettings::sessionTimeoutMinutes());

        $user = $this->user('Login-Pass-123!');

        $this->post(route('admin.login.attempt'), [
            'username' => $user->username,
            'password' => 'Login-Pass-123!',
        ])->assertRedirect();

        // A normal page view stays authenticated and keeps its single row.
        $this->get(route('admin.dashboard'))->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, $this->sessionRowCount($user->id));
    }
}
