<?php

namespace Tests\Feature\Tournament;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Part 1 of the Tournament Handler feature: the user type, the login path, the
 * role and the trimmed sidebar.
 *
 * A handler is a users row carrying the handler role with is_handler true. It logs
 * in at the same /admin/login as every other admin, is not rejected on the way in,
 * and once in the navigation leads with the Tournament group and shows nothing
 * else. There is no per-tournament filtering yet; that is a later part. What is
 * proven here is that the role, the flag, the login and the landing all line up,
 * and that none of it changes anything for an existing administrator.
 */
class TournamentHandlerAccessTest extends TestCase
{
    use RefreshDatabase;

    private function deploy(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $slug, array $overrides = []): User
    {
        $role = Role::where('slug', $slug)->firstOrFail();

        return User::create(array_merge([
            'name' => 'Test ' . $slug,
            'username' => $slug . '-' . uniqid(),
            'email' => uniqid() . '@example.test',
            'password' => 'secret-password-1!',
            'role_id' => $role->id,
            'is_active' => true,
        ], $overrides));
    }

    private function handler(): User
    {
        return $this->userWithRole('handler', ['is_handler' => true]);
    }

    public function test_a_handler_can_log_in_and_is_not_rejected_by_can_access_admin(): void
    {
        $this->deploy();

        $handler = $this->handler();

        $this->assertTrue($handler->canAccessAdmin(), 'The handler role must hold admin.access and be active.');

        $response = $this->post(route('admin.login.attempt'), [
            'username' => $handler->username,
            'password' => 'secret-password-1!',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($handler);
    }

    public function test_a_handler_lands_on_the_tournaments_list_after_login(): void
    {
        $this->deploy();

        $handler = $this->handler();

        $response = $this->post(route('admin.login.attempt'), [
            'username' => $handler->username,
            'password' => 'secret-password-1!',
        ]);

        $response->assertRedirect(route('admin.tournaments.index'));
    }

    public function test_a_normal_admin_still_lands_on_the_dashboard(): void
    {
        $this->deploy();

        $admin = $this->userWithRole('administrator');

        $response = $this->post(route('admin.login.attempt'), [
            'username' => $admin->username,
            'password' => 'secret-password-1!',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
    }

    public function test_the_handler_sidebar_shows_only_the_tournament_group_and_dashboard(): void
    {
        $this->deploy();

        $handler = $this->handler();

        $nav = AdminNavigation::for($handler);

        // The dashboard item and the Modules section, nothing more. No System
        // section (Settings) at all.
        $dashboard = collect($nav)->firstWhere('route', 'admin.dashboard');
        $this->assertNotNull($dashboard, 'The dashboard item must be present.');

        $groupKeys = collect($nav)
            ->where('kind', 'section')
            ->flatMap(fn (array $section) => collect($section['items'])->pluck('key'))
            ->filter()
            ->values()
            ->all();

        $this->assertSame(['tournament'], $groupKeys, 'Only the Tournament group may appear in the sidebar.');

        // Event, Shop, Payments, Campaign, Portfolio and Settings are all absent.
        foreach (['event', 'shop', 'payments', 'campaign', 'portfolio', 'settings'] as $absent) {
            $this->assertNotContains($absent, $groupKeys, $absent . ' must not appear for a handler.');
        }
    }

    public function test_the_handler_tournament_group_has_no_settings_child(): void
    {
        $this->deploy();

        $handler = $this->handler();

        $nav = AdminNavigation::for($handler);

        $tournament = collect($nav)
            ->where('kind', 'section')
            ->flatMap(fn (array $section) => $section['items'])
            ->firstWhere('key', 'tournament');

        $this->assertNotNull($tournament);

        $labels = collect($tournament['children'])->pluck('label')->all();

        // The owner's five: Settings is gated on tournaments.settings.view, which a
        // handler does not hold, so it drops out of the permission filter.
        $this->assertContains('Tournaments', $labels);
        $this->assertContains('Matches', $labels);
        $this->assertContains('Standings', $labels);
        $this->assertContains('Point Rules', $labels);
        $this->assertContains('Hall of Fame', $labels);
        $this->assertNotContains('Settings', $labels);
    }

    public function test_a_handler_does_not_hold_the_create_edit_delete_or_rule_write_permissions(): void
    {
        $this->deploy();

        $handler = $this->handler();

        foreach ([
            'tournaments.create',
            'tournaments.update',
            'tournaments.delete',
            'tournaments.rules.create',
            'tournaments.rules.update',
            'tournaments.rules.delete',
            'tournaments.settings.view',
        ] as $slug) {
            $this->assertFalse($handler->hasPermission($slug), $slug . ' must not be held by a handler.');
        }

        // And it does hold the ones the owner asked for.
        foreach ([
            'admin.access',
            'dashboard.view',
            'tournaments.view',
            'tournaments.matches.view',
            'tournaments.matches.score',
            'tournaments.matches.generate',
            'tournaments.standings.view',
            'tournaments.standings.export',
            'tournaments.rules.view',
            'tournaments.halloffame.view',
            'tournaments.halloffame.publish',
        ] as $slug) {
            $this->assertTrue($handler->hasPermission($slug), $slug . ' must be held by a handler.');
        }
    }

    public function test_the_handler_role_is_created_idempotently(): void
    {
        $this->deploy();
        $this->deploy();

        $roles = Role::where('slug', 'handler')->get();

        $this->assertCount(1, $roles, 'Re-seeding must not duplicate the handler role.');

        $role = $roles->first();
        $this->assertTrue($role->is_protected, 'The handler role is a system role.');
        $this->assertSame('Tournament Handler', $role->name);

        $expected = [
            'admin.access',
            'dashboard.view',
            'tournaments.halloffame.publish',
            'tournaments.halloffame.view',
            'tournaments.matches.generate',
            'tournaments.matches.score',
            'tournaments.matches.view',
            'tournaments.rules.view',
            'tournaments.standings.export',
            'tournaments.standings.view',
            'tournaments.view',
        ];

        $actual = $role->permissions()->pluck('slug')->sort()->values()->all();

        $this->assertSame($expected, $actual, 'The handler role must hold exactly the granted slugs.');
    }

    public function test_an_existing_admin_account_is_unchanged_by_the_handler_additions(): void
    {
        $this->deploy();

        $admin = $this->userWithRole('administrator');

        // The additive flag defaults false in the database, so an administrator
        // read back is not a handler.
        $admin = $admin->fresh();
        $this->assertFalse($admin->isHandler());
        $this->assertFalse($admin->is_handler);
        $this->assertTrue($admin->canAccessAdmin());

        // Still holds the slugs it always did.
        $this->assertTrue($admin->hasPermission('tournaments.create'));
        $this->assertTrue($admin->hasPermission('users.view'));
    }
}
