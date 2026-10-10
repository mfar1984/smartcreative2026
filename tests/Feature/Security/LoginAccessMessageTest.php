<?php

namespace Tests\Feature\Security;

use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\User;

/**
 * The sign-in message tells apart a wrong password from a right password whose
 * account cannot reach the admin.
 *
 * The trap this closes: an operator built a role without "Access the admin area",
 * assigned a user, and the login screen answered the correct password with the
 * generic "these credentials do not match our records". Half an hour was spent
 * hunting a password that was never wrong. A right password that cannot enter now
 * says so plainly; a genuinely wrong username or password keeps the generic
 * message, because that message is the only thing stopping the form being used to
 * discover which accounts exist.
 */
class LoginAccessMessageTest extends SecurityTestCase
{
    private const GENERIC = 'These credentials do not match our records.';

    /** A user on a role that is active but holds no permissions at all. */
    private function userWithoutAdminAccess(): User
    {
        $role = Role::create([
            'slug' => 'no-access-' . uniqid(),
            'name' => 'No Access ' . uniqid(),
            'is_active' => true,
        ]);

        return User::create([
            'name' => 'Blocked User',
            'username' => 'blocked-' . uniqid(),
            'email' => uniqid() . '@example.test',
            'password' => self::PASSWORD,
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    public function test_a_right_password_on_a_role_without_admin_access_is_told_so_not_wrong_credentials(): void
    {
        $user = $this->userWithoutAdminAccess();

        $response = $this->signIn($user->username);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();

        $message = session('errors')->first('username');
        $this->assertStringContainsString('does not have access to the admin area', $message);
        $this->assertNotSame(self::GENERIC, $message);
        $this->assertSame(self::GENERIC, __('auth.failed'));
    }

    public function test_the_role_without_admin_access_refusal_is_in_the_activity_log_not_the_security_log(): void
    {
        $user = $this->userWithoutAdminAccess();

        $this->signIn($user->username)->assertSessionHasErrors('username');

        // Recorded for an admin to find — but as a misconfiguration, not an attack.
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'auth.denied',
            'user_id' => $user->id,
        ]);

        // The Security Log is for refusals about where a request came from, not a
        // correctly authenticated person hitting a permissions wall.
        $this->assertSame(0, SecurityEvent::query()->count());
    }

    public function test_a_wrong_password_still_sees_the_generic_message(): void
    {
        $user = $this->administrator();

        $this->signIn($user->username, 'not-the-password')
            ->assertSessionHasErrors(['username' => self::GENERIC]);

        $this->assertGuest();
        $this->assertSame(0, SecurityEvent::query()->count());
    }

    public function test_a_wrong_username_still_sees_the_generic_message(): void
    {
        $this->signIn('nobody-' . uniqid(), 'whatever')
            ->assertSessionHasErrors(['username' => self::GENERIC]);

        $this->assertGuest();
    }

    public function test_an_inactive_account_with_a_right_password_gets_a_fitting_refusal(): void
    {
        $user = $this->administrator();
        $user->forceFill(['is_active' => false])->save();

        $this->signIn($user->username)->assertSessionHasErrors('username');

        $message = session('errors')->first('username');
        $this->assertStringContainsString('deactivated', $message);
        $this->assertNotSame(self::GENERIC, $message);
        $this->assertGuest();
        $this->assertSame(0, SecurityEvent::query()->count());
    }

    public function test_an_active_account_on_an_inactive_role_gets_a_fitting_refusal(): void
    {
        $user = $this->administrator();
        $user->role->forceFill(['is_active' => false])->save();

        $this->signIn($user->username)->assertSessionHasErrors('username');

        $message = session('errors')->first('username');
        $this->assertStringContainsString('role is inactive', $message);
        $this->assertNotSame(self::GENERIC, $message);
        $this->assertGuest();
        $this->assertSame(0, SecurityEvent::query()->count());
    }

    public function test_a_super_admin_and_an_ordinary_admin_still_sign_in(): void
    {
        $owner = $this->superAdmin();

        $this->signIn($owner->username)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($owner);

        $this->post(route('admin.logout'));

        $admin = $this->administrator();

        $this->signIn($admin->username)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);
    }
}
