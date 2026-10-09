<?php

namespace Tests\Feature\Settings;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\PasswordPolicy;
use App\Support\SecuritySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The password-policy half of the Security tab.
 *
 * Covers: the settings persist and read back; the user create/update rule is
 * built from the configured minimum and required classes; with defaults a
 * password that passed before still passes; and password_changed_at is stamped
 * whenever a password is created or changed.
 */
class SecurityPasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SecuritySettings::flush();
    }

    private ?User $admin = null;

    private function administrator(): User
    {
        if ($this->admin !== null) {
            return $this->admin;
        }

        $role = Role::create([
            'slug' => Role::SUPER_ADMIN,
            'name' => 'Super Admin',
            'is_active' => true,
        ]);

        return $this->admin = User::create([
            'name' => 'Admin',
            'username' => 'admin-' . uniqid(),
            'email' => uniqid() . '@example.test',
            'password' => 'Secret-Password-1!',
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

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
        ], $overrides);
    }

    public function test_saving_the_security_settings_persists_and_reads_back(): void
    {
        $response = $this->actingAs($this->administrator())
            ->put(route('admin.settings.security.update'), $this->payload([
                'password_min' => 14,
                'password_require_upper' => '1',
                'password_expiry_days' => 90,
                'session_timeout_minutes' => 45,
                'single_session' => '1',
            ]));

        $response->assertRedirect(route('admin.settings.general', ['tab' => 'security']));
        $response->assertSessionHasNoErrors();

        $this->assertSame('14', Setting::read('security.password_min'));
        $this->assertSame('1', Setting::read('security.password_require_upper'));
        $this->assertSame('90', Setting::read('security.password_expiry_days'));
        $this->assertSame('45', Setting::read('security.session_timeout_minutes'));
        $this->assertSame('1', Setting::read('security.single_session'));

        SecuritySettings::flush();
        $this->assertSame(14, SecuritySettings::passwordMin());
        $this->assertTrue(SecuritySettings::passwordRequireUpper());
        $this->assertSame(90, SecuritySettings::passwordExpiryDays());
        $this->assertSame(45, SecuritySettings::sessionTimeoutMinutes());
        $this->assertTrue(SecuritySettings::singleSession());
    }

    public function test_an_absurd_session_timeout_is_rejected(): void
    {
        $response = $this->actingAs($this->administrator())
            ->put(route('admin.settings.security.update'), $this->payload([
                'session_timeout_minutes' => 1,
            ]));

        $response->assertSessionHasErrors('session_timeout_minutes');
        $this->assertNull(Setting::read('security.session_timeout_minutes'));
    }

    public function test_defaults_reproduce_todays_password_rule(): void
    {
        // Empty settings table: the rule must equal min(10)->letters()->numbers()->symbols().
        SecuritySettings::flush();

        // A password that satisfied the old literal rule still passes.
        $this->assertTrue($this->passwordPasses('Secret-Password-1!'));

        // Each class is still required at the default.
        $this->assertFalse($this->passwordPasses('short1!'));          // under 10
        $this->assertFalse($this->passwordPasses('abcdefghij'));       // no number, no symbol
        $this->assertFalse($this->passwordPasses('abcdefghij1'));      // no symbol
        $this->assertFalse($this->passwordPasses('1234567890!'));      // no letter

        // Mixed case is NOT required by default: an all-lowercase one passes.
        $this->assertTrue($this->passwordPasses('abcdefghij1!'));
    }

    public function test_configured_minimum_length_is_enforced_through_the_user_request(): void
    {
        Setting::write('security.password_min', '12', 'security');
        SecuritySettings::flush();

        // 11 characters is now rejected...
        $this->assertFalse($this->passwordPasses('Abcdefghj1!'));
        // ...and 12 characters passes.
        $this->assertTrue($this->passwordPasses('Abcdefghj12!'));
    }

    public function test_require_upper_toggle_adds_mixed_case(): void
    {
        Setting::write('security.password_require_upper', '1', 'security');
        SecuritySettings::flush();

        // All lowercase now fails; a mix passes.
        $this->assertFalse($this->passwordPasses('abcdefghij1!'));
        $this->assertTrue($this->passwordPasses('Abcdefghij1!'));
    }

    public function test_password_changed_at_is_set_when_a_user_is_created(): void
    {
        $role = Role::create(['slug' => 'viewer', 'name' => 'Viewer', 'is_active' => true]);

        $user = User::create([
            'name' => 'Fresh',
            'username' => 'fresh-' . uniqid(),
            'email' => uniqid() . '@example.test',
            'password' => 'Abcdefghij1!',
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $this->assertNotNull($user->fresh()->password_changed_at);
    }

    public function test_password_changed_at_is_updated_when_the_password_changes(): void
    {
        $user = $this->administrator();
        $user->forceFill(['password_changed_at' => now()->subDays(100)])->save();
        $old = $user->fresh()->password_changed_at;

        $user->update(['password' => 'Brand-New-Pass-9!']);

        $this->assertTrue($user->fresh()->password_changed_at->gt($old));
    }

    public function test_password_changed_at_is_untouched_when_other_fields_change(): void
    {
        $user = $this->administrator();
        $user->forceFill(['password_changed_at' => now()->subDays(30)])->save();
        $stamp = $user->fresh()->password_changed_at;

        $user->update(['name' => 'Renamed Only']);

        $this->assertEquals(
            $stamp->timestamp,
            $user->fresh()->password_changed_at->timestamp,
        );
    }

    /**
     * Runs the exact Password rule the user Form Requests use against a candidate.
     */
    private function passwordPasses(string $password): bool
    {
        return Validator::make(
            ['password' => $password],
            ['password' => [PasswordPolicy::rule()]],
        )->passes();
    }
}
