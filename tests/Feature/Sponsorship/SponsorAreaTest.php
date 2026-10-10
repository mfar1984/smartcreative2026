<?php

namespace Tests\Feature\Sponsorship;

use App\Models\ActivityLog;
use App\Models\Coupon;
use App\Models\CouponAllocation;
use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use App\Services\Coupon\CouponRedeemer;
use App\Support\AdminNavigation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Coupon\CouponTestCase;

/**
 * The sponsor's own area: what they see, and everything they cannot reach.
 *
 * A sponsorship account is MONITOR AND VIEW ONLY. It signs in at the same
 * /admin/login, lands on its own screen, and from there sees the blocks of codes it
 * funded: whose block is finished, whose is untouched, and who used a code — by name.
 *
 * WHY THE REFUSALS ARE ASSERTED BY URL
 *
 * A listing that merely hides a link is not a guard. A sponsor's interest spans
 * Coupon, Event and Shop, and every one of those modules has screens that total money
 * and sit beside participants' IC numbers and other sponsors' figures. So each of
 * those surfaces is asked for by URL here, the way the tournament handler scope tests
 * ask for an unassigned tournament.
 *
 * The sponsor's own area carries NO sponsor id in its path, which is the strongest
 * form of that guard: there is no number to edit. The one id the screen takes — the
 * block filter — is resolved against the signed-in sponsor's own blocks, and the test
 * below hands it another sponsor's block id to prove it.
 */
class SponsorAreaTest extends CouponTestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Sponsor-Pass-123!';

    /** A participant's details, which must never appear on a sponsor's screen. */
    private const REDEEMER_IC = '880101121234';

    private const REDEEMER_PHONE = '0191234567';

    /** A representative's details, which stay with the office for the same reason. */
    private const HOLDER_IC = '901010101010';

    private const HOLDER_PHONE = '0123334444';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function sponsor(string $name, ?float $committed = null): User
    {
        return User::create([
            'name' => $name,
            'username' => 'sponsor-'.uniqid(),
            'email' => uniqid().'@example.test',
            'password' => self::PASSWORD,
            'role_id' => Role::where('slug', Role::SPONSOR)->firstOrFail()->id,
            'is_active' => true,
            'is_sponsor' => true,
            'sponsor_committed_amount' => $committed,
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Office Admin',
            'username' => 'admin-'.uniqid(),
            'email' => uniqid().'@example.test',
            'password' => self::PASSWORD,
            'role_id' => Role::where('slug', Role::SUPER_ADMIN)->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    /** A RM20 fixed coupon in unique mode, ticked on one RM100 event. */
    private function batch(Event $event, string $name): Coupon
    {
        $coupon = $this->uniqueCoupon([
            'name' => $name,
            'discount_type' => Coupon::DISCOUNT_FIXED,
            'discount_value' => 20,
        ]);

        $coupon->events()->attach($event);

        return $coupon->fresh();
    }

    /**
     * A block of codes, tagged to the sponsorship that funded it.
     *
     * The IC is passed in and is different for every representative on purpose: a
     * holder is matched on their identity key, and the IC wins that match, so
     * reusing one would merge three representatives into one holder row.
     */
    private function block(
        Coupon $coupon,
        User $sponsor,
        int $codes,
        string $holder,
        string $ic,
        string $phone,
    ): CouponAllocation {
        $allocation = $this->issue($coupon, $codes, [
            'full_name' => $holder,
            'ic_number' => $ic,
            'phone' => $phone,
        ]);

        $allocation->forceFill(['sponsor_user_id' => $sponsor->id])->save();

        return $allocation->fresh();
    }

    /**
     * Spend one code of a block on a one-person registration, naming the redeemer.
     *
     * The participant carries a real IC and phone, because what this suite has to
     * prove is that neither of them reaches a sponsor's screen.
     */
    private function redeem(CouponAllocation $block, Event $event, string $person): void
    {
        $registration = $this->registration($event, [], 1);

        $registration->participants()->first()->update([
            'full_name' => $person,
            'ic_number' => self::REDEEMER_IC,
            'phone' => self::REDEEMER_PHONE,
        ]);

        $code = $block->codes()->unused()->orderBy('id')->firstOrFail();

        $outcome = app(CouponRedeemer::class)->claim(
            $block->coupon->fresh(),
            $event->registrationAmount(),
            1,
            $registration->fresh(['participants']),
            null,
            $code,
        );

        $this->assertTrue($outcome->succeeded(), 'The fixture redemption must succeed.');
    }

    /**
     * The owner's two scenarios in one fixture.
     *
     * Maju pledged RM500 and holds two blocks: Siti's two codes, both spent, and
     * Ahmad's ten, untouched. Twelve codes at RM20 estimates RM240, two spent codes
     * really gave RM40, so RM460 of the pledge is left — four figures that are not
     * each other, which is the point of keeping them apart.
     *
     * @return array{0: User, 1: User, 2: CouponAllocation, 3: CouponAllocation, 4: CouponAllocation}
     */
    private function scenario(): array
    {
        $event = $this->event(['fee' => 100]);

        $maju = $this->sponsor('Syarikat Maju Sdn Bhd', 500);
        $other = $this->sponsor('Other NGO Berhad', 900);

        $batch = $this->batch($event, 'MAJU20');

        $siti = $this->block($batch, $maju, 2, 'Siti Representative', self::HOLDER_IC, self::HOLDER_PHONE);
        $ahmad = $this->block($batch, $maju, 10, 'Ahmad Representative', '901010202020', '0123335555');
        $theirs = $this->block($batch, $other, 5, 'Rosli Of The Other NGO', '901010303030', '0123336666');

        $this->redeem($siti, $event, 'Aminah Binti Yusof');
        $this->redeem($siti, $event, 'Hassan Bin Omar');
        $this->redeem($theirs, $event, 'Zainab Of The Other Block');

        return [$maju, $other, $siti, $ahmad, $theirs];
    }

    /* ---------------------------------------------------------------------
     | Signing in and landing
     * ------------------------------------------------------------------ */

    public function test_a_sponsor_signs_in_at_the_admin_login_and_lands_on_their_own_area(): void
    {
        $sponsor = $this->sponsor('Syarikat Maju Sdn Bhd', 500);

        $this->assertTrue($sponsor->canAccessAdmin(), 'The sponsor role must hold admin.access and be active.');

        $response = $this->post(route('admin.login.attempt'), [
            'username' => $sponsor->username,
            'password' => self::PASSWORD,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($sponsor);
        $response->assertRedirect(route('admin.sponsorship.index'));
    }

    public function test_an_administrator_still_lands_on_the_dashboard(): void
    {
        $admin = $this->admin();

        $this->post(route('admin.login.attempt'), [
            'username' => $admin->username,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('admin.dashboard'));
    }

    public function test_the_sponsor_sidebar_shows_their_area_and_nothing_else(): void
    {
        [$maju] = $this->scenario();

        $labels = collect(AdminNavigation::for($maju))->pluck('label')->all();

        $this->assertSame(['Sponsorship'], $labels, 'A sponsor sees one menu item and no section headings.');

        // And the sidebar itself links nowhere else, including from the brand: a
        // sponsor does not hold dashboard.view, so a logo pointing at it would be a
        // link straight to a 403.
        $response = $this->actingAs($maju)->get(route('admin.sponsorship.index'));

        $response->assertOk();

        /*
         | Matched with the closing quote of the href, because the dashboard URL is
         | /admin and so is a prefix of every other admin path — without the quote
         | this could never fail. With it, it says what it means: nothing on the
         | page links AT the dashboard.
         */
        $response->assertDontSee(route('admin.dashboard').'"', false);
        $response->assertDontSee(route('admin.coupons.index').'"', false);
        $response->assertDontSee(route('admin.settings.users').'"', false);

        // The brand goes to the one screen they can be on.
        $response->assertSee(route('admin.sponsorship.index').'"', false);
    }

    /* ---------------------------------------------------------------------
     | The four figures
     * ------------------------------------------------------------------ */

    public function test_the_four_figures_are_correct_and_are_not_each_other(): void
    {
        [$maju] = $this->scenario();

        $response = $this->actingAs($maju)->get(route('admin.sponsorship.index'));

        $response->assertOk();

        $response->assertSee('RM 500.00');   // committed: the pledge
        $response->assertSee('RM 240.00');   // estimated: 12 codes x RM20
        $response->assertSee('RM 40.00');    // actual: two codes really spent
        $response->assertSee('RM 460.00');   // remaining: pledge less the actual

        // And each one is labelled as the kind of figure it is, because an estimate
        // presented as a fact is how a sponsor's money appears to vanish.
        $response->assertSee('Committed');
        $response->assertSee('Estimated allocated');
        $response->assertSee('Actually used');
        $response->assertSee('Sponsorship left');
    }

    public function test_the_committed_amount_is_the_sponsors_own_and_not_the_batch_figure(): void
    {
        [$maju, , $siti] = $this->scenario();

        // A figure on the coupon type, which belongs to the batch and not to this
        // sponsorship. The same batch carries another sponsor's block.
        $siti->coupon->forceFill(['committed_amount' => 99999])->save();

        $response = $this->actingAs($maju)->get(route('admin.sponsorship.index'));

        $response->assertOk();
        $response->assertSee('RM 500.00');
        $response->assertDontSee('RM 99,999.00');
    }

    public function test_a_sponsorship_with_no_pledge_shows_no_committed_figure_rather_than_zero(): void
    {
        $sponsor = $this->sponsor('Pledgeless Sponsor');

        $response = $this->actingAs($sponsor)->get(route('admin.sponsorship.index'));

        $response->assertOk();
        $response->assertSee('no pledge recorded yet');
        $response->assertSee('needs a committed amount');
    }

    /* ---------------------------------------------------------------------
     | Their blocks, and only theirs
     * ------------------------------------------------------------------ */

    public function test_a_sponsor_sees_their_own_blocks_with_used_and_unused_counts(): void
    {
        [$maju] = $this->scenario();

        $response = $this->actingAs($maju)->get(route('admin.sponsorship.index'));

        $response->assertOk();

        $response->assertSee('Siti Representative');
        $response->assertSee('Ahmad Representative');

        // The NGO's whole question: whose block is finished, whose is untouched.
        $response->assertSee('Finished');
        $response->assertSee('Untouched');
    }

    public function test_a_sponsor_does_not_see_a_block_belonging_to_another_sponsor(): void
    {
        [$maju] = $this->scenario();

        $response = $this->actingAs($maju)->get(route('admin.sponsorship.index'));

        $response->assertOk();
        $response->assertDontSee('Rosli Of The Other NGO');
        $response->assertDontSee('Zainab Of The Other Block');

        // Nor the other sponsorship's money.
        $response->assertDontSee('RM 900.00');
    }

    public function test_another_sponsors_block_id_in_the_filter_reaches_nothing(): void
    {
        [$maju, , , , $theirs] = $this->scenario();

        $response = $this->actingAs($maju)
            ->get(route('admin.sponsorship.index', ['tab' => 'event', 'block' => $theirs->id]));

        // Not a 404 on purpose: the id is simply not one of theirs, so the screen
        // falls back to everything this sponsor funded rather than reaching across.
        $response->assertOk();
        $response->assertDontSee('Zainab Of The Other Block');
        $response->assertDontSee('Rosli Of The Other NGO');
        $response->assertSee('Aminah Binti Yusof');
    }

    public function test_the_block_filter_narrows_to_one_of_their_own_blocks(): void
    {
        [$maju, , $siti] = $this->scenario();

        $response = $this->actingAs($maju)
            ->get(route('admin.sponsorship.index', ['tab' => 'event', 'block' => $siti->id]));

        $response->assertOk();
        $response->assertSee('Aminah Binti Yusof');
        $response->assertSee('Showing one block only');
    }

    /* ---------------------------------------------------------------------
     | Who used the codes: names, and nothing else
     * ------------------------------------------------------------------ */

    public function test_a_sponsor_sees_redeemer_names_and_no_ic_or_phone_number(): void
    {
        [$maju] = $this->scenario();

        $response = $this->actingAs($maju)->get(route('admin.sponsorship.index', ['tab' => 'event']));

        $response->assertOk();

        // The names are the point of the screen.
        $response->assertSee('Aminah Binti Yusof');
        $response->assertSee('Hassan Bin Omar');

        // The absence is asserted, not assumed. A redeemer is a member of the
        // public: their IC and phone are on the registration and stay there.
        $response->assertDontSee(self::REDEEMER_IC);
        $response->assertDontSee(self::REDEEMER_PHONE);

        // And the representative's own contact details stay with the office too,
        // which leaves no IC and no phone number anywhere on the page.
        $response->assertDontSee(self::HOLDER_IC);
        $response->assertDontSee(self::HOLDER_PHONE);
    }

    public function test_the_usage_list_shows_the_discount_and_no_other_money(): void
    {
        [$maju, , $siti] = $this->scenario();

        $response = $this->actingAs($maju)->get(route('admin.sponsorship.index', ['tab' => 'event']));

        $response->assertOk();

        // The discount their own sponsorship funded, per use.
        $response->assertSee('RM 20.00');

        // Not what the participant was charged, and no reference to look it up by.
        $response->assertDontSee('RM 100.00');
        $response->assertDontSee($siti->codes()->used()->first()->redemption->registration->reference);
    }

    public function test_the_usage_time_is_shown_on_the_office_clock(): void
    {
        // 16:30 UTC is already the next day in Malaysia, which is where this
        // project has had the same off-by-eight-hours bug four times.
        Carbon::setTestNow(Carbon::parse('2026-03-10 16:30:00', 'UTC'));

        try {
            [$maju] = $this->scenario();

            $response = $this->actingAs($maju)->get(route('admin.sponsorship.index', ['tab' => 'event']));

            $response->assertOk();
            $response->assertSee('11 Mar 2026');
            $response->assertDontSee('10 Mar 2026');
        } finally {
            Carbon::setTestNow();
        }
    }

    /* ---------------------------------------------------------------------
     | The CSV export
     * ------------------------------------------------------------------ */

    public function test_the_block_export_carries_only_their_own_rows_and_is_logged(): void
    {
        [$maju] = $this->scenario();

        $response = $this->actingAs($maju)
            ->get(route('admin.sponsorship.export', ['set' => 'blocks']));

        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Siti Representative', $csv);
        $this->assertStringContainsString('Ahmad Representative', $csv);
        $this->assertStringNotContainsString('Rosli Of The Other NGO', $csv);

        // No contact details in the file either, for the same reason they are not
        // on the screen.
        $this->assertStringNotContainsString(self::HOLDER_IC, $csv);
        $this->assertStringNotContainsString(self::HOLDER_PHONE, $csv);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $maju->id,
            'action' => 'sponsorship.export',
        ]);
    }

    public function test_the_usage_export_carries_only_their_own_redeemers_and_is_logged(): void
    {
        [$maju] = $this->scenario();

        $response = $this->actingAs($maju)
            ->get(route('admin.sponsorship.export', ['set' => 'event']));

        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Aminah Binti Yusof', $csv);
        $this->assertStringContainsString('Hassan Bin Omar', $csv);

        // Another sponsor's redeemer is not in the file.
        $this->assertStringNotContainsString('Zainab Of The Other Block', $csv);

        // A name is a disclosure even when it is only a name, so the file carries
        // no IC and no phone number.
        $this->assertStringNotContainsString(self::REDEEMER_IC, $csv);
        $this->assertStringNotContainsString(self::REDEEMER_PHONE, $csv);

        $this->assertSame(1, ActivityLog::where('user_id', $maju->id)
            ->where('action', 'sponsorship.export')
            ->count());
    }

    /* ---------------------------------------------------------------------
     | Everything a sponsor cannot reach, asked for by URL
     * ------------------------------------------------------------------ */

    public function test_a_sponsor_is_refused_every_staff_screen_by_url(): void
    {
        [$maju, , $siti] = $this->scenario();

        $urls = [
            route('admin.dashboard'),
            route('admin.coupons.index'),
            route('admin.coupons.report'),
            route('admin.coupons.report.show', $siti->coupon),
            route('admin.coupons.tracking'),
            route('admin.event.registration'),
            route('admin.event.participants'),
            route('admin.shop.products'),
            route('admin.shop.orders'),
            route('admin.payments.overview'),
            route('admin.payments.transactions'),
            route('admin.settings.users'),
        ];

        foreach ($urls as $url) {
            $this->actingAs($maju)->get($url)->assertForbidden();
        }
    }

    public function test_a_sponsor_cannot_tag_a_block_to_themselves(): void
    {
        [$maju, , , , $theirs] = $this->scenario();

        $this->actingAs($maju)
            ->put(route('admin.coupons.allocations.sponsor', [
                'coupon' => $theirs->coupon,
                'allocation' => $theirs,
            ]), ['sponsor_user_id' => $maju->id])
            ->assertForbidden();

        $this->assertDatabaseHas('coupon_allocations', [
            'id' => $theirs->id,
            'sponsor_user_id' => $theirs->sponsor_user_id,
        ]);
    }

    public function test_a_staff_account_without_the_area_permission_is_refused_it(): void
    {
        // Everything a coupon administrator holds, and neither the sponsor's own
        // area nor the office's reading of one.
        $this->actingAs($this->couponAdmin())
            ->get(route('admin.sponsorship.index'))
            ->assertForbidden();
    }

    /* ---------------------------------------------------------------------
     | The ?sponsor= id, and who may pass one
     * ------------------------------------------------------------------ */

    /**
     * REFUSED, not quietly narrowed to their own.
     *
     * Silently ignoring the id would hide the attempt, and the screen would read as
     * though the id had been honoured. A 403 is the honest answer: naming a
     * sponsorship is a staff act and a sponsorship account is never staff.
     */
    public function test_a_sponsor_passing_another_sponsorships_id_is_refused(): void
    {
        [$maju, $other] = $this->scenario();

        $this->actingAs($maju)
            ->get(route('admin.sponsorship.index', ['sponsor' => $other->id]))
            ->assertForbidden();

        // The export is the same screen as a file, so it is the same gate.
        $this->actingAs($maju)
            ->get(route('admin.sponsorship.export', ['set' => 'event', 'sponsor' => $other->id]))
            ->assertForbidden();
    }

    /** Even their OWN id, because passing one at all is the thing being refused. */
    public function test_a_sponsor_passing_their_own_id_is_refused_as_well(): void
    {
        [$maju] = $this->scenario();

        $this->actingAs($maju)
            ->get(route('admin.sponsorship.index', ['sponsor' => $maju->id]))
            ->assertForbidden();

        // And with no id they still see their own, exactly as before.
        $this->actingAs($maju)
            ->get(route('admin.sponsorship.index'))
            ->assertOk()
            ->assertSee('Siti Representative');
    }

    public function test_a_super_admin_can_open_any_sponsorship_by_id(): void
    {
        [$maju, $other] = $this->scenario();

        $response = $this->actingAs($this->admin())
            ->get(route('admin.sponsorship.index', ['sponsor' => $maju->id]));

        $response->assertOk();
        $response->assertSee($maju->name);
        $response->assertSee('Siti Representative');
        $response->assertSee('RM 500.00');

        // One sponsorship at a time: opening Maju does not show the other's rows.
        $response->assertDontSee('Rosli Of The Other NGO');

        $theirs = $this->actingAs($this->admin())
            ->get(route('admin.sponsorship.index', ['sponsor' => $other->id]));

        $theirs->assertOk();
        $theirs->assertSee('Rosli Of The Other NGO');
        $theirs->assertDontSee('Siti Representative');
    }

    /**
     * The office's own permission, reused rather than reinvented.
     *
     * sponsors.view is what the Sponsorship tab on User Management is already behind,
     * so there is one answer to "who may read somebody else's sponsorship".
     */
    public function test_a_staff_account_holding_the_sponsorship_view_permission_can_open_one(): void
    {
        [$maju] = $this->scenario();

        $operator = $this->userWith(['sponsors.view']);

        $response = $this->actingAs($operator)
            ->get(route('admin.sponsorship.index', ['sponsor' => $maju->id]));

        $response->assertOk();
        $response->assertSee('Siti Representative');

        // With nobody named they get the list, which is somewhere useful.
        $this->actingAs($operator)
            ->get(route('admin.sponsorship.index'))
            ->assertOk()
            ->assertSee($maju->name);
    }

    public function test_a_staff_account_without_that_permission_cannot_open_one_by_url(): void
    {
        [$maju] = $this->scenario();

        // Every coupon permission there is, and not sponsors.view.
        $this->actingAs($this->couponAdmin())
            ->get(route('admin.sponsorship.index', ['sponsor' => $maju->id]))
            ->assertForbidden();

        $this->actingAs($this->couponAdmin())
            ->get(route('admin.sponsorship.export', ['set' => 'blocks', 'sponsor' => $maju->id]))
            ->assertForbidden();
    }

    public function test_an_id_that_is_not_a_sponsorship_account_is_a_404(): void
    {
        $this->scenario();

        $admin = $this->admin();

        // An administrator account, which funds nothing and is not a sponsorship.
        $this->actingAs($admin)
            ->get(route('admin.sponsorship.index', ['sponsor' => $admin->id]))
            ->assertNotFound();

        $this->actingAs($admin)
            ->get(route('admin.sponsorship.index', ['sponsor' => 99999]))
            ->assertNotFound();
    }

    /**
     * A super admin naming nobody gets the LIST, not their own empty figures.
     *
     * This used to open on their own sponsorship, which is nothing, and the owner's
     * complaint was exactly that: "sepatutnya super admin akan nampak semua". A
     * staff account funds nothing, so four zeroes above an empty table is an honest
     * answer to a question nobody asked. The list is the useful one.
     */
    public function test_a_super_admin_naming_nobody_lands_on_the_list_of_sponsorships(): void
    {
        [$maju, $other] = $this->scenario();

        $response = $this->actingAs($this->admin())->get(route('admin.sponsorship.index'));

        $response->assertOk();

        // Every sponsorship there is, with what each one really gave away.
        $response->assertSee($maju->name);
        $response->assertSee($other->name);
        $response->assertSee('RM 500.00');
        $response->assertSee('RM 900.00');

        // And a way into each one.
        $response->assertSee(route('admin.sponsorship.index', ['sponsor' => $maju->id]), false);

        // Not their own empty area, which is what it used to show.
        $response->assertDontSee('No coupon blocks have been tagged to this sponsorship yet');
    }

    /* ---------------------------------------------------------------------
     | Staff tagging, which is how a block gets a sponsor at all
     * ------------------------------------------------------------------ */

    public function test_staff_tag_a_block_to_a_sponsorship_from_the_report_screen(): void
    {
        $event = $this->event(['fee' => 100]);
        $batch = $this->batch($event, 'MAJU20');
        $block = $this->issue($batch, 4, ['full_name' => 'Siti Representative']);

        $sponsor = $this->sponsor('Syarikat Maju Sdn Bhd', 500);

        $response = $this->actingAs($this->couponAdmin())
            ->put(route('admin.coupons.allocations.sponsor', ['coupon' => $batch, 'allocation' => $block]), [
                'sponsor_user_id' => $sponsor->id,
            ]);

        $response->assertRedirect(route('admin.coupons.report.show', $batch));

        $this->assertDatabaseHas('coupon_allocations', [
            'id' => $block->id,
            'sponsor_user_id' => $sponsor->id,
        ]);

        // And the sponsor can now see it.
        $this->actingAs($sponsor)
            ->get(route('admin.sponsorship.index'))
            ->assertSee('Siti Representative');
    }

    public function test_tagging_every_block_on_a_batch_tags_each_one(): void
    {
        $event = $this->event(['fee' => 100]);
        $batch = $this->batch($event, 'MAJU20');

        $first = $this->issue($batch, 2, ['full_name' => 'Siti Representative']);
        $second = $this->issue($batch, 3, ['full_name' => 'Ahmad Representative']);

        $sponsor = $this->sponsor('Syarikat Maju Sdn Bhd', 500);

        $this->actingAs($this->couponAdmin())
            ->put(route('admin.coupons.allocations.sponsor', ['coupon' => $batch, 'allocation' => $first]), [
                'sponsor_user_id' => $sponsor->id,
                'apply_to' => 'batch',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($sponsor->id, (int) $first->fresh()->sponsor_user_id);
        $this->assertSame($sponsor->id, (int) $second->fresh()->sponsor_user_id);
    }

    public function test_a_non_sponsor_account_cannot_be_tagged_as_one(): void
    {
        $event = $this->event(['fee' => 100]);
        $batch = $this->batch($event, 'MAJU20');
        $block = $this->issue($batch, 2, ['full_name' => 'Siti Representative']);

        $admin = $this->admin();

        $this->actingAs($this->couponAdmin())
            ->put(route('admin.coupons.allocations.sponsor', ['coupon' => $batch, 'allocation' => $block]), [
                'sponsor_user_id' => $admin->id,
            ])
            ->assertSessionHasErrors('sponsor_user_id');

        $this->assertNull($block->fresh()->sponsor_user_id);
    }

    public function test_a_block_from_another_batch_is_refused_on_the_tagging_route(): void
    {
        $event = $this->event(['fee' => 100]);

        $batch = $this->batch($event, 'MAJU20');
        $otherBatch = $this->batch($event, 'OTHER20');

        $block = $this->issue($otherBatch, 2, ['full_name' => 'Siti Representative']);
        $sponsor = $this->sponsor('Syarikat Maju Sdn Bhd', 500);

        $this->actingAs($this->couponAdmin())
            ->put(route('admin.coupons.allocations.sponsor', ['coupon' => $batch, 'allocation' => $block]), [
                'sponsor_user_id' => $sponsor->id,
            ])
            ->assertNotFound();

        $this->assertNull($block->fresh()->sponsor_user_id);
    }

    /* ---------------------------------------------------------------------
     | Regression: the staff Report is unchanged apart from the new column
     * ------------------------------------------------------------------ */

    public function test_the_staff_report_still_shows_the_blocks_and_now_names_the_sponsorship(): void
    {
        [$maju, , $siti] = $this->scenario();

        $response = $this->actingAs($this->couponAdmin())
            ->get(route('admin.coupons.report.show', $siti->coupon));

        $response->assertOk();

        // Everything it showed before.
        $response->assertSee('Blocks Issued');
        $response->assertSee('Siti Representative');
        $response->assertSee('Rosli Of The Other NGO');

        // Plus who funded each block, which is the one thing that is new. The
        // office still sees the representative's contact details here, because
        // tracing a code back to whoever was given it is its job.
        $response->assertSee('Sponsorship');
        $response->assertSee($maju->name);
        $response->assertSee(self::HOLDER_IC);
    }
}
