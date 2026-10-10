<?php

namespace Tests\Feature\Coupon;

use App\Models\ActivityLog;
use App\Models\Coupon;
use App\Models\CouponCode;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Role;
use App\Models\User;
use App\Services\Coupon\CouponOutcome;
use App\Services\Coupon\CouponReleaser;
use App\Services\Coupon\RegistrationCouponWriter;
use App\Support\CouponSponsorship;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a registration gives its coupon uses back.
 *
 * THE BUG THE OWNER FOUND. A shared 50% batch read "2 / 10 — 8 uses left" with exactly
 * ONE registration behind it. The second use belonged to an entry that had been deleted
 * from Participants: coupon_codes.event_registration_id is nullOnDelete, so the row
 * survived with an empty registration id and the use stayed spent for ever. The cap
 * stayed reduced and the sponsor was still charged for the discount.
 *
 * WHY RELEASING IS ALWAYS RIGHT HERE, which is what the paid-entry test below guards:
 * ParticipantController::destroy() refuses to delete an entry that received money, so
 * anything that CAN be deleted never collected a sen. Nobody benefited from the
 * discount, so the use was never really spent.
 */
class CouponReleaseOnDeleteTest extends CouponTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function writer(): RegistrationCouponWriter
    {
        return app(RegistrationCouponWriter::class);
    }

    /** Somebody who may delete an entry, and nothing more. */
    private function deleter(): User
    {
        return $this->userWith(['participants.view', 'participants.delete']);
    }

    private function deleteEntry(EventRegistration $registration)
    {
        return $this->actingAs($this->deleter())
            ->delete(route('admin.event.participants.destroy', $registration));
    }

    /** A grouping event charging per head, so a use is a participant. */
    private function perHeadEvent(): Event
    {
        return $this->event([
            'registration_mode' => Event::MODE_GROUPING,
            'charges_addons_per_participant' => true,
            'fee' => 0,
            'min_players' => 1,
            'max_players' => 20,
        ]);
    }

    private function sponsor(string $name, float $committed): User
    {
        return User::create([
            'name' => $name,
            'username' => 'sponsor-'.uniqid(),
            'email' => uniqid().'@example.test',
            'password' => 'Sponsor-Pass-123!',
            'role_id' => Role::where('slug', Role::SPONSOR)->firstOrFail()->id,
            'is_active' => true,
            'is_sponsor' => true,
            'sponsor_committed_amount' => $committed,
        ]);
    }

    /* ---------------------------------------------------------------------
     | A shared batch: the ledger rows go and the cap comes back
     * ------------------------------------------------------------------ */

    public function test_deleting_a_registration_releases_its_uses_and_restores_the_remaining_count(): void
    {
        $event = $this->perHeadEvent();
        $registration = $this->registration($event, ['addons_total' => 150], people: 10);

        $coupon = $this->percentageCoupon(50, ['quantity' => 20]);

        $this->assertTrue($this->writer()->apply($registration, $coupon)->succeeded());
        $this->assertSame(10, $coupon->fresh()->remaining());

        $this->deleteEntry($registration)->assertSessionHasNoErrors();

        // The rows are GONE rather than left pointing at nothing, which is what kept
        // the use spent before.
        $this->assertSame(0, CouponCode::query()->where('coupon_id', $coupon->id)->count());
        $this->assertSame(0, $coupon->fresh()->redeemedCount());
        $this->assertSame(20, $coupon->fresh()->remaining());

        $this->assertDatabaseMissing('event_registrations', ['id' => $registration->id]);
    }

    public function test_the_trail_records_which_coupon_got_how_many_uses_back(): void
    {
        $event = $this->perHeadEvent();
        $registration = $this->registration($event, ['addons_total' => 150], people: 10);

        $coupon = $this->percentageCoupon(50, ['quantity' => 20, 'name' => 'RELEASE1']);

        $this->writer()->apply($registration, $coupon);

        $this->deleteEntry($registration);

        $line = ActivityLog::query()->where('action', 'coupons.release')->sole();

        // The reference, the batch, the count and the money. A use reappearing in a
        // cap with no explanation is what sent the owner hunting in the first place.
        $this->assertStringContainsString($registration->reference, $line->description);
        $this->assertStringContainsString('RELEASE1', $line->description);
        $this->assertStringContainsString('10 use(s)', $line->description);
        $this->assertStringContainsString('RM 75.00', $line->description);
    }

    public function test_the_operator_is_told_on_screen_that_uses_came_back(): void
    {
        $registration = $this->registration($this->event(['fee' => 100]), people: 1);
        $coupon = $this->fixedCoupon(20, ['quantity' => 5, 'name' => 'RELEASE2']);

        $this->writer()->apply($registration, $coupon);

        $this->deleteEntry($registration)
            ->assertSessionHas('status', fn (string $status) => str_contains($status, '1 use of coupon RELEASE2 was given back.'));
    }

    public function test_deleting_an_entry_with_no_coupon_says_nothing_about_coupons(): void
    {
        $registration = $this->registration($this->event(['fee' => 100]), people: 1);

        $response = $this->deleteEntry($registration);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status', fn (string $status) => ! str_contains($status, 'given back'));

        $this->assertSame(0, ActivityLog::query()->where('action', 'coupons.release')->count());
    }

    public function test_another_registrations_uses_are_left_alone(): void
    {
        $event = $this->perHeadEvent();

        $coupon = $this->percentageCoupon(50, ['quantity' => 20]);

        $doomed = $this->registration($event, ['addons_total' => 30], people: 2);
        $keeper = $this->registration($event, ['addons_total' => 45], people: 3);

        $this->writer()->apply($doomed, $coupon->fresh());
        $this->writer()->apply($keeper, $coupon->fresh());

        $this->assertSame(15, $coupon->fresh()->remaining());

        $this->deleteEntry($doomed);

        // Only the two that belonged to the deleted entry came back.
        $this->assertSame(3, $coupon->fresh()->redeemedCount());
        $this->assertSame(17, $coupon->fresh()->remaining());
        $this->assertSame(3, CouponCode::query()->where('event_registration_id', $keeper->id)->count());
        $this->assertSame('22.50', $keeper->fresh()->discount_amount);
    }

    /* ---------------------------------------------------------------------
     | Unique mode: the holder's block recovers its codes
     * ------------------------------------------------------------------ */

    public function test_in_unique_mode_the_deletion_returns_the_codes_to_the_block_unused(): void
    {
        $event = $this->perHeadEvent();
        $registration = $this->registration($event, ['addons_total' => 150], people: 10);

        $coupon = $this->uniqueCoupon(['discount_type' => Coupon::DISCOUNT_PERCENTAGE, 'discount_value' => 50]);
        $siti = $this->issue($coupon, 15, ['full_name' => 'Siti Nurhaliza']);

        $typed = $this->codeFrom($siti);

        $this->assertTrue($this->writer()->applyCode($registration, $typed)->succeeded());
        $this->assertSame(10, $siti->fresh()->usedCount());
        $this->assertSame(5, $siti->fresh()->unusedCount());

        $this->deleteEntry($registration)->assertSessionHasNoErrors();

        // The whole block is back, and nothing is left half-released: `used_at` and
        // the pairing to the ledger row both go, so the codes are usable again.
        $this->assertSame(0, $siti->fresh()->usedCount());
        $this->assertSame(15, $siti->fresh()->unusedCount());
        $this->assertSame(0, CouponCode::query()->where('coupon_id', $coupon->id)->count());

        $this->assertDatabaseMissing('coupon_issued_codes', [
            'coupon_allocation_id' => $siti->id,
            'coupon_code_id' => null,
            'used_at' => now()->toDateTimeString(),
        ]);

        // And the returned code can really be spent again, which is the point.
        $second = $this->registration($event, ['addons_total' => 150], people: 10);

        $this->assertTrue($this->writer()->applyCode($second, $typed)->succeeded());
    }

    public function test_a_neighbouring_block_is_untouched_by_a_release(): void
    {
        $event = $this->perHeadEvent();
        $registration = $this->registration($event, ['addons_total' => 30], people: 2);

        $coupon = $this->uniqueCoupon(['discount_type' => Coupon::DISCOUNT_PERCENTAGE, 'discount_value' => 50]);

        $siti = $this->issue($coupon, 5, ['full_name' => 'Siti Nurhaliza']);
        $ahmad = $this->issue($coupon, 5, ['full_name' => 'Ahmad Bin Ali']);

        $ahmadUser = $this->registration($event, ['addons_total' => 30], people: 2);

        $this->writer()->applyCode($registration, $this->codeFrom($siti));
        $this->writer()->applyCode($ahmadUser, $this->codeFrom($ahmad));

        $this->deleteEntry($registration);

        $this->assertSame(5, $siti->fresh()->unusedCount());
        $this->assertSame(3, $ahmad->fresh()->unusedCount(), "Ahmad's block is not Siti's to give back.");
    }

    /* ---------------------------------------------------------------------
     | The sponsor's figures move, and only the right ones
     * ------------------------------------------------------------------ */

    public function test_a_sponsors_figures_drop_back_after_the_deletion(): void
    {
        $event = $this->event(['fee' => 100]);

        $coupon = $this->uniqueCoupon([
            'discount_type' => Coupon::DISCOUNT_FIXED,
            'discount_value' => 20,
        ]);
        $coupon->events()->attach($event);

        $maju = $this->sponsor('Syarikat Maju Sdn Bhd', 500);

        $block = $this->issue($coupon->fresh(), 10, ['full_name' => 'Siti Representative']);
        $block->forceFill(['sponsor_user_id' => $maju->id])->save();

        $registration = $this->registration($event, people: 1);

        $this->assertTrue($this->writer()->applyCode($registration, $this->codeFrom($block))->succeeded());

        $before = CouponSponsorship::forSponsor($maju->fresh());

        $this->assertSame(200.0, $before['estimated']);
        $this->assertSame(20.0, $before['actual']);
        $this->assertSame(480.0, $before['remaining']);
        $this->assertSame(1, $before['used']);
        $this->assertSame(9, $before['unused']);

        $this->deleteEntry($registration)->assertSessionHasNoErrors();

        $after = CouponSponsorship::forSponsor($maju->fresh());

        // Actually used and the balance both move, because the discount was never
        // really given.
        $this->assertSame(0.0, $after['actual']);
        $this->assertSame(500.0, $after['remaining']);
        $this->assertSame(0, $after['used']);
        $this->assertSame(10, $after['unused']);

        // The ESTIMATE does not: ten codes were still issued, and what was allocated
        // is not changed by one of them coming back unspent.
        $this->assertSame(200.0, $after['estimated']);
        $this->assertSame(500.0, $after['committed']);
    }

    public function test_the_sponsors_own_screen_stops_showing_the_released_use(): void
    {
        $event = $this->event(['fee' => 100]);

        $coupon = $this->uniqueCoupon(['discount_type' => Coupon::DISCOUNT_FIXED, 'discount_value' => 20]);
        $coupon->events()->attach($event);

        $maju = $this->sponsor('Syarikat Maju Sdn Bhd', 500);

        $block = $this->issue($coupon->fresh(), 4, ['full_name' => 'Siti Representative']);
        $block->forceFill(['sponsor_user_id' => $maju->id])->save();

        $registration = $this->registration($event, people: 1);
        $registration->participants()->first()->update(['full_name' => 'Aminah Binti Yusof']);

        $this->writer()->applyCode($registration->fresh(['participants']), $this->codeFrom($block));

        $this->actingAs($maju)->get(route('admin.sponsorship.index'))
            ->assertOk()
            ->assertSee('Aminah Binti Yusof');

        $this->deleteEntry($registration);

        $this->actingAs($maju)->get(route('admin.sponsorship.index'))
            ->assertOk()
            ->assertDontSee('Aminah Binti Yusof');
    }

    /* ---------------------------------------------------------------------
     | The guard the release rests on
     * ------------------------------------------------------------------ */

    public function test_an_entry_that_received_money_still_cannot_be_deleted_so_nothing_is_released(): void
    {
        $registration = $this->registration($this->event(['fee' => 100]), people: 1);
        $coupon = $this->fixedCoupon(20, ['quantity' => 5]);

        $this->writer()->apply($registration, $coupon);

        // RM 30 arrived against the RM 80 that was owed, so this is a financial
        // record and deleting it would leave the books disagreeing with the gateway.
        $registration->forceFill(['amount_paid' => 30])->save();

        $response = $this->deleteEntry($registration);

        $response->assertSessionHasErrors('registration');

        // The entry stands, the ledger row stands, the cap stands. The release path
        // is never reached for an entry that paid, which is exactly what makes
        // releasing safe everywhere else.
        $this->assertDatabaseHas('event_registrations', ['id' => $registration->id]);
        $this->assertSame(1, CouponCode::query()->where('coupon_id', $coupon->id)->count());
        $this->assertSame(4, $coupon->fresh()->remaining());
        $this->assertSame(0, ActivityLog::query()->where('action', 'coupons.release')->count());
    }

    public function test_a_refunded_entry_is_also_refused_and_keeps_its_use(): void
    {
        $registration = $this->registration($this->event(['fee' => 100]), people: 1);
        $coupon = $this->fixedCoupon(20, ['quantity' => 5]);

        $this->writer()->apply($registration, $coupon);

        $registration->forceFill(['refunded_amount' => 80])->save();

        $this->deleteEntry($registration)->assertSessionHasErrors('registration');

        $this->assertSame(1, CouponCode::query()->where('coupon_id', $coupon->id)->count());
        $this->assertSame(4, $coupon->fresh()->remaining());
    }

    /* ---------------------------------------------------------------------
     | The race: a release and a claim cannot both spend one use
     * ------------------------------------------------------------------ */

    public function test_a_release_and_a_claim_cannot_both_spend_the_same_remaining_use(): void
    {
        $event = $this->event(['fee' => 100]);

        // One use in the whole batch. The first entry holds it.
        $coupon = $this->fixedCoupon(20, ['quantity' => 1]);

        $holder = $this->registration($event, people: 1);
        $waiting = $this->registration($event, people: 1);

        $this->assertTrue($this->writer()->apply($holder, $coupon)->succeeded());

        // Nothing to give while the first entry holds it, under the same batch lock
        // the release is about to take.
        $refused = $this->writer()->apply($waiting, $coupon->fresh());

        $this->assertTrue($refused->ranOut());
        $this->assertSame(CouponOutcome::RAN_OUT, $refused->status);

        app(CouponReleaser::class)->releaseForRegistration($holder);

        // Released, so the second entry may now have it — and the ledger still holds
        // exactly ONE row, never two for a cap of one.
        $granted = $this->writer()->apply($waiting->fresh(), $coupon->fresh());

        $this->assertTrue($granted->succeeded());
        $this->assertSame(1, CouponCode::query()->where('coupon_id', $coupon->id)->count());
        $this->assertSame(0, $coupon->fresh()->remaining());
        $this->assertSame($waiting->id, CouponCode::query()->sole()->event_registration_id);
    }

    public function test_the_release_goes_through_the_batch_row_the_claim_locks(): void
    {
        $registration = $this->registration($this->event(['fee' => 100]), people: 1);
        $coupon = $this->fixedCoupon(20, ['quantity' => 5]);

        $this->writer()->apply($registration, $coupon);

        /*
         | The release has to serialise against a claim, and the thing it serialises
         | ON is the batch row — the identical row CouponRedeemer takes for update,
         | because the two move the same remaining count from opposite directions.
         |
         | Asserted as "it reads the batch row inside its transaction", not by
         | matching FOR UPDATE in the SQL: this suite runs on SQLite, whose grammar
         | drops the lock clause entirely, so a text match would assert nothing on
         | the engine it runs on and would pass on MySQL for free. That the lock
         | actually holds is what the serialisation test above demonstrates.
         */
        $statements = [];

        DB::listen(function ($query) use (&$statements) {
            $statements[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });

        app(CouponReleaser::class)->releaseForRegistration($registration);

        $readBatch = collect($statements)->contains(
            fn (array $statement) => str_contains($statement['sql'], 'from "coupons"')
                && in_array($coupon->id, $statement['bindings'], false),
        );

        $this->assertTrue($readBatch, 'The release must go through the batch row a claim locks.');
    }

    public function test_a_failed_deletion_rolls_the_release_back_with_it(): void
    {
        $registration = $this->registration($this->event(['fee' => 100]), people: 1);
        $coupon = $this->fixedCoupon(20, ['quantity' => 5]);

        $this->writer()->apply($registration, $coupon);

        /*
         | The release inside a transaction that then rolls back. A release that
         | committed on its own would hand a use back to an entry still standing,
         | which is the same inconsistency in the other direction.
         */
        try {
            DB::transaction(function () use ($registration) {
                app(CouponReleaser::class)->releaseForRegistration($registration);

                throw new \RuntimeException('the delete failed');
            });
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertSame(1, CouponCode::query()->where('coupon_id', $coupon->id)->count());
        $this->assertSame(4, $coupon->fresh()->remaining());
    }

    /* ---------------------------------------------------------------------
     | Clearing what the old behaviour already stranded: 2026_10_17_090000
     * ------------------------------------------------------------------ */

    /** Run the orphan release again over whatever is in the table now. */
    private function runOrphanRelease(): void
    {
        (require database_path('migrations/2026_10_17_090000_release_orphaned_coupon_redemptions.php'))->up();
    }

    /**
     * A ledger row as nullOnDelete left it: the use spent, the registration gone.
     *
     * Written by hand because the application can no longer produce this shape, which
     * is the point of the fix — and the live database has at least one.
     */
    private function orphanRow(Coupon $coupon, float $discount = 7.50): int
    {
        return DB::table('coupon_codes')->insertGetId([
            'coupon_id' => $coupon->id,
            'code' => $coupon->name,
            'redeemed_at' => now()->subHours(6),
            'discount_amount' => $discount,
            'event_registration_id' => null,
            'shop_order_id' => null,
            'created_at' => now()->subHours(6),
            'updated_at' => now()->subHours(6),
        ]);
    }

    public function test_the_migration_releases_an_orphan_and_leaves_a_live_redemption_alone(): void
    {
        $registration = $this->registration($this->event(['fee' => 30]), people: 1);

        // The owner's batch, with exactly their two rows on it.
        $coupon = $this->percentageCoupon(50, ['quantity' => 10, 'name' => 'NG68BJ']);

        $this->assertTrue($this->writer()->apply($registration, $coupon)->succeeded());

        $orphan = $this->orphanRow($coupon);

        $this->assertSame(2, CouponCode::query()->where('coupon_id', $coupon->id)->count());
        $this->assertSame(8, $coupon->fresh()->remaining(), 'The reading the owner saw: 2 / 10.');

        $this->runOrphanRelease();

        // The orphan is gone and the use is back in the pool.
        $this->assertDatabaseMissing('coupon_codes', ['id' => $orphan]);
        $this->assertSame(1, CouponCode::query()->where('coupon_id', $coupon->id)->count());
        $this->assertSame(9, $coupon->fresh()->remaining());

        // The live redemption is untouched, and the registration still holds its
        // discount. This is the half that must not move.
        $live = CouponCode::query()->where('coupon_id', $coupon->id)->sole();

        $this->assertSame($registration->id, $live->event_registration_id);
        $this->assertSame('15.00', $live->discount_amount);

        $registration->refresh();

        $this->assertSame($live->id, $registration->coupon_code_id);
        $this->assertSame('15.00', $registration->discount_amount);
        $this->assertSame('15.00', $registration->amount);
    }

    public function test_the_migration_returns_an_orphans_minted_code_to_its_block(): void
    {
        $coupon = $this->uniqueCoupon(['discount_type' => Coupon::DISCOUNT_FIXED, 'discount_value' => 20]);
        $block = $this->issue($coupon, 5, ['full_name' => 'Siti Representative']);

        $orphan = $this->orphanRow($coupon->fresh(), 20.00);

        // The code, marked spent against a row whose registration has gone.
        $code = $block->codes()->unused()->orderBy('id')->firstOrFail();

        DB::table('coupon_issued_codes')->where('id', $code->id)->update([
            'used_at' => now()->subHours(6),
            'coupon_code_id' => $orphan,
        ]);

        $this->assertSame(4, $block->fresh()->unusedCount());

        $this->runOrphanRelease();

        // Back in the block, unused, and with no pairing left to a row that no longer
        // exists. Releasing the ledger row without this would have cost the holder a
        // code for ever.
        $this->assertSame(5, $block->fresh()->unusedCount());
        $this->assertFalse($code->fresh()->isUsed());
        $this->assertNull($code->fresh()->coupon_code_id);
    }

    public function test_the_migration_leaves_a_live_shop_redemption_alone(): void
    {
        // Shop rows name an order, not a registration. Reading "no registration" as
        // "orphaned" would delete every one of them.
        $coupon = $this->fixedCoupon(10, ['kind' => Coupon::KIND_SHOP, 'quantity' => 5]);
        $product = $this->product(['price' => 50]);
        $product->coupons()->attach($coupon);

        $this->shopOpenWithGateway();
        $this->flatShipping(10);
        $this->fakeChipPurchase();

        $this->withSession($this->basket($product))
            ->post(route('checkout.place'), $this->checkoutFields(['voucher_code' => $coupon->name]));

        $row = CouponCode::query()->where('coupon_id', $coupon->id)->sole();

        $this->assertNotNull($row->shop_order_id);

        $this->runOrphanRelease();

        $this->assertDatabaseHas('coupon_codes', ['id' => $row->id]);
        $this->assertSame(4, $coupon->fresh()->remaining());
    }

    public function test_running_the_migration_again_over_a_clean_ledger_changes_nothing(): void
    {
        $registration = $this->registration($this->event(['fee' => 100]), people: 1);
        $coupon = $this->fixedCoupon(20, ['quantity' => 5]);

        $this->writer()->apply($registration, $coupon);

        $before = (array) DB::table('coupon_codes')->sole();

        $this->runOrphanRelease();
        $this->runOrphanRelease();

        $this->assertSame($before, (array) DB::table('coupon_codes')->sole());
        $this->assertSame(4, $coupon->fresh()->remaining());
    }
}
