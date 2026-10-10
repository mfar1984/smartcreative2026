<?php

namespace Tests\Feature\Settings;

use App\Models\Coupon;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shape that took the Sponsorship tab down in production with a 500.
 *
 * A sponsorship funding a SHARED-code batch and holding NO blocks. That is the
 * ordinary state of a shared coupon, and it is the one the other fixtures did not
 * have: every one of them issued at least one block, so the tab was only ever
 * exercised with a non-empty block list.
 *
 * WHY AN EMPTY LIST BROKE IT, because the fix reads like noise without this
 *
 * UserController::sponsorBatches() maps the blocks to coupon names and concats the
 * shared batch names onto them. get() returns an ELOQUENT collection, and Eloquent's
 * map() only hands back a plain collection when the mapped result CONTAINS something
 * that is not a model. On an empty result that test is false, so it stayed an Eloquent
 * collection — concat() then poured strings into it and unique() called getKey() on
 * each one. "Call to a member function getKey() on string."
 *
 * So the bug needed an empty block list AND a shared batch to show itself, which is
 * why 74 passing sponsorship tests said nothing about it. The guard is the shape, not
 * the line.
 */
class SponsorshipTabSharedBatchTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $slug, array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => sprintf('Test %s', $slug),
            'username' => sprintf('%s-%s', $slug, uniqid()),
            'email' => sprintf('%s@example.test', uniqid()),
            'password' => 'Sponsor-Pass-123!',
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'is_active' => true,
        ], $overrides));
    }

    private function sponsorshipTab()
    {
        return $this->get(route('admin.settings.users', ['tab' => 'sponsorship']));
    }

    public function test_the_tab_opens_for_a_sponsorship_whose_only_coupon_is_a_shared_batch(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = $this->userWithRole(Role::SUPER_ADMIN);

        $sponsor = $this->userWithRole(Role::SPONSOR, [
            'is_sponsor' => true,
            'name' => 'Persatuan Kebajikan Kasih Sayang',
        ]);

        // Shared mode, so there is no allocation anywhere behind it. This is the
        // state the live database was in when the tab returned a 500.
        $coupon = Coupon::create([
            'kind' => Coupon::KIND_EVENT,
            'mode' => Coupon::MODE_SHARED,
            'name' => 'POSTER50',
            'quantity' => 10,
            'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
            'discount_value' => 50,
            'expires_at' => now()->addMonth()->toDateString(),
            'design' => 'classic',
            'sponsor_user_id' => $sponsor->id,
        ]);

        $this->assertSame(0, $coupon->allocations()->count(), 'a shared batch must have no blocks');

        $this->actingAs($admin)
            ->sponsorshipTab()
            ->assertOk()
            ->assertSee($sponsor->name)
            ->assertSee('POSTER50');
    }

    public function test_the_tab_opens_for_a_sponsorship_funding_nothing_at_all(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = $this->userWithRole(Role::SUPER_ADMIN);

        $sponsor = $this->userWithRole(Role::SPONSOR, [
            'is_sponsor' => true,
            'name' => 'Belum Ditag Sdn Bhd',
        ]);

        // Both lists empty, which is every sponsorship the moment it is created.
        $this->actingAs($admin)
            ->sponsorshipTab()
            ->assertOk()
            ->assertSee($sponsor->name);
    }
}
