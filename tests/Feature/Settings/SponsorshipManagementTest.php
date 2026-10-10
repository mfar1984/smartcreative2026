<?php

namespace Tests\Feature\Settings;

use App\Models\Coupon;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Coupon\CouponIssuer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Sponsorship tab on User Management.
 *
 * One screen, three lists now. The Users tab keeps the administrator accounts it
 * always held, minus handlers and minus sponsors; the Handler tab holds handlers; the
 * Sponsorship tab holds the monitor-only accounts a sponsor, an NGO or a chairman
 * signs in with. Nobody appears on two of them.
 *
 * The permissions are the reason the lists are apart rather than merged: a role can be
 * given sponsorship management without being given administrator management, so the
 * sponsor endpoints must never read a role from the request. If they did, that role
 * could mint a super admin by posting one id, which is the escalation the create test
 * below asserts against — the same shape as the handler test beside it.
 */
class SponsorshipManagementTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Sponsor-Pass-123!';

    private function deploy(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $slug, array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => sprintf('Test %s', $slug),
            'username' => sprintf('%s-%s', $slug, uniqid()),
            'email' => sprintf('%s@example.test', uniqid()),
            'password' => self::PASSWORD,
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'is_active' => true,
        ], $overrides));
    }

    private function superAdmin(): User
    {
        return $this->userWithRole(Role::SUPER_ADMIN);
    }

    private function handler(string $name = 'Desk Referee Zulu'): User
    {
        return $this->userWithRole(Role::HANDLER, ['is_handler' => true, 'name' => $name]);
    }

    private function sponsor(string $name = 'Syarikat Maju Sdn Bhd', array $overrides = []): User
    {
        return $this->userWithRole(Role::SPONSOR, array_merge([
            'is_sponsor' => true,
            'name' => $name,
        ], $overrides));
    }

    /** @param  array<int, string>  $permissions */
    private function userWith(array $permissions): User
    {
        $role = Role::create([
            'slug' => sprintf('custom-%s', uniqid()),
            'name' => 'Custom',
            'is_active' => true,
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', array_merge(['admin.access'], $permissions))->pluck('id')->all()
        );

        return User::create([
            'name' => 'Custom Operator',
            'username' => sprintf('custom-%s', uniqid()),
            'email' => sprintf('%s@example.test', uniqid()),
            'password' => self::PASSWORD,
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    /** A unique-mode batch with one block of codes, funded by the given sponsor. */
    private function fundedBlock(User $sponsor, string $batchName, int $codes = 4): Coupon
    {
        $coupon = Coupon::create([
            'kind' => Coupon::KIND_EVENT,
            'mode' => Coupon::MODE_UNIQUE,
            'name' => $batchName,
            'quantity' => 0,
            'expires_at' => now()->addMonth()->toDateString(),
            'discount_type' => Coupon::DISCOUNT_FIXED,
            'discount_value' => 20,
            'design' => 'classic',
        ]);

        $allocation = app(CouponIssuer::class)->issue($coupon, $codes, ['full_name' => 'Siti Representative']);
        $allocation->forceFill(['sponsor_user_id' => $sponsor->id])->save();

        return $coupon->fresh();
    }

    /* ---------------------------------------------------------------------
     | The three tabs
     * ------------------------------------------------------------------ */

    public function test_the_sponsorship_tab_renders_and_lists_only_sponsor_accounts(): void
    {
        $this->deploy();

        $sponsor = $this->sponsor();
        $handler = $this->handler();
        $admin = $this->userWithRole('administrator', ['name' => 'Office Admin Yankee']);

        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.settings.users', ['tab' => 'sponsorship']));

        $response->assertOk();

        // The tab's own heading and its reason for existing, drawn for real.
        $response->assertSee('Accounts that monitor the coupons they funded');
        $response->assertSee('Funded');
        $response->assertSee('Committed');

        $response->assertSee($sponsor->name);
        $response->assertDontSee($handler->name);
        $response->assertDontSee($admin->name);
    }

    public function test_the_users_tab_lists_neither_sponsors_nor_handlers(): void
    {
        $this->deploy();

        $sponsor = $this->sponsor();
        $handler = $this->handler();
        $admin = $this->userWithRole('administrator', ['name' => 'Office Admin Yankee']);

        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.settings.users', ['tab' => 'users']));

        $response->assertOk();
        $response->assertSee($admin->name);

        // One account, one list, so nobody is editable from two sets of endpoints.
        $response->assertDontSee($sponsor->name);
        $response->assertDontSee($handler->name);
    }

    public function test_the_handler_tab_lists_no_sponsors(): void
    {
        $this->deploy();

        $sponsor = $this->sponsor();
        $handler = $this->handler();

        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.settings.users', ['tab' => 'handler']));

        $response->assertOk();
        $response->assertSee($handler->name);
        $response->assertDontSee($sponsor->name);
    }

    public function test_the_row_says_what_the_sponsorship_is_tied_to_and_how_much_is_used(): void
    {
        $this->deploy();

        $sponsor = $this->sponsor('Syarikat Maju Sdn Bhd', ['sponsor_committed_amount' => 500]);
        $this->fundedBlock($sponsor, 'MAJU20', 4);

        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.settings.users', ['tab' => 'sponsorship']));

        $response->assertOk();

        // Which coupon, how many blocks, how much of it has gone, and the pledge.
        $response->assertSee('MAJU20');
        $response->assertSee('1 block');
        $response->assertSee('0 of 4 codes used');
        $response->assertSee('RM 500.00');
    }

    public function test_an_untagged_sponsorship_shows_the_empty_state(): void
    {
        $this->deploy();

        $this->sponsor();

        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.settings.users', ['tab' => 'sponsorship']));

        $response->assertOk();
        $response->assertSee('Not tagged to any coupon yet');
    }

    /* ---------------------------------------------------------------------
     | Create, edit, delete
     * ------------------------------------------------------------------ */

    public function test_creating_from_the_sponsorship_tab_sets_the_flag_and_the_role(): void
    {
        $this->deploy();

        $response = $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.users.sponsors.store'), [
                'name' => 'Syarikat Maju Sdn Bhd',
                'username' => 'maju-sponsor',
                'email' => 'maju@example.test',
                'sponsor_committed_amount' => '2000',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
                'is_active' => '1',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('admin.settings.users', ['tab' => 'sponsorship']));

        $created = User::where('username', 'maju-sponsor')->firstOrFail();

        $this->assertTrue($created->is_sponsor);
        $this->assertTrue($created->isSponsor());
        $this->assertFalse($created->is_handler);
        $this->assertSame(Role::SPONSOR, $created->role->slug);
        $this->assertSame('2000.00', (string) $created->sponsor_committed_amount);
    }

    public function test_a_role_id_posted_to_the_sponsor_create_endpoint_is_never_granted(): void
    {
        $this->deploy();

        $superAdminRoleId = Role::where('slug', Role::SUPER_ADMIN)->value('id');

        $response = $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.users.sponsors.store'), [
                'name' => 'Sneaky Sponsor',
                'username' => 'sneaky-sponsor',
                'email' => 'sneaky-sponsor@example.test',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
                'is_active' => '1',

                // The escalation this endpoint must not allow.
                'role_id' => $superAdminRoleId,
            ]);

        $response->assertSessionHasNoErrors();

        $created = User::where('username', 'sneaky-sponsor')->firstOrFail();

        $this->assertNotSame((int) $superAdminRoleId, (int) $created->role_id, 'A posted role_id must be ignored.');
        $this->assertSame(Role::SPONSOR, $created->role->slug);
        $this->assertFalse($created->role->isSuperAdmin());
        $this->assertTrue($created->is_sponsor);
    }

    public function test_editing_a_sponsorship_keeps_it_a_sponsorship(): void
    {
        $this->deploy();

        $sponsor = $this->sponsor();
        $superAdminRoleId = Role::where('slug', Role::SUPER_ADMIN)->value('id');

        $response = $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.users.sponsors.update', $sponsor), [
                'name' => 'Syarikat Maju Renamed',
                'username' => $sponsor->username,
                'email' => $sponsor->email,
                'sponsor_committed_amount' => '750.50',
                'is_active' => '1',
                'role_id' => $superAdminRoleId,
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('admin.settings.users', ['tab' => 'sponsorship']));

        $sponsor->refresh();

        $this->assertSame('Syarikat Maju Renamed', $sponsor->name);
        $this->assertSame('750.50', (string) $sponsor->sponsor_committed_amount);
        $this->assertTrue($sponsor->is_sponsor);
        $this->assertSame(Role::SPONSOR, $sponsor->role->slug);
    }

    public function test_deleting_a_sponsorship_releases_its_blocks_and_keeps_every_code(): void
    {
        $this->deploy();

        $sponsor = $this->sponsor();
        $coupon = $this->fundedBlock($sponsor, 'MAJU20', 4);
        $allocation = $coupon->allocations()->firstOrFail();

        $response = $this->actingAs($this->superAdmin())
            ->delete(route('admin.settings.users.sponsors.destroy', $sponsor));

        $response->assertRedirect(route('admin.settings.users', ['tab' => 'sponsorship']));

        $this->assertDatabaseMissing('users', ['id' => $sponsor->id]);

        // The block survives with no sponsor, and so does every code in it: codes
        // that have been printed must not vanish because an account was closed.
        $this->assertDatabaseHas('coupon_allocations', [
            'id' => $allocation->id,
            'sponsor_user_id' => null,
        ]);
        $this->assertSame(4, $coupon->issuedCodes()->count());
    }

    /* ---------------------------------------------------------------------
     | Permissions
     * ------------------------------------------------------------------ */

    public function test_the_four_sponsor_permissions_are_seeded_under_user_management(): void
    {
        $this->deploy();
        $this->deploy();

        foreach (['sponsors.view', 'sponsors.create', 'sponsors.update', 'sponsors.delete'] as $slug) {
            $rows = Permission::where('slug', $slug)->get();

            $this->assertCount(1, $rows, sprintf('Re-seeding must not duplicate %s.', $slug));
            $this->assertSame('User Management', $rows->first()->group);
            $this->assertSame('Sponsorship', $rows->first()->module);
        }
    }

    public function test_the_sponsor_role_holds_none_of_the_sponsorship_management_permissions(): void
    {
        $this->deploy();

        $sponsor = $this->sponsor();

        foreach (['sponsors.view', 'sponsors.create', 'sponsors.update', 'sponsors.delete'] as $slug) {
            $this->assertFalse($sponsor->hasPermission($slug), sprintf('A sponsor must not manage sponsors: %s.', $slug));
        }
    }

    public function test_the_sponsor_role_cannot_reach_any_of_the_endpoints(): void
    {
        $this->deploy();

        $sponsor = $this->sponsor();
        $other = $this->sponsor('Other NGO Berhad');

        $this->actingAs($sponsor)->get(route('admin.settings.users'))->assertForbidden();

        $this->actingAs($sponsor)->post(route('admin.settings.users.sponsors.store'), [
            'name' => 'Nope',
            'username' => 'nope-sponsor',
            'email' => 'nope@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertForbidden();

        $this->actingAs($sponsor)->put(route('admin.settings.users.sponsors.update', $other), [
            'name' => 'Nope',
            'username' => $other->username,
            'email' => $other->email,
        ])->assertForbidden();

        $this->actingAs($sponsor)
            ->delete(route('admin.settings.users.sponsors.destroy', $other))
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $other->id]);
    }

    public function test_each_sponsor_endpoint_refuses_a_role_without_its_permission(): void
    {
        $this->deploy();

        $sponsor = $this->sponsor();

        // Full administrator user management, and none of the sponsor slugs.
        $operator = $this->userWith(['users.view', 'users.create', 'users.update', 'users.delete']);

        $this->actingAs($operator)->post(route('admin.settings.users.sponsors.store'), [
            'name' => 'Maju',
            'username' => 'maju-sponsor',
            'email' => 'maju@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertForbidden();

        $this->actingAs($operator)->put(route('admin.settings.users.sponsors.update', $sponsor), [
            'name' => 'Renamed',
            'username' => $sponsor->username,
            'email' => $sponsor->email,
        ])->assertForbidden();

        $this->actingAs($operator)
            ->delete(route('admin.settings.users.sponsors.destroy', $sponsor))
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['username' => 'maju-sponsor']);
        $this->assertDatabaseHas('users', ['id' => $sponsor->id, 'name' => $sponsor->name]);
    }

    public function test_sponsor_view_is_required_for_the_tab_to_be_drawn(): void
    {
        $this->deploy();

        $sponsor = $this->sponsor();
        $operator = $this->userWith(['users.view']);

        // Asking for the sponsorship tab by hand falls back to the one tab this
        // role may see, rather than drawing a list it is not allowed to read.
        $response = $this->actingAs($operator)
            ->get(route('admin.settings.users', ['tab' => 'sponsorship']));

        $response->assertOk();
        $response->assertDontSee($sponsor->name);
        $response->assertDontSee('Add Sponsorship');
        $response->assertDontSee(route('admin.settings.users', ['tab' => 'sponsorship']), false);
    }

    public function test_sponsorship_management_alone_reaches_only_its_own_tab(): void
    {
        $this->deploy();

        $sponsor = $this->sponsor();
        $admin = $this->userWithRole('administrator', ['name' => 'Office Admin Yankee']);
        $handler = $this->handler();

        $operator = $this->userWith(['sponsors.view', 'sponsors.create', 'sponsors.update', 'sponsors.delete']);

        $response = $this->actingAs($operator)->get(route('admin.settings.users'));

        $response->assertOk();

        // Lands on the Sponsorship tab even though it is not the first declared.
        $response->assertSee('Accounts that monitor the coupons they funded');
        $response->assertSee($sponsor->name);

        // And the other two tabs are neither drawn nor linked.
        $response->assertDontSee($admin->name);
        $response->assertDontSee($handler->name);
        $response->assertDontSee('Add User');
        $response->assertDontSee(route('admin.settings.users', ['tab' => 'users']), false);
        $response->assertDontSee(route('admin.settings.users', ['tab' => 'handler']), false);

        // Typing another tab into the query string changes nothing.
        $forced = $this->actingAs($operator)
            ->get(route('admin.settings.users', ['tab' => 'users']));

        $forced->assertOk();
        $forced->assertSee($sponsor->name);
        $forced->assertDontSee($admin->name);
    }

    /* ---------------------------------------------------------------------
     | The three sets of endpoints cannot reach each other's rows
     * ------------------------------------------------------------------ */

    public function test_a_sponsor_id_posted_to_the_user_update_route_is_refused(): void
    {
        $this->deploy();

        $sponsor = $this->sponsor();

        $response = $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.users.update', $sponsor), [
                'name' => 'Promoted Sponsor',
                'username' => $sponsor->username,
                'email' => $sponsor->email,
                'role_id' => Role::where('slug', Role::SUPER_ADMIN)->value('id'),
                'is_active' => '1',
            ]);

        $response->assertForbidden();

        $sponsor->refresh();
        $this->assertSame('Syarikat Maju Sdn Bhd', $sponsor->name);
        $this->assertSame(Role::SPONSOR, $sponsor->role->slug);
    }

    public function test_a_sponsor_id_posted_to_the_user_delete_route_is_refused(): void
    {
        $this->deploy();

        $sponsor = $this->sponsor();

        $this->actingAs($this->superAdmin())
            ->delete(route('admin.settings.users.destroy', $sponsor))
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $sponsor->id]);
    }

    public function test_a_sponsor_id_posted_to_the_handler_update_route_is_refused(): void
    {
        $this->deploy();

        $sponsor = $this->sponsor();

        $response = $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.users.handlers.update', $sponsor), [
                'name' => 'Converted Sponsor',
                'username' => $sponsor->username,
                'email' => $sponsor->email,
                'is_active' => '1',
            ]);

        $response->assertForbidden();

        $sponsor->refresh();
        $this->assertSame('Syarikat Maju Sdn Bhd', $sponsor->name);
        $this->assertFalse($sponsor->is_handler);
    }

    public function test_a_handler_id_posted_to_the_sponsor_update_route_is_refused(): void
    {
        $this->deploy();

        $handler = $this->handler();

        $response = $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.users.sponsors.update', $handler), [
                'name' => 'Converted Zulu',
                'username' => $handler->username,
                'email' => $handler->email,
                'is_active' => '1',
            ]);

        $response->assertForbidden();

        $handler->refresh();
        $this->assertSame('Desk Referee Zulu', $handler->name);
        $this->assertFalse($handler->is_sponsor);
    }

    public function test_an_admin_id_posted_to_the_sponsor_routes_is_refused(): void
    {
        $this->deploy();

        $admin = $this->userWithRole('administrator', ['name' => 'Office Admin Yankee']);

        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.users.sponsors.update', $admin), [
                'name' => 'Demoted Yankee',
                'username' => $admin->username,
                'email' => $admin->email,
                'is_active' => '1',
            ])
            ->assertForbidden();

        $this->actingAs($this->superAdmin())
            ->delete(route('admin.settings.users.sponsors.destroy', $admin))
            ->assertForbidden();

        $admin->refresh();
        $this->assertSame('Office Admin Yankee', $admin->name);
        $this->assertFalse($admin->is_sponsor);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }
}
