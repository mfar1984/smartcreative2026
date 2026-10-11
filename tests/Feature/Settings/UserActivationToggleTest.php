<?php

namespace Tests\Feature\Settings;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminLogger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The lock / unlock action on every row of User Management, on all four tabs.
 *
 * What the owner asked for: switch an account off from its row, without opening
 * the edit form, and see at a glance that it is off. What makes it dangerous: the
 * same field decides who may sign in, so the two lockout guards below — you
 * cannot switch yourself off, and you cannot switch off the last account that can
 * still administer the system — are the point of this file as much as the toggle
 * is. Both are asserted against a DIRECT PUT, with the control hidden, because
 * hiding a button is not a guard.
 *
 * Handlers, sponsors and monitors are rows in the same users table, so a single
 * endpoint per tab exists only to keep the PERMISSIONS apart: a role granted
 * handler management must not be able to lock an administrator by changing the
 * number in the URL. Every cross-tab case below is that.
 */
class UserActivationToggleTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Toggle-Pass-123!';

    /** The grey the locked row is drawn in. Asserted raw, as it appears in class="". */
    private const GREYED = 'bg-gray-50 opacity-60';

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

    private function superAdmin(string $name = 'Owner Alpha'): User
    {
        return $this->userWithRole(Role::SUPER_ADMIN, ['name' => $name]);
    }

    private function administrator(string $name = 'Office Admin Bravo'): User
    {
        return $this->userWithRole('administrator', ['name' => $name]);
    }

    private function handler(string $name = 'Desk Referee Charlie'): User
    {
        return $this->userWithRole(Role::HANDLER, ['is_handler' => true, 'name' => $name]);
    }

    private function sponsor(string $name = 'Maju Sponsor Delta'): User
    {
        return $this->userWithRole(Role::SPONSOR, ['is_sponsor' => true, 'name' => $name]);
    }

    private function monitor(string $name = 'Outside Watcher Echo'): User
    {
        return $this->userWithRole(Role::MONITOR, ['is_monitor' => true, 'name' => $name]);
    }

    /**
     * An account on a role of its own holding exactly these permissions.
     *
     * $flags carries the tab flag, so an account that LIVES on the handler,
     * sponsorship or monitoring tab can also be given that tab's update
     * permission. That is the only configuration in which somebody can post their
     * own id at those three endpoints — an ordinary handler holds no permissions
     * at all — and it is how the self-lock guard is reached and proved there.
     *
     * @param  array<int, string>  $permissions
     * @param  array<string, mixed>  $flags
     */
    private function userWith(array $permissions, array $flags = []): User
    {
        $role = Role::create([
            'slug' => sprintf('custom-%s', uniqid()),
            'name' => 'Custom',
            'is_active' => true,
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', array_merge(['admin.access'], $permissions))->pluck('id')->all()
        );

        return User::create(array_merge([
            'name' => 'Custom Operator',
            'username' => sprintf('custom-%s', uniqid()),
            'email' => sprintf('%s@example.test', uniqid()),
            'password' => self::PASSWORD,
            'role_id' => $role->id,
            'is_active' => true,
        ], $flags));
    }

    /**
     * The four tabs, as route name / tab query / fixture maker, so each case below
     * says its rule once instead of four times.
     *
     * @return array<string, array{route: string, tab: array<string, string>, make: callable}>
     */
    private function tabs(): array
    {
        return [
            'users' => [
                'route' => 'admin.settings.users.activation',
                'tab' => [],
                'make' => fn () => $this->administrator(),
            ],
            'handler' => [
                'route' => 'admin.settings.users.handlers.activation',
                'tab' => ['tab' => 'handler'],
                'make' => fn () => $this->handler(),
            ],
            'sponsorship' => [
                'route' => 'admin.settings.users.sponsors.activation',
                'tab' => ['tab' => 'sponsorship'],
                'make' => fn () => $this->sponsor(),
            ],
            'monitoring' => [
                'route' => 'admin.settings.users.monitors.activation',
                'tab' => ['tab' => 'monitoring'],
                'make' => fn () => $this->monitor(),
            ],
        ];
    }

    /* ---------------------------------------------------------------------
     | The control, as it is drawn
     * ------------------------------------------------------------------ */

    public function test_every_tab_draws_a_deactivate_control_on_an_active_row(): void
    {
        $this->deploy();

        foreach ($this->tabs() as $slug => $tab) {
            $row = ($tab['make'])();

            $response = $this->actingAs($this->superAdmin())
                ->get(route('admin.settings.users', $tab['tab']));

            $response->assertOk();
            $response->assertSee(sprintf('aria-label="Deactivate %s"', $row->name), false);
            $response->assertSee(route($tab['route'], $row), false);

            // An active row is not greyed, so the grey means something.
            $response->assertDontSee(self::GREYED, false);
            $response->assertSee('Active', false);

            $this->assertTrue($row->fresh()->is_active, $slug);
        }
    }

    public function test_every_tab_greys_a_locked_row_and_offers_activate(): void
    {
        $this->deploy();

        foreach ($this->tabs() as $slug => $tab) {
            $row = ($tab['make'])();
            $row->forceFill(['is_active' => false])->save();

            $response = $this->actingAs($this->superAdmin())
                ->get(route('admin.settings.users', $tab['tab']));

            $response->assertOk();
            $response->assertSee(self::GREYED, false);
            $response->assertSee(sprintf('aria-label="Activate %s"', $row->name), false);
            $response->assertSee('Activate', false);

            // The Status badge still does its own job; the grey is on top of it,
            // not instead of it.
            $response->assertSee('Inactive', false);

            // Nothing offers to deactivate a row that is already off.
            $response->assertDontSee(sprintf('aria-label="Deactivate %s"', $row->name), false);

            $row->forceFill(['is_active' => true])->save();
            $this->assertTrue($row->fresh()->is_active, $slug);
        }
    }

    public function test_your_own_row_offers_no_deactivate_control(): void
    {
        $this->deploy();

        $owner = $this->superAdmin();

        $response = $this->actingAs($owner)->get(route('admin.settings.users'));

        $response->assertOk();
        $response->assertSee($owner->name);
        $response->assertDontSee(sprintf('aria-label="Deactivate %s"', $owner->name), false);
    }

    public function test_a_view_only_role_is_offered_no_activation_control(): void
    {
        $this->deploy();

        $admin = $this->administrator();
        $reader = $this->userWith(['users.view']);

        $response = $this->actingAs($reader)->get(route('admin.settings.users'));

        $response->assertOk();
        $response->assertSee('View only');
        $response->assertDontSee(route('admin.settings.users.activation', $admin), false);
    }

    /* ---------------------------------------------------------------------
     | Locking and unlocking, per tab
     * ------------------------------------------------------------------ */

    public function test_a_super_admin_can_lock_and_unlock_an_account_on_every_tab(): void
    {
        $this->deploy();

        $owner = $this->superAdmin();

        foreach ($this->tabs() as $slug => $tab) {
            $row = ($tab['make'])();

            $this->actingAs($owner)
                ->put(route($tab['route'], $row), ['is_active' => '0'])
                ->assertSessionHasNoErrors()
                ->assertSessionHas('status')
                ->assertRedirect(route('admin.settings.users', $tab['tab']));

            $this->assertFalse($row->fresh()->is_active, $slug);

            // The lock is filed under the family's ordinary edit action, so one
            // account's history reads in one place.
            $this->assertDatabaseHas('activity_logs', [
                'action' => sprintf('%s.update', $this->prefixFor($slug)),
                'user_id' => $owner->id,
            ]);

            $this->actingAs($owner)
                ->put(route($tab['route'], $row), ['is_active' => '1'])
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('admin.settings.users', $tab['tab']));

            $this->assertTrue($row->fresh()->is_active, $slug);
        }
    }

    public function test_locking_records_the_before_and_after_in_the_audit_log(): void
    {
        $this->deploy();

        $handler = $this->handler();

        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.users.handlers.activation', $handler), ['is_active' => '0'])
            ->assertSessionHasNoErrors();

        $audit = \App\Models\AuditLog::query()
            ->where('auditable_type', User::class)
            ->where('auditable_id', $handler->id)
            ->where('event', 'updated')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(['is_active' => true], $audit->old_values);
        $this->assertSame(['is_active' => false], $audit->new_values);
    }

    /** Which activity action slug each tab's endpoint writes under. */
    private function prefixFor(string $tab): string
    {
        return match ($tab) {
            'handler' => 'handlers',
            'sponsorship' => 'sponsors',
            'monitoring' => 'monitors',
            default => 'users',
        };
    }

    /* ---------------------------------------------------------------------
     | Lockout guards. Both posted directly, with no control on screen.
     * ------------------------------------------------------------------ */

    public function test_nobody_can_lock_their_own_account_on_any_endpoint(): void
    {
        $this->deploy();

        /*
         | Each actor is the account it posts, and holds the one permission its own
         | endpoint demands — so the request gets past the route middleware and the
         | cross-tab check, and the self-lock guard is the only thing left that can
         | refuse it. No control for any of these is ever drawn: this is the crafted
         | PUT the hidden icon does not stop.
         */
        $attempts = [
            'admin.settings.users.activation' => $this->userWith(['users.update']),
            'admin.settings.users.handlers.activation' => $this->userWith(['handlers.update'], ['is_handler' => true]),
            'admin.settings.users.sponsors.activation' => $this->userWith(['sponsors.update'], ['is_sponsor' => true]),
            'admin.settings.users.monitors.activation' => $this->userWith(['monitors.update'], ['is_monitor' => true]),
        ];

        foreach ($attempts as $name => $account) {
            $this->actingAs($account)
                ->put(route($name, $account), ['is_active' => '0'])
                ->assertSessionHasErrors('user');

            $this->assertTrue($account->fresh()->is_active, $name);

            // The attempt is left behind at WARN, because "who tried to switch
            // this off" is what somebody will be looking for afterwards.
            $this->assertDatabaseHas('activity_logs', [
                'user_id' => $account->id,
                'level' => AdminLogger::LEVEL_WARN,
            ]);
        }
    }

    public function test_the_self_lock_guard_does_not_stop_an_operator_unlocking_somebody_else(): void
    {
        $this->deploy();

        $operator = $this->userWith(['users.view', 'users.update']);
        $admin = $this->administrator();
        $admin->forceFill(['is_active' => false])->save();

        // The guard covers deactivation only. Nobody can re-activate their OWN row
        // from here in any case: an inactive account cannot sign in, so it never
        // reaches this screen — which is exactly why somebody else must be able to.
        $this->actingAs($operator)
            ->put(route('admin.settings.users.activation', $admin), ['is_active' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_the_last_active_super_admin_cannot_be_locked(): void
    {
        $this->deploy();

        $owner = $this->superAdmin();

        // Posted by somebody else, so the self-lock guard above is not what refuses
        // it: this is the last-administering-account rule on its own.
        $operator = $this->userWith(['users.view', 'users.update']);

        $this->actingAs($operator)
            ->put(route('admin.settings.users.activation', $owner), ['is_active' => '0'])
            ->assertSessionHasErrors('user');

        $this->assertTrue($owner->fresh()->is_active);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $operator->id,
            'action' => 'users.update',
            'level' => AdminLogger::LEVEL_WARN,
        ]);
    }

    public function test_one_of_two_active_super_admins_can_be_locked(): void
    {
        $this->deploy();

        $owner = $this->superAdmin();
        $spare = $this->superAdmin('Spare Owner Hotel');

        $this->actingAs($spare)
            ->put(route('admin.settings.users.activation', $owner), ['is_active' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($owner->fresh()->is_active);
    }

    public function test_an_already_inactive_super_admin_does_not_count_towards_the_last_one(): void
    {
        $this->deploy();

        $owner = $this->superAdmin();
        $this->superAdmin('Switched Off India')->forceFill(['is_active' => false])->save();

        // Two super admin rows exist, but only one of them can sign in, so that
        // one is still the last administering account.
        $this->actingAs($this->userWith(['users.view', 'users.update']))
            ->put(route('admin.settings.users.activation', $owner), ['is_active' => '0'])
            ->assertSessionHasErrors('user');

        $this->assertTrue($owner->fresh()->is_active);
    }

    /* ---------------------------------------------------------------------
     | Permissions and cross-tab ids
     * ------------------------------------------------------------------ */

    public function test_each_endpoint_needs_its_own_tabs_update_permission(): void
    {
        $this->deploy();

        $rows = [
            'admin.settings.users.activation' => [$this->administrator(), 'users.update'],
            'admin.settings.users.handlers.activation' => [$this->handler(), 'handlers.update'],
            'admin.settings.users.sponsors.activation' => [$this->sponsor(), 'sponsors.update'],
            'admin.settings.users.monitors.activation' => [$this->monitor(), 'monitors.update'],
        ];

        foreach ($rows as $name => [$row, $needed]) {
            // Everything except the one permission this endpoint wants.
            $others = array_values(array_diff(
                ['users.update', 'handlers.update', 'sponsors.update', 'monitors.update'],
                [$needed],
            ));

            $this->actingAs($this->userWith($others))
                ->put(route($name, $row), ['is_active' => '0'])
                ->assertForbidden();

            $this->assertTrue($row->fresh()->is_active, $name);

            $this->actingAs($this->userWith([$needed]))
                ->put(route($name, $row), ['is_active' => '0'])
                ->assertSessionHasNoErrors();

            $this->assertFalse($row->fresh()->is_active, $name);
        }
    }

    public function test_an_id_from_another_tab_is_refused_on_every_endpoint(): void
    {
        $this->deploy();

        $owner = $this->superAdmin();

        $crossed = [
            'admin.settings.users.activation' => $this->handler(),
            'admin.settings.users.handlers.activation' => $this->administrator(),
            'admin.settings.users.sponsors.activation' => $this->monitor(),
            'admin.settings.users.monitors.activation' => $this->sponsor(),
        ];

        foreach ($crossed as $name => $row) {
            $this->actingAs($owner)
                ->put(route($name, $row), ['is_active' => '0'])
                ->assertForbidden();

            $this->assertTrue($row->fresh()->is_active, $name);
        }
    }

    public function test_a_post_without_a_target_state_changes_nothing(): void
    {
        $this->deploy();

        $handler = $this->handler();

        // The target state is posted rather than flipped, so a submit that says
        // nothing must not be read as "whatever it is not now".
        $this->actingAs($this->superAdmin())
            ->putJson(route('admin.settings.users.handlers.activation', $handler), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_active');

        $this->assertTrue($handler->fresh()->is_active);
    }
}
