<?php

namespace Tests\Feature\Settings;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roles Management warns when a role is saved that cannot sign in.
 *
 * admin.access is one unremarkable checkbox among 124, and a role built without it
 * cannot log in at all — the trap that locked a brand new user out. Saving such a
 * role is allowed, because a parked or template role is a real thing, but it is
 * called out plainly rather than left invisible. A role that holds admin.access
 * saves with no warning, and the super admin — which always holds every permission
 * — is never warned.
 */
class RoleAdminAccessWarningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'Owner',
            'username' => 'owner-' . uniqid(),
            'email' => uniqid() . '@example.test',
            'password' => 'Role-Pass-123!',
            'role_id' => Role::where('slug', Role::SUPER_ADMIN)->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    private function permissionId(string $slug): int
    {
        return (int) Permission::where('slug', $slug)->value('id');
    }

    public function test_saving_a_role_without_admin_access_warns(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.roles.store'), [
                'name' => 'Template Role',
                'is_active' => '1',
                'permissions' => [$this->permissionId('dashboard.view')],
            ])
            ->assertRedirect(route('admin.settings.roles'))
            ->assertSessionHas('warning');

        $this->assertStringContainsString(
            'Access the admin area',
            session('warning'),
        );
    }

    public function test_saving_a_role_with_admin_access_does_not_warn(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.roles.store'), [
                'name' => 'Working Role',
                'is_active' => '1',
                'permissions' => [
                    $this->permissionId('admin.access'),
                    $this->permissionId('dashboard.view'),
                ],
            ])
            ->assertRedirect(route('admin.settings.roles'))
            ->assertSessionMissing('warning');
    }

    public function test_updating_a_role_to_drop_admin_access_warns_about_the_assigned_users(): void
    {
        $role = Role::create([
            'slug' => 'has-users-' . uniqid(),
            'name' => 'Has Users',
            'is_active' => true,
        ]);
        $role->permissions()->sync([$this->permissionId('admin.access')]);

        User::create([
            'name' => 'Stuck',
            'username' => 'stuck-' . uniqid(),
            'email' => uniqid() . '@example.test',
            'password' => 'Role-Pass-123!',
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.roles.update', $role), [
                'name' => $role->name,
                'is_active' => '1',
                'permissions' => [$this->permissionId('dashboard.view')],
            ])
            ->assertRedirect(route('admin.settings.roles'))
            ->assertSessionHas('warning');

        $message = session('warning');
        $this->assertStringContainsString('Access the admin area', $message);
        $this->assertStringContainsString('1 user', $message);
    }

    public function test_the_super_admin_save_is_unaffected_by_the_warning(): void
    {
        $superAdmin = Role::where('slug', Role::SUPER_ADMIN)->firstOrFail();

        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.roles.update', $superAdmin), [
                'name' => 'Super Admin',
                'is_active' => '1',
                'permissions' => [],
            ])
            ->assertRedirect(route('admin.settings.roles'))
            ->assertSessionMissing('warning');

        // Force-synced back to every permission, so it still signs in.
        $this->assertTrue($superAdmin->fresh()->grantsAdminAccess());
    }

    public function test_the_index_flags_a_role_with_users_that_cannot_sign_in(): void
    {
        $role = Role::create([
            'slug' => 'broken-' . uniqid(),
            'name' => 'Broken Role',
            'is_active' => true,
        ]);
        $role->permissions()->sync([$this->permissionId('dashboard.view')]);

        User::create([
            'name' => 'Locked Out',
            'username' => 'locked-' . uniqid(),
            'email' => uniqid() . '@example.test',
            'password' => 'Role-Pass-123!',
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.settings.roles'))
            ->assertOk()
            ->assertSee('No admin access')
            ->assertSee('cannot sign in');
    }
}
