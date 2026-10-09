<?php

namespace Tests\Feature\Security;

use App\Models\BannedIp;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Security\LoginBanService;
use App\Support\SecuritySettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Fixtures shared by the Part 2 security tests: bans, rate limits, the allowlist.
 *
 * Abstract, so PHPUnit does not run it. Roles come from the real seeder, the way
 * the other admin tests build them, so super-admin, administrator and viewer here
 * are the roles production has. Rows are explicit Model::create() calls.
 *
 * SecuritySettings memoises the whole group in a static, which survives from one
 * test to the next in the same process. It is flushed on the way in AND on the way
 * out, so an allowlist or a limit set here can never leak into an unrelated admin
 * test that runs afterwards.
 */
abstract class SecurityTestCase extends TestCase
{
    use RefreshDatabase;

    protected const PASSWORD = 'Login-Pass-123!';

    protected function setUp(): void
    {
        parent::setUp();

        SecuritySettings::flush();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        SecuritySettings::flush();

        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | People
     * ------------------------------------------------------------------ */

    protected function userWithRole(string $slug): User
    {
        return User::create([
            'name' => 'Test ' . $slug,
            'username' => $slug . '-' . uniqid(),
            'email' => uniqid() . '@example.test',
            'password' => self::PASSWORD,
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    protected function superAdmin(): User
    {
        return $this->userWithRole(Role::SUPER_ADMIN);
    }

    /** Holds settings.security.view and .update, but is not a super admin. */
    protected function administrator(): User
    {
        return $this->userWithRole('administrator');
    }

    /**
     * A user holding exactly these permission slugs, on a role of its own.
     *
     * @param  array<int, string>  $permissions
     */
    protected function userWith(array $permissions): User
    {
        $role = Role::create([
            'slug' => 'custom-' . uniqid(),
            'name' => 'Custom',
            'is_active' => true,
        ]);

        $role->permissions()->sync(Permission::whereIn('slug', $permissions)->pluck('id')->all());

        return User::create([
            'name' => 'Custom',
            'username' => 'custom-' . uniqid(),
            'email' => uniqid() . '@example.test',
            'password' => self::PASSWORD,
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    /* ---------------------------------------------------------------------
     | Settings and bans
     * ------------------------------------------------------------------ */

    /** @param  array<string, string>  $values  key => value, without the group prefix */
    protected function security(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::write('security.' . $key, $value, 'security');
        }

        SecuritySettings::flush();
    }

    protected function bans(): LoginBanService
    {
        return app(LoginBanService::class);
    }

    /** A ban in force, written directly, as recordFailure() would leave it. */
    protected function activeBan(string $ip, int $minutesLeft = 30): BannedIp
    {
        return BannedIp::create([
            'ip_address' => $ip,
            'failed_attempts' => 10,
            'reason' => '10 failed sign-in attempts within 15 minutes',
            'banned_at' => now(),
            'expires_at' => now()->addMinutes($minutesLeft),
        ]);
    }

    /* ---------------------------------------------------------------------
     | Requests
     * ------------------------------------------------------------------ */

    /** Make every following request come from this client IP. */
    protected function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    protected function signIn(string $username, string $password = self::PASSWORD): TestResponse
    {
        return $this->post(route('admin.login.attempt'), [
            'username' => $username,
            'password' => $password,
        ]);
    }

    /** A failed sign in under a username of its own, so the per-account limiter never trips. */
    protected function failedSignIn(): TestResponse
    {
        return $this->signIn('nobody-' . uniqid(), 'wrong-password');
    }
}
