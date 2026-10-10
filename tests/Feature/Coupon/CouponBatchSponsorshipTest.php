<?php

namespace Tests\Feature\Coupon;

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\CouponAllocation;
use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use App\Services\Coupon\RegistrationCouponWriter;
use App\Support\CouponSponsorship;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Who funded a BATCH, said on the coupon form, under THE CODE.
 *
 * WHAT THIS CLOSES
 *
 * The sponsorship used to be tagged only on an ALLOCATION — a block of unique codes —
 * from the Coupon Report. Two things were wrong with that, both found by the owner
 * using it:
 *
 *   he could not find it. The control only appeared once a sponsorship account
 *   existed, and only on a batch that had blocks, so on a shared-code batch there was
 *   nothing to tag and nothing explaining why.
 *
 *   a SHARED batch could not be sponsored at all. CouponIssuer refuses to issue
 *   unless the batch is unique, so a shared batch never gets an allocation, and the
 *   tag lived on the allocation. A sponsor pledging RM2,000 against RM100 coupons is
 *   perfectly well one shared code used twenty times; nobody decided to exclude that,
 *   it fell out of where the tag was put.
 *
 * THE RULE, which is asserted here from both ends:
 *
 *   THE BATCH-LEVEL SPONSORSHIP APPLIES TO EVERY BLOCK IN THE BATCH, UNLESS THAT
 *   BLOCK NAMES ITS OWN, WHICH OVERRIDES IT FOR THAT BLOCK ONLY.
 *
 * A shared batch has no block, so it appears in a sponsor's list with no handler and
 * says so in words rather than showing a blank row that reads as a bug.
 */
class CouponBatchSponsorshipTest extends CouponTestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Sponsor-Pass-123!';

    protected function setUp(): void
    {
        parent::setUp();

        // The sponsor role and sponsorship.portal.view come from the seeder, which
        // is what a sponsor signs in with.
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

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

    /**
     * The coupon form's own fields, shared mode unless told otherwise.
     *
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'kind' => Coupon::KIND_EVENT,
            'mode' => Coupon::MODE_SHARED,
            'name' => 'ABC123',
            'quantity' => 0,
            'expires_at' => now()->addMonth()->toDateString(),
            'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
            'discount_value' => 10,
            'design' => 'classic',
        ];
    }

    private function writer(): RegistrationCouponWriter
    {
        return app(RegistrationCouponWriter::class);
    }

    /** Spend one use of a shared code on a one-person registration, naming the person. */
    private function useSharedCode(Coupon $coupon, Event $event, string $person): void
    {
        $registration = $this->registration($event, people: 1);
        $registration->participants()->first()->update(['full_name' => $person]);

        $this->assertTrue(
            $this->writer()->applyCode($registration->fresh(['participants']), $coupon->name)->succeeded(),
            'The fixture redemption must succeed.',
        );
    }

    /** Spend one code of a block on a one-person registration, naming the person. */
    private function useBlockCode(CouponAllocation $block, Event $event, string $person): void
    {
        $registration = $this->registration($event, people: 1);
        $registration->participants()->first()->update(['full_name' => $person]);

        $this->assertTrue(
            $this->writer()->applyCode($registration->fresh(['participants']), $this->codeFrom($block))->succeeded(),
            'The fixture redemption must succeed.',
        );
    }

    /**
     * The rendered text of the quantity field's own label.
     *
     * Read out of the markup rather than asserted with assertSee, because the script
     * at the foot of the form carries BOTH wordings — it keeps the label right when
     * the mode is switched in the browser — so a plain assertDontSee could never
     * fail.
     */
    private function quantityLabel(string $html): string
    {
        preg_match('/<label[^>]*\bfor="quantity"[^>]*>\s*([^<\n]+)/', $html, $matches);

        return trim($matches[1] ?? '');
    }

    /* ---------------------------------------------------------------------
     | The field is on the form, and it is optional
     * ------------------------------------------------------------------ */

    public function test_the_field_is_on_the_create_form(): void
    {
        $sponsor = $this->sponsor('Syarikat Maju Sdn Bhd', 500);

        $response = $this->actingAs($this->couponAdmin())->get(route('admin.coupons.create'));

        $response->assertOk();
        $response->assertSee('name="sponsor_user_id"', false);
        $response->assertSee($sponsor->name);

        // And the rule is on the screen, not only in the code: two places that can
        // set the same thing is how this feature confused its owner the first time.
        $response->assertSee('unless that block names its own');
    }

    public function test_the_field_is_on_the_edit_form_and_shows_the_current_choice(): void
    {
        $sponsor = $this->sponsor('Syarikat Maju Sdn Bhd', 500);
        $coupon = $this->coupon(['name' => 'EDITME1', 'quantity' => 10, 'sponsor_user_id' => $sponsor->id]);

        $response = $this->actingAs($this->couponAdmin())->get(route('admin.coupons.edit', $coupon));

        $response->assertOk();
        $response->assertSee('name="sponsor_user_id"', false);
        $response->assertSee('value="'.$sponsor->id.'" selected', false);
    }

    public function test_saving_without_it_leaves_the_batch_unsponsored(): void
    {
        $this->sponsor('Syarikat Maju Sdn Bhd', 500);

        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $this->form(['name' => 'NOSPON1', 'quantity' => 10]))
            ->assertSessionHasNoErrors();

        $coupon = Coupon::query()->where('name', 'NOSPON1')->sole();

        // Optional, because most coupons have no sponsor at all.
        $this->assertNull($coupon->sponsor_user_id);
        $this->assertFalse($coupon->hasSponsor());
    }

    public function test_the_choice_round_trips_and_can_be_cleared(): void
    {
        $sponsor = $this->sponsor('Syarikat Maju Sdn Bhd', 500);
        $coupon = $this->coupon(['name' => 'ROUND1', 'quantity' => 10]);

        $this->actingAs($this->couponAdmin())
            ->put(route('admin.coupons.update', $coupon), $this->form([
                'name' => 'ROUND1',
                'quantity' => 10,
                'sponsor_user_id' => $sponsor->id,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame($sponsor->id, (int) $coupon->fresh()->sponsor_user_id);

        // Cleared again, which is what an empty box has to mean.
        $this->actingAs($this->couponAdmin())
            ->put(route('admin.coupons.update', $coupon), $this->form([
                'name' => 'ROUND1',
                'quantity' => 10,
                'sponsor_user_id' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertNull($coupon->fresh()->sponsor_user_id);
    }

    /* ---------------------------------------------------------------------
     | THE GAP: a shared-code batch can be sponsored
     * ------------------------------------------------------------------ */

    public function test_a_shared_batch_can_be_sponsored_and_reaches_the_sponsors_area(): void
    {
        $event = $this->event(['fee' => 100]);
        $maju = $this->sponsor('Syarikat Maju Sdn Bhd', 500);

        // One code on a poster, used ten times. There is no allocation anywhere in
        // this scenario, which is exactly why it used to be unsponsorable.
        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $this->form([
                'name' => 'POSTER20',
                'mode' => Coupon::MODE_SHARED,
                'quantity' => 10,
                'discount_type' => Coupon::DISCOUNT_FIXED,
                'discount_value' => 20,
                'sponsor_user_id' => $maju->id,
            ]))
            ->assertSessionHasNoErrors();

        $coupon = Coupon::query()->where('name', 'POSTER20')->sole();

        $this->assertTrue($coupon->isShared());
        $this->assertSame($maju->id, (int) $coupon->sponsor_user_id);
        $this->assertSame(0, $coupon->allocations()->count(), 'A shared batch has no blocks at all.');

        $coupon->events()->attach($event);

        $this->useSharedCode($coupon, $event, 'Aminah Binti Yusof');
        $this->useSharedCode($coupon, $event, 'Hassan Bin Omar');

        $response = $this->actingAs($maju)->get(route('admin.sponsorship.index'));

        $response->assertOk();

        // The batch is in the list, by name.
        $response->assertSee('POSTER20');

        // Its uses are counted: two of the ten the cap allows.
        $response->assertSee('RM 40.00');

        // With NO HOLDER, said in words. There is nobody to name — a shared code was
        // never handed to a representative — so this is not the same thing as a
        // block whose handler was left blank, and it must not read like one.
        $response->assertSee('One shared code — no block');
        $response->assertDontSee('Not assigned');

        // And the people who used it are on the screen, by name only.
        $response->assertSee('Aminah Binti Yusof');
        $response->assertSee('Hassan Bin Omar');

        // The figures agree with the screen.
        $figures = CouponSponsorship::forSponsor($maju->fresh());

        $this->assertSame(40.0, $figures['actual'], 'Two uses at RM20.');
        $this->assertSame(2, $figures['used']);
        $this->assertSame(10, $figures['codes'], 'The use cap is the same promise as ten codes.');
        $this->assertSame(0, $figures['blocks'], 'There is no block, and the figure says so.');
        $this->assertSame(1, $figures['shared_batches']);
    }

    public function test_an_unlimited_shared_batch_reads_as_unlimited_rather_than_zero(): void
    {
        $event = $this->event(['fee' => 100]);
        $maju = $this->sponsor('Syarikat Maju Sdn Bhd', 500);

        $coupon = $this->fixedCoupon(20, [
            'name' => 'ENDLESS1',
            'quantity' => 0,
            'sponsor_user_id' => $maju->id,
        ]);

        $coupon->events()->attach($event);
        $this->useSharedCode($coupon, $event, 'Aminah Binti Yusof');

        $response = $this->actingAs($maju)->get(route('admin.sponsorship.index'));

        $response->assertOk();
        $response->assertSee('ENDLESS1');

        // No denominator exists, so the column says Unlimited rather than printing a
        // zero that would read as "nothing left".
        $response->assertSee('Unlimited');

        $figures = CouponSponsorship::forSponsor($maju->fresh());

        $this->assertSame(20.0, $figures['actual'], 'The real discount still counts.');
        $this->assertSame(1, $figures['used']);
        $this->assertNull($figures['estimated'], 'Nothing to total, so nothing is claimed.');
        $this->assertSame(1, $figures['unpriced']);
    }

    /* ---------------------------------------------------------------------
     | The rule: batch level, and the block-level override
     * ------------------------------------------------------------------ */

    public function test_every_block_of_a_batch_tagged_at_batch_level_reads_as_that_sponsors(): void
    {
        $maju = $this->sponsor('Syarikat Maju Sdn Bhd', 500);

        $coupon = $this->uniqueCoupon(['name' => 'BATCHWIDE', 'sponsor_user_id' => $maju->id]);

        $siti = $this->issue($coupon, 4, ['full_name' => 'Siti Representative', 'ic_number' => '901010101010']);
        $ahmad = $this->issue($coupon, 6, ['full_name' => 'Ahmad Representative', 'ic_number' => '901010202020']);

        // Neither block names anybody: both follow the batch.
        $this->assertNull($siti->fresh()->sponsor_user_id);
        $this->assertNull($ahmad->fresh()->sponsor_user_id);

        foreach ([$siti, $ahmad] as $block) {
            $resolved = $block->fresh();

            $this->assertSame($maju->id, $resolved->effectiveSponsorId());
            $this->assertTrue($resolved->inheritsSponsor());
        }

        // And both are the sponsor's as far as every query is concerned.
        $this->assertEqualsCanonicalizing(
            [$siti->id, $ahmad->id],
            $maju->sponsoredBlocks()->pluck('coupon_allocations.id')->all(),
        );

        $response = $this->actingAs($maju)->get(route('admin.sponsorship.index'));

        $response->assertOk();
        $response->assertSee('Siti Representative');
        $response->assertSee('Ahmad Representative');
    }

    public function test_a_block_naming_its_own_sponsorship_overrides_the_batch_for_that_block_only(): void
    {
        $maju = $this->sponsor('Syarikat Maju Sdn Bhd', 500);
        $other = $this->sponsor('Other NGO Berhad', 900);

        $coupon = $this->uniqueCoupon(['name' => 'SPLIT123', 'sponsor_user_id' => $other->id]);

        $follows = $this->issue($coupon, 4, ['full_name' => 'Rosli Follows The Batch', 'ic_number' => '901010303030']);
        $overridden = $this->issue($coupon, 6, ['full_name' => 'Ahmad Was Overridden', 'ic_number' => '901010404040']);

        $this->actingAs($this->couponAdmin())
            ->put(route('admin.coupons.allocations.sponsor', ['coupon' => $coupon, 'allocation' => $overridden]), [
                'sponsor_user_id' => $maju->id,
            ])
            ->assertSessionHasNoErrors();

        // THAT BLOCK ONLY.
        $this->assertSame($maju->id, $overridden->fresh()->effectiveSponsorId());
        $this->assertSame($other->id, $follows->fresh()->effectiveSponsorId());

        $this->assertSame([$overridden->id], $maju->sponsoredBlocks()->pluck('coupon_allocations.id')->all());
        $this->assertSame([$follows->id], $other->sponsoredBlocks()->pluck('coupon_allocations.id')->all());

        // Each sponsor sees their own block and not the other's.
        $mine = $this->actingAs($maju)->get(route('admin.sponsorship.index'));
        $mine->assertSee('Ahmad Was Overridden');
        $mine->assertDontSee('Rosli Follows The Batch');

        $theirs = $this->actingAs($other)->get(route('admin.sponsorship.index'));
        $theirs->assertSee('Rosli Follows The Batch');
        $theirs->assertDontSee('Ahmad Was Overridden');

        // Clearing the override hands the block back to the batch rather than
        // making it unsponsored, and the staff screen says which it is.
        $this->actingAs($this->couponAdmin())
            ->put(route('admin.coupons.allocations.sponsor', ['coupon' => $coupon, 'allocation' => $overridden]), [
                'sponsor_user_id' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($other->id, $overridden->fresh()->effectiveSponsorId());
    }

    public function test_the_report_screen_says_which_blocks_come_from_the_batch(): void
    {
        $maju = $this->sponsor('Syarikat Maju Sdn Bhd', 500);
        $other = $this->sponsor('Other NGO Berhad', 900);

        $coupon = $this->uniqueCoupon(['name' => 'SPLIT456', 'sponsor_user_id' => $other->id]);

        $this->issue($coupon, 2, ['full_name' => 'Rosli Follows The Batch', 'ic_number' => '901010505050']);
        $overridden = $this->issue($coupon, 2, ['full_name' => 'Ahmad Was Overridden', 'ic_number' => '901010606060']);
        $overridden->forceFill(['sponsor_user_id' => $maju->id])->save();

        $response = $this->actingAs($this->couponAdmin())
            ->get(route('admin.coupons.report.show', $coupon));

        $response->assertOk();

        // The batch's own sponsorship, and the rule, both on the page.
        $response->assertSee('Sponsorship for the whole batch:');
        $response->assertSee($other->name);
        $response->assertSee('unless it names its own sponsorship');

        // A blank block is not "not sponsored" any more, and the select says so.
        $response->assertSee('Follow the batch ('.$other->name.')');
    }

    /* ---------------------------------------------------------------------
     | The four figures, across all three shapes at once
     * ------------------------------------------------------------------ */

    public function test_the_four_figures_cover_a_shared_batch_a_unique_batch_and_an_overridden_block(): void
    {
        $event = $this->event(['fee' => 100]);

        $maju = $this->sponsor('Syarikat Maju Sdn Bhd', 1000);
        $other = $this->sponsor('Other NGO Berhad', 900);

        /* ---- one SHARED batch: RM20, ten uses allowed, two taken ---- */
        $shared = $this->fixedCoupon(20, [
            'name' => 'SHARE20',
            'quantity' => 10,
            'sponsor_user_id' => $maju->id,
        ]);
        $shared->events()->attach($event);

        $this->useSharedCode($shared, $event, 'Aminah Binti Yusof');
        $this->useSharedCode($shared, $event, 'Hassan Bin Omar');

        /* ---- one UNIQUE batch of their own: RM20, five codes, one used ---- */
        $own = $this->uniqueCoupon([
            'name' => 'OWN20',
            'discount_type' => Coupon::DISCOUNT_FIXED,
            'discount_value' => 20,
            'sponsor_user_id' => $maju->id,
        ]);
        $own->events()->attach($event);

        $ownBlock = $this->issue($own, 5, ['full_name' => 'Siti Representative', 'ic_number' => '901010707070']);
        $this->useBlockCode($ownBlock, $event, 'Zaiton Binti Ali');

        /* ---- one block OVERRIDDEN to them, on somebody else's batch ---- */
        $theirs = $this->uniqueCoupon([
            'name' => 'THEIRS50',
            'discount_type' => Coupon::DISCOUNT_FIXED,
            'discount_value' => 50,
            'sponsor_user_id' => $other->id,
        ]);
        $theirs->events()->attach($event);

        $theirBlock = $this->issue($theirs, 3, ['full_name' => 'Rosli Of The Other NGO', 'ic_number' => '901010808080']);
        $borrowed = $this->issue($theirs, 2, ['full_name' => 'Ahmad Was Overridden', 'ic_number' => '901010909090']);
        $borrowed->forceFill(['sponsor_user_id' => $maju->id])->save();

        $this->useBlockCode($borrowed, $event, 'Fatimah Binti Hassan');

        $figures = CouponSponsorship::forSponsor($maju->fresh());

        /* ---- committed: a promise, typed by hand ---- */
        $this->assertSame(1000.0, $figures['committed']);

        /* ---- estimated: 10 shared uses + 5 own codes + 2 borrowed, at their own rates ---- */
        $this->assertSame(17, $figures['codes'], '10 shared uses, 5 own codes, 2 borrowed.');
        $this->assertSame(400.0, $figures['estimated'], '10x20 + 5x20 + 2x50.');

        /* ---- actual: the real discount on four uses, read off the ledger ---- */
        $this->assertSame(4, $figures['used']);
        $this->assertSame(110.0, $figures['actual'], '20 + 20 + 20 + 50.');

        /* ---- remaining: committed less ACTUAL, never less the estimate ---- */
        $this->assertSame(890.0, $figures['remaining']);

        /* ---- and all four are different numbers ---- */
        $this->assertCount(4, array_unique([
            $figures['committed'],
            $figures['estimated'],
            $figures['actual'],
            $figures['remaining'],
        ]));

        // Taken off the estimate, remaining would read RM600. It does not.
        $this->assertNotSame(600.0, $figures['remaining']);

        // Two blocks and one shared batch, counted apart because they are not the
        // same kind of thing.
        $this->assertSame(2, $figures['blocks']);
        $this->assertSame(1, $figures['shared_batches']);
        $this->assertSame(3, $figures['batches']);

        /* ---- and the other sponsor keeps only what is theirs ---- */
        $theirFigures = CouponSponsorship::forSponsor($other->fresh());

        $this->assertSame([$theirBlock->id], $other->sponsoredBlocks()->pluck('coupon_allocations.id')->all());
        $this->assertSame(3, $theirFigures['codes']);
        $this->assertSame(0.0, $theirFigures['actual'], 'Nothing of theirs has been used.');
        $this->assertSame(900.0, $theirFigures['remaining']);

        /* ---- the screen agrees with the figures ---- */
        $response = $this->actingAs($maju)->get(route('admin.sponsorship.index'));

        $response->assertOk();
        $response->assertSee('RM 1,000.00');  // committed
        $response->assertSee('RM 400.00');    // estimated
        $response->assertSee('RM 110.00');    // actually used
        $response->assertSee('RM 890.00');    // left

        $response->assertSee('SHARE20');
        $response->assertSee('OWN20');
        $response->assertSee('Ahmad Was Overridden');
        $response->assertDontSee('Rosli Of The Other NGO');
    }

    /* ---------------------------------------------------------------------
     | No sponsorship accounts yet
     * ------------------------------------------------------------------ */

    public function test_with_no_sponsorship_accounts_the_form_shows_the_link_instead_of_an_empty_control(): void
    {
        $response = $this->actingAs($this->couponAdmin())->get(route('admin.coupons.create'));

        $response->assertOk();

        // No control, because there is nothing to choose.
        $response->assertDontSee('name="sponsor_user_id"', false);

        // And it says so, with somewhere to go — rather than leaving the operator
        // hunting for a field that cannot exist yet, which is what the Report screen
        // used to do.
        $response->assertSee('There are no sponsorship accounts yet');
        $response->assertSee(route('admin.settings.users', ['tab' => 'sponsorship']), false);
    }

    public function test_the_report_screen_also_says_so_rather_than_drawing_nothing(): void
    {
        $coupon = $this->uniqueCoupon(['name' => 'NOACCTS1']);
        $this->issue($coupon, 2, ['full_name' => 'Siti Representative']);

        $response = $this->actingAs($this->couponAdmin())
            ->get(route('admin.coupons.report.show', $coupon));

        $response->assertOk();
        $response->assertSee('There are no sponsorship accounts yet, so there is nothing to tag a block to.');
        $response->assertSee(route('admin.settings.users', ['tab' => 'sponsorship']), false);
    }

    /* ---------------------------------------------------------------------
     | Only a sponsorship account, and only with the permission
     * ------------------------------------------------------------------ */

    public function test_a_non_sponsor_account_cannot_be_chosen(): void
    {
        $admin = $this->admin();

        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $this->form([
                'name' => 'NOTASPON',
                'quantity' => 10,
                'sponsor_user_id' => $admin->id,
            ]))
            ->assertSessionHasErrors('sponsor_user_id');

        // Without the is_sponsor condition any user id would be accepted, which
        // would quietly hand an administrator a sponsor's screen.
        $this->assertSame(0, Coupon::query()->where('name', 'NOTASPON')->count());
    }

    public function test_a_role_without_the_permission_neither_sees_the_field_nor_can_post_it(): void
    {
        $sponsor = $this->sponsor('Syarikat Maju Sdn Bhd', 500);

        // May create a coupon, may not edit one — which is the permission tagging a
        // sponsorship has always taken, on the Report screen before this.
        $creator = $this->userWith(['coupons.view', 'coupons.create']);

        $response = $this->actingAs($creator)->get(route('admin.coupons.create'));

        $response->assertOk();
        $response->assertDontSee('name="sponsor_user_id"', false);
        $response->assertDontSee($sponsor->name);

        $this->actingAs($creator)
            ->post(route('admin.coupons.store'), $this->form([
                'name' => 'NOPERM01',
                'quantity' => 10,
                'sponsor_user_id' => $sponsor->id,
            ]))
            ->assertSessionHasErrors('sponsor_user_id');

        $this->assertSame(0, Coupon::query()->where('name', 'NOPERM01')->count());
    }

    public function test_a_role_without_the_permission_may_still_create_an_unsponsored_coupon(): void
    {
        $creator = $this->userWith(['coupons.view', 'coupons.create']);

        $this->actingAs($creator)
            ->post(route('admin.coupons.store'), $this->form(['name' => 'PLAIN001', 'quantity' => 10]))
            ->assertSessionHasNoErrors();

        $this->assertNull(Coupon::query()->where('name', 'PLAIN001')->sole()->sponsor_user_id);
    }

    /* ---------------------------------------------------------------------
     | Nothing about this happens quietly
     * ------------------------------------------------------------------ */

    public function test_tagging_changing_and_clearing_are_each_logged_and_audited(): void
    {
        $first = $this->sponsor('Syarikat Maju Sdn Bhd', 500);
        $second = $this->sponsor('Other NGO Berhad', 900);

        $coupon = $this->coupon(['name' => 'TRAIL001', 'quantity' => 10]);

        /* ---- set, from nothing ---- */
        $this->actingAs($this->couponAdmin())
            ->put(route('admin.coupons.update', $coupon), $this->form([
                'name' => 'TRAIL001',
                'quantity' => 10,
                'sponsor_user_id' => $first->id,
            ]))
            ->assertSessionHasNoErrors();

        $line = ActivityLog::where('action', 'coupons.sponsor')->latest('id')->firstOrFail();

        $this->assertStringContainsString('TRAIL001', $line->description);
        $this->assertStringContainsString($first->logLabel(), $line->description);
        $this->assertStringContainsString('was not sponsored before', $line->description);

        $audit = AuditLog::where('event', 'coupon.sponsor_tagged')->latest('id')->firstOrFail();

        $this->assertNull($audit->old_values['sponsor']);
        $this->assertSame($first->logLabel(), $audit->new_values['sponsor']);

        /* ---- changed: BOTH ends named, because this moves money responsibility ---- */
        $this->actingAs($this->couponAdmin())
            ->put(route('admin.coupons.update', $coupon), $this->form([
                'name' => 'TRAIL001',
                'quantity' => 10,
                'sponsor_user_id' => $second->id,
            ]))
            ->assertSessionHasNoErrors();

        $line = ActivityLog::where('action', 'coupons.sponsor')->latest('id')->firstOrFail();

        $this->assertStringContainsString($first->logLabel(), $line->description);
        $this->assertStringContainsString($second->logLabel(), $line->description);

        $audit = AuditLog::where('event', 'coupon.sponsor_tagged')->latest('id')->firstOrFail();

        $this->assertSame($first->logLabel(), $audit->old_values['sponsor']);
        $this->assertSame($second->logLabel(), $audit->new_values['sponsor']);

        /* ---- cleared ---- */
        $this->actingAs($this->couponAdmin())
            ->put(route('admin.coupons.update', $coupon), $this->form([
                'name' => 'TRAIL001',
                'quantity' => 10,
                'sponsor_user_id' => '',
            ]))
            ->assertSessionHasNoErrors();

        $line = ActivityLog::where('action', 'coupons.sponsor')->latest('id')->firstOrFail();

        $this->assertStringContainsString($second->logLabel(), $line->description);
        $this->assertStringContainsString('not sponsored now', $line->description);

        $audit = AuditLog::where('event', 'coupon.sponsor_tagged')->latest('id')->firstOrFail();

        $this->assertSame($second->logLabel(), $audit->old_values['sponsor']);
        $this->assertNull($audit->new_values['sponsor']);

        // Three moves, three lines. A save that did not touch the sponsorship writes
        // none, so the trail is the moves and not the saves.
        $this->assertSame(3, ActivityLog::where('action', 'coupons.sponsor')->count());
    }

    public function test_a_save_that_leaves_the_sponsorship_alone_writes_no_sponsorship_line(): void
    {
        $sponsor = $this->sponsor('Syarikat Maju Sdn Bhd', 500);
        $coupon = $this->coupon(['name' => 'QUIET001', 'quantity' => 10, 'sponsor_user_id' => $sponsor->id]);

        $this->actingAs($this->couponAdmin())
            ->put(route('admin.coupons.update', $coupon), $this->form([
                'name' => 'QUIET001',
                'quantity' => 25,
                'sponsor_user_id' => $sponsor->id,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(25, (int) $coupon->fresh()->quantity);
        $this->assertSame(0, ActivityLog::where('action', 'coupons.sponsor')->count());
    }

    public function test_creating_a_sponsored_batch_is_logged_at_the_moment_it_is_created(): void
    {
        $sponsor = $this->sponsor('Syarikat Maju Sdn Bhd', 500);

        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $this->form([
                'name' => 'ATBIRTH1',
                'quantity' => 10,
                'sponsor_user_id' => $sponsor->id,
            ]))
            ->assertSessionHasNoErrors();

        $line = ActivityLog::where('action', 'coupons.sponsor')->latest('id')->firstOrFail();

        $this->assertStringContainsString('ATBIRTH1', $line->description);
        $this->assertStringContainsString($sponsor->logLabel(), $line->description);
    }

    /* ---------------------------------------------------------------------
     | One label, saying what the figure actually is
     * ------------------------------------------------------------------ */

    public function test_the_quantity_label_says_codes_in_unique_mode_and_uses_in_shared_mode(): void
    {
        $admin = $this->couponAdmin();

        // Shared is the default on create, and there the figure genuinely is uses.
        $create = $this->actingAs($admin)->get(route('admin.coupons.create'));
        $create->assertOk();
        $this->assertSame('How Many Uses', $this->quantityLabel($create->getContent()));

        $shared = $this->coupon(['name' => 'SHAREDQ1', 'quantity' => 50]);
        $sharedForm = $this->actingAs($admin)->get(route('admin.coupons.edit', $shared));
        $sharedForm->assertOk();
        $this->assertSame('How Many Uses', $this->quantityLabel($sharedForm->getContent()));

        /*
         | Unique mode. What the operator types here is how many CODES to generate.
         | The two numbers are equal, so "uses" was not wrong — but this feature has
         | already sent its owner down a wrong path once with a batch name that read
         | like a code, and a label saying "uses" beside a button that mints codes is
         | the same mistake.
         */
        $unique = $this->uniqueCoupon(['name' => 'UNIQUEQ1']);
        $uniqueForm = $this->actingAs($admin)->get(route('admin.coupons.edit', $unique));
        $uniqueForm->assertOk();
        $this->assertSame('How Many Codes', $this->quantityLabel($uniqueForm->getContent()));

        // And the helper beside it changes with it.
        $uniqueForm->assertSee('How many individual codes to generate now.');
    }
}
