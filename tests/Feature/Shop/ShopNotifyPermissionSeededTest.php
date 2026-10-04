<?php

namespace Tests\Feature\Shop;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The new permission actually reaches a role, on a site that already has its roles.
 *
 * This is the failure the whole release hangs on, and it has already bitten once: the
 * seeder stopped syncing existing roles — correctly, because syncing undid every box
 * ticked by hand on each deployment — so a permission added with a new feature lands in
 * the catalogue granted to nobody. The route 403s, $canNotify is false, and the icon
 * renders for nobody but super-admin. No error anywhere. The screen looks exactly as it
 * did before the deploy.
 *
 * So two things are asserted together, and they pull in opposite directions:
 *   - shop.orders.notify reaches `administrator` even though that role already exists;
 *   - nothing else about that role is touched, including a permission somebody ticked
 *     by hand and one the seeder's own list would have granted.
 *
 * A re-run must be a no-op, because this seeder runs on every deployment.
 */
class ShopNotifyPermissionSeededTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'shop.orders.notify';

    /** Something a person ticked on the matrix that must survive the deploy. */
    private const HAND_TICKED = 'payments.refund';

    /** Something in the seeder's own administrator list, deliberately NOT re-granted. */
    private const NOT_BACKFILLED = 'shop.orders.refund';

    /**
     * One deployment. Named deploy() rather than seed(), because TestCase::seed()
     * already exists with a different signature.
     */
    private function deploy(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_the_permission_is_in_the_catalogue(): void
    {
        $this->deploy();

        $permission = Permission::where('slug', self::SLUG)->first();

        $this->assertNotNull($permission, 'shop.orders.notify must exist, or the route 403s for everyone.');
        $this->assertSame('Shop', $permission->group);
        $this->assertSame('Orders', $permission->module);
        $this->assertSame('notify', $permission->action);
    }

    public function test_a_fresh_install_grants_it_to_the_administrator_role(): void
    {
        $this->deploy();

        $administrator = Role::where('slug', 'administrator')->firstOrFail();

        $this->assertTrue(
            $administrator->permissions()->where('slug', self::SLUG)->exists(),
            'A fresh install must grant the new permission.',
        );
    }

    public function test_it_is_backfilled_onto_a_role_that_predates_the_release(): void
    {
        // A site as it is today: the catalogue seeded, the roles created, and then
        // somebody ticking and unticking boxes on the matrix.
        $this->deploy();

        $administrator = Role::where('slug', 'administrator')->firstOrFail();

        // Take the new permission away, exactly as a pre-release database would have
        // it: the role exists, the slug does not reach it.
        $administrator->permissions()->detach(
            Permission::where('slug', self::SLUG)->value('id')
        );

        // And take away one the seeder's own list contains, to prove the backfill is
        // the named slugs and not a quiet re-sync of everything.
        $administrator->permissions()->detach(
            Permission::where('slug', self::NOT_BACKFILLED)->value('id')
        );

        $this->assertFalse($administrator->permissions()->where('slug', self::SLUG)->exists());

        // Run the deployment again.
        $this->deploy();

        $this->assertTrue(
            $administrator->permissions()->where('slug', self::SLUG)->exists(),
            'The backfill must reach a role that already existed, or the icon is invisible in production.',
        );

        $this->assertFalse(
            $administrator->permissions()->where('slug', self::NOT_BACKFILLED)->exists(),
            'Only the named slugs are backfilled. Re-syncing the whole list is what broke this before.',
        );
    }

    public function test_it_does_not_undo_a_permission_ticked_by_hand(): void
    {
        $this->deploy();

        $viewer = Role::where('slug', 'viewer')->firstOrFail();

        // Viewer is read-only in the seeder. Somebody granted it a refund permission
        // on the matrix; that is their decision, and a deploy must not revert it.
        $viewer->permissions()->syncWithoutDetaching(
            Permission::where('slug', self::HAND_TICKED)->value('id')
        );

        $before = $viewer->permissions()->count();

        $this->deploy();

        $this->assertTrue(
            $viewer->permissions()->where('slug', self::HAND_TICKED)->exists(),
            'A hand-ticked grant must survive a re-seed.',
        );

        $this->assertSame($before, $viewer->permissions()->count());

        // And the read-only role does not quietly gain the ability to email customers.
        $this->assertFalse($viewer->permissions()->where('slug', self::SLUG)->exists());
    }

    public function test_running_the_seeder_twice_changes_nothing(): void
    {
        $this->deploy();

        $administrator = Role::where('slug', 'administrator')->firstOrFail();
        $before = $administrator->permissions()->pluck('slug')->sort()->values()->all();

        $this->deploy();

        $after = $administrator->fresh()->permissions()->pluck('slug')->sort()->values()->all();

        $this->assertSame($before, $after);
    }
}
