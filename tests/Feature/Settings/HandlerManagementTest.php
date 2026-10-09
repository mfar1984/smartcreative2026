<?php

namespace Tests\Feature\Settings;

use App\Models\Event;
use App\Models\Permission;
use App\Models\PointRule;
use App\Models\Role;
use App\Models\Tournament;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Part 3 of the Tournament Handler feature: the Handler tab on User Management.
 *
 * One screen, two lists. The Users tab keeps the administrator accounts it always
 * held, minus the handlers, and the Handler tab holds the handlers and the
 * tournaments each of them runs. Nobody appears on both.
 *
 * The permissions are the reason the lists are apart rather than merged: a role can
 * be given handler management without being given administrator management, so the
 * handler endpoints must never read a role from the request. If they did, that role
 * could mint a super admin by posting one id, which is the escalation the create
 * test below asserts against.
 */
class HandlerManagementTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Handler-Pass-123!';

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

    private function tournament(string $name): Tournament
    {
        $event = Event::create([
            'slug' => sprintf('event-%s', uniqid()),
            'title' => 'Handler Event',
            'category' => 'E-Sport',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => 'published',
            'registration_mode' => 'manager',
            'seats_total' => 100,
        ]);

        $rule = PointRule::create([
            'name' => sprintf('Bracket %s', uniqid()),
            'kind' => PointRule::KIND_BRACKET,
            'squad_size' => 5,
            'components' => [
                ['key' => 'series_won', 'label' => 'Games Won', 'type' => 'per_unit', 'source' => 'series_won', 'value' => 1],
            ],
            'inputs' => [
                ['key' => 'series_won', 'label' => 'Games Won', 'type' => 'integer', 'min' => 0, 'max' => 3, 'required' => true],
            ],
            'tiebreak' => ['series_won'],
            'is_active' => true,
        ]);

        return Tournament::create([
            'event_id' => $event->id,
            'name' => $name,
            'format' => Tournament::FORMAT_SINGLE_ELIM,
            'point_rule_id' => $rule->id,
            'status' => Tournament::STATUS_SETUP,
            'seeding_method' => Tournament::SEEDING_MANUAL,
            'settings' => ['buffer_minutes' => 15, 'map_rotation' => ['Erangel']],
        ]);
    }

    /* ---------------------------------------------------------------------
     | The two tabs
     * ------------------------------------------------------------------ */

    public function test_the_handler_tab_renders_and_lists_only_handler_accounts(): void
    {
        $this->deploy();

        $handler = $this->handler();
        $admin = $this->userWithRole('administrator', ['name' => 'Office Admin Yankee']);

        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.settings.users', ['tab' => 'handler']));

        $response->assertOk();

        // The tab's own heading and column, drawn for real.
        $response->assertSee('Accounts that run a tournament on the day');
        $response->assertSee('Tournaments');

        $response->assertSee($handler->name);
        $response->assertDontSee($admin->name);
    }

    public function test_the_users_tab_lists_only_non_handler_accounts(): void
    {
        $this->deploy();

        $handler = $this->handler();
        $admin = $this->userWithRole('administrator', ['name' => 'Office Admin Yankee']);

        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.settings.users', ['tab' => 'users']));

        $response->assertOk();
        $response->assertSee($admin->name);

        // The same account is on one list and one only, so nobody is editable
        // from both sets of endpoints.
        $response->assertDontSee($handler->name);
    }

    public function test_the_users_tab_is_the_default(): void
    {
        $this->deploy();

        $handler = $this->handler();
        $admin = $this->userWithRole('administrator', ['name' => 'Office Admin Yankee']);

        $response = $this->actingAs($this->superAdmin())->get(route('admin.settings.users'));

        $response->assertOk();
        $response->assertSee($admin->name);
        $response->assertDontSee($handler->name);
    }

    public function test_a_handler_row_names_the_tournaments_it_runs(): void
    {
        $this->deploy();

        $handler = $this->handler();
        $tournament = $this->tournament('Alpha Cup');
        $tournament->handlers()->attach($handler);

        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.settings.users', ['tab' => 'handler']));

        $response->assertOk();
        $response->assertSee('Alpha Cup');
        $response->assertDontSee('Not assigned yet');
    }

    public function test_an_unassigned_handler_shows_the_empty_state(): void
    {
        $this->deploy();

        $this->handler();
        $this->tournament('Alpha Cup');

        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.settings.users', ['tab' => 'handler']));

        $response->assertOk();

        // The column that explains why a handler can see nothing.
        $response->assertSee('Not assigned yet');
        $response->assertDontSee('Alpha Cup');
    }

    /* ---------------------------------------------------------------------
     | Create, edit, delete
     * ------------------------------------------------------------------ */

    public function test_creating_from_the_handler_tab_sets_the_flag_and_the_role(): void
    {
        $this->deploy();

        $response = $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.users.handlers.store'), [
                'name' => 'Desk Referee Zulu',
                'username' => 'desk-zulu',
                'email' => 'desk-zulu@example.test',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
                'is_active' => '1',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('admin.settings.users', ['tab' => 'handler']));

        $created = User::where('username', 'desk-zulu')->firstOrFail();

        $this->assertTrue($created->is_handler);
        $this->assertTrue($created->isHandler());
        $this->assertSame(Role::HANDLER, $created->role->slug);
    }

    public function test_a_role_id_posted_to_the_handler_create_endpoint_is_never_granted(): void
    {
        $this->deploy();

        $superAdminRoleId = Role::where('slug', Role::SUPER_ADMIN)->value('id');

        $response = $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.users.handlers.store'), [
                'name' => 'Sneaky Zulu',
                'username' => 'sneaky-zulu',
                'email' => 'sneaky-zulu@example.test',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
                'is_active' => '1',

                // The escalation this endpoint must not allow.
                'role_id' => $superAdminRoleId,
            ]);

        $response->assertSessionHasNoErrors();

        $created = User::where('username', 'sneaky-zulu')->firstOrFail();

        $this->assertNotSame((int) $superAdminRoleId, (int) $created->role_id, 'A posted role_id must be ignored.');
        $this->assertSame(Role::HANDLER, $created->role->slug);
        $this->assertFalse($created->role->isSuperAdmin());
        $this->assertTrue($created->is_handler);
    }

    public function test_editing_a_handler_keeps_it_a_handler(): void
    {
        $this->deploy();

        $handler = $this->handler();
        $superAdminRoleId = Role::where('slug', Role::SUPER_ADMIN)->value('id');

        $response = $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.users.handlers.update', $handler), [
                'name' => 'Desk Referee Renamed',
                'username' => $handler->username,
                'email' => $handler->email,
                'is_active' => '1',
                'role_id' => $superAdminRoleId,
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('admin.settings.users', ['tab' => 'handler']));

        $handler->refresh();

        $this->assertSame('Desk Referee Renamed', $handler->name);
        $this->assertTrue($handler->is_handler);
        $this->assertSame(Role::HANDLER, $handler->role->slug);
    }

    public function test_deleting_an_assigned_handler_leaves_the_tournament_intact(): void
    {
        $this->deploy();

        $handler = $this->handler();
        $tournament = $this->tournament('Alpha Cup');
        $tournament->handlers()->attach($handler);

        $response = $this->actingAs($this->superAdmin())
            ->delete(route('admin.settings.users.handlers.destroy', $handler));

        $response->assertRedirect(route('admin.settings.users', ['tab' => 'handler']));

        $this->assertDatabaseMissing('users', ['id' => $handler->id]);
        $this->assertDatabaseMissing('tournament_handler', ['user_id' => $handler->id]);
        $this->assertDatabaseHas('tournaments', ['id' => $tournament->id, 'name' => 'Alpha Cup']);
    }

    /* ---------------------------------------------------------------------
     | Permissions
     * ------------------------------------------------------------------ */

    public function test_the_four_handler_permissions_are_seeded_under_user_management(): void
    {
        $this->deploy();
        $this->deploy();

        foreach (['handlers.view', 'handlers.create', 'handlers.update', 'handlers.delete'] as $slug) {
            $rows = Permission::where('slug', $slug)->get();

            $this->assertCount(1, $rows, sprintf('Re-seeding must not duplicate %s.', $slug));
            $this->assertSame('User Management', $rows->first()->group);
            $this->assertSame('Handler', $rows->first()->module);
        }
    }

    public function test_the_handler_role_holds_none_of_the_handler_management_permissions(): void
    {
        $this->deploy();

        $handler = $this->handler();

        foreach (['handlers.view', 'handlers.create', 'handlers.update', 'handlers.delete'] as $slug) {
            $this->assertFalse($handler->hasPermission($slug), sprintf('A handler must not manage handlers: %s.', $slug));
        }
    }

    public function test_the_handler_role_cannot_reach_any_of_the_endpoints(): void
    {
        $this->deploy();

        $handler = $this->handler();
        $other = $this->handler('Other Zulu');

        $this->actingAs($handler)->get(route('admin.settings.users'))->assertForbidden();

        $this->actingAs($handler)->post(route('admin.settings.users.handlers.store'), [
            'name' => 'Nope',
            'username' => 'nope-zulu',
            'email' => 'nope@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertForbidden();

        $this->actingAs($handler)->put(route('admin.settings.users.handlers.update', $other), [
            'name' => 'Nope',
            'username' => $other->username,
            'email' => $other->email,
        ])->assertForbidden();

        $this->actingAs($handler)
            ->delete(route('admin.settings.users.handlers.destroy', $other))
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $other->id]);
    }

    public function test_each_handler_endpoint_refuses_a_role_without_its_permission(): void
    {
        $this->deploy();

        $handler = $this->handler();

        // Full administrator user management, and none of the handler slugs.
        $operator = $this->userWith(['users.view', 'users.create', 'users.update', 'users.delete']);

        $this->actingAs($operator)->post(route('admin.settings.users.handlers.store'), [
            'name' => 'Desk Zulu',
            'username' => 'desk-zulu',
            'email' => 'desk-zulu@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertForbidden();

        $this->actingAs($operator)->put(route('admin.settings.users.handlers.update', $handler), [
            'name' => 'Renamed',
            'username' => $handler->username,
            'email' => $handler->email,
        ])->assertForbidden();

        $this->actingAs($operator)
            ->delete(route('admin.settings.users.handlers.destroy', $handler))
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['username' => 'desk-zulu']);
        $this->assertDatabaseHas('users', ['id' => $handler->id, 'name' => $handler->name]);
    }

    public function test_handler_view_is_required_for_the_tab_to_be_drawn(): void
    {
        $this->deploy();

        $handler = $this->handler();
        $operator = $this->userWith(['users.view']);

        // Asking for the handler tab by hand falls back to the one tab this role
        // may see, rather than drawing a list it is not allowed to read.
        $response = $this->actingAs($operator)
            ->get(route('admin.settings.users', ['tab' => 'handler']));

        $response->assertOk();
        $response->assertDontSee($handler->name);
        $response->assertDontSee('Add Handler');
        $response->assertDontSee(route('admin.settings.users', ['tab' => 'handler']), false);
    }

    public function test_handler_management_alone_reaches_the_handler_tab_and_not_the_users_tab(): void
    {
        $this->deploy();

        $handler = $this->handler();
        $admin = $this->userWithRole('administrator', ['name' => 'Office Admin Yankee']);

        $operator = $this->userWith(['handlers.view', 'handlers.create', 'handlers.update', 'handlers.delete']);

        $response = $this->actingAs($operator)->get(route('admin.settings.users'));

        $response->assertOk();

        // Lands on the Handler tab even though it is not the first one declared.
        $response->assertSee('Accounts that run a tournament on the day');
        $response->assertSee($handler->name);

        // And the Users tab is neither drawn nor linked.
        $response->assertDontSee($admin->name);
        $response->assertDontSee('Add User');
        $response->assertDontSee(route('admin.settings.users', ['tab' => 'users']), false);

        // Typing the other tab into the query string changes nothing.
        $forced = $this->actingAs($operator)
            ->get(route('admin.settings.users', ['tab' => 'users']));

        $forced->assertOk();
        $forced->assertSee($handler->name);
        $forced->assertDontSee($admin->name);
    }

    public function test_a_role_with_neither_view_permission_is_refused_the_screen(): void
    {
        $this->deploy();

        $this->actingAs($this->userWith(['dashboard.view']))
            ->get(route('admin.settings.users'))
            ->assertForbidden();
    }

    /* ---------------------------------------------------------------------
     | The two sets of endpoints cannot reach each other's rows
     * ------------------------------------------------------------------ */

    public function test_a_handler_id_posted_to_the_user_update_route_is_refused(): void
    {
        $this->deploy();

        $handler = $this->handler();

        $response = $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.users.update', $handler), [
                'name' => 'Promoted Zulu',
                'username' => $handler->username,
                'email' => $handler->email,
                'role_id' => Role::where('slug', Role::SUPER_ADMIN)->value('id'),
                'is_active' => '1',
            ]);

        $response->assertForbidden();

        $handler->refresh();
        $this->assertSame('Desk Referee Zulu', $handler->name);
        $this->assertSame(Role::HANDLER, $handler->role->slug);
    }

    public function test_a_handler_id_posted_to_the_user_delete_route_is_refused(): void
    {
        $this->deploy();

        $handler = $this->handler();

        $this->actingAs($this->superAdmin())
            ->delete(route('admin.settings.users.destroy', $handler))
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $handler->id]);
    }

    public function test_an_admin_id_posted_to_the_handler_update_route_is_refused(): void
    {
        $this->deploy();

        $admin = $this->userWithRole('administrator', ['name' => 'Office Admin Yankee']);

        $response = $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.users.handlers.update', $admin), [
                'name' => 'Demoted Yankee',
                'username' => $admin->username,
                'email' => $admin->email,
                'is_active' => '1',
            ]);

        $response->assertForbidden();

        $admin->refresh();
        $this->assertSame('Office Admin Yankee', $admin->name);
        $this->assertFalse($admin->is_handler);
    }

    public function test_an_admin_id_posted_to_the_handler_delete_route_is_refused(): void
    {
        $this->deploy();

        $admin = $this->userWithRole('administrator', ['name' => 'Office Admin Yankee']);

        $this->actingAs($this->superAdmin())
            ->delete(route('admin.settings.users.handlers.destroy', $admin))
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    /* ---------------------------------------------------------------------
     | Regression: the Users tab behaves exactly as it did
     * ------------------------------------------------------------------ */

    public function test_user_management_is_unchanged_for_a_role_holding_the_users_permissions(): void
    {
        $this->deploy();

        $operator = $this->userWith(['users.view', 'users.create', 'users.update', 'users.delete']);
        $viewerRoleId = Role::where('slug', 'viewer')->value('id');

        $create = $this->actingAs($operator)->post(route('admin.settings.users.store'), [
            'name' => 'Office Admin Yankee',
            'username' => 'office-yankee',
            'email' => 'office-yankee@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'role_id' => $viewerRoleId,
            'is_active' => '1',
        ]);

        $create->assertSessionHasNoErrors();
        $create->assertRedirect(route('admin.settings.users'));

        $user = User::where('username', 'office-yankee')->firstOrFail();

        $this->assertSame((int) $viewerRoleId, (int) $user->role_id);
        $this->assertFalse($user->is_handler, 'The users endpoints must not flip an account into handler status.');

        $list = $this->actingAs($operator)->get(route('admin.settings.users'));
        $list->assertOk();
        $list->assertSee('Office Admin Yankee');
        $list->assertSee('Add User');

        $update = $this->actingAs($operator)->put(route('admin.settings.users.update', $user), [
            'name' => 'Office Admin Renamed',
            'username' => $user->username,
            'email' => $user->email,
            'role_id' => $viewerRoleId,
            'is_active' => '1',
        ]);

        $update->assertSessionHasNoErrors();
        $this->assertSame('Office Admin Renamed', $user->fresh()->name);
        $this->assertFalse($user->fresh()->is_handler);

        $this->actingAs($operator)
            ->delete(route('admin.settings.users.destroy', $user))
            ->assertRedirect(route('admin.settings.users'));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }
}
