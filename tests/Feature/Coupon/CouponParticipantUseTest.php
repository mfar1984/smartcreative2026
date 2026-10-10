<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\CouponCode;
use App\Models\CouponIssuedCode;
use App\Models\Event;
use App\Services\Coupon\CouponOutcome;
use App\Services\Coupon\CouponRedeemer;
use App\Services\Coupon\RegistrationCouponWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * A USE IS A PARTICIPANT, and the money and the cap count the same way.
 *
 * The worked example the whole feature rests on: a sponsor commits RM2,000 and the
 * coupon is 50% of a RM15 entry, which is RM7.50 a head. A group of ten enters ONE
 * code once, all ten are discounted, and the batch is charged TEN uses. A thousand
 * codes therefore fund a hundred such groups — RM7,500, the committed figure, with no
 * blow-out.
 *
 * Counted the old way, one registration was one use however many people it covered, so
 * a thousand codes would have funded a thousand GROUPS: RM75,000 against a RM2,000
 * pledge. That is the arithmetic these tests exist to pin down.
 *
 * The other half is the refusal. Ten participants against five remaining uses is
 * refused outright, never part-applied, because the registration stores ONE discount
 * figure with no record of WHICH five it covered — so a later Recheck Totals would
 * have nothing to recompute against.
 */
class CouponParticipantUseTest extends CouponTestCase
{
    use RefreshDatabase;

    private function writer(): RegistrationCouponWriter
    {
        return app(RegistrationCouponWriter::class);
    }

    private function redeemer(): CouponRedeemer
    {
        return app(CouponRedeemer::class);
    }

    /** A grouping event charging per head, at $fee a head through the add-ons total. */
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

    /* ---------------------------------------------------------------------
     | THE WORKED EXAMPLE
     * ------------------------------------------------------------------ */

    public function test_a_group_of_ten_charges_ten_uses_and_writes_ten_ledger_rows(): void
    {
        $event = $this->perHeadEvent();

        // RM15 a head for ten heads: RM150 before the discount.
        $registration = $this->registration($event, ['addons_total' => 150], people: 10);

        $coupon = $this->percentageCoupon(50, ['quantity' => 1000]);

        $outcome = $this->writer()->apply($registration, $coupon);

        $this->assertTrue($outcome->succeeded());

        // Half of RM150. Displayed once, as one discount on one registration.
        $this->assertSame(75.0, $outcome->discount);
        $this->assertSame(10, $outcome->uses);

        $registration->refresh();
        $this->assertSame('75.00', $registration->discount_amount);
        $this->assertSame('75.00', $registration->amount);

        /* ---- ten uses charged, not one ---- */

        $this->assertSame(10, $coupon->fresh()->redeemedCount());
        $this->assertSame(990, $coupon->fresh()->remaining());

        /* ---- ten ledger rows, each naming the person it covered ---- */

        $rows = CouponCode::query()->where('coupon_id', $coupon->id)->orderBy('id')->get();

        $this->assertCount(10, $rows);

        $names = [];

        foreach ($rows as $row) {
            // All ten point at the same registration and carry the same typed code.
            $this->assertSame($registration->id, $row->event_registration_id);
            $this->assertSame($coupon->name, $row->code);
            $this->assertNotNull($row->event_participant_id);
            $this->assertNotNull($row->participant_name);

            $names[] = $row->participant_name;
        }

        // Ten different people, which is what makes "show me the ten under this
        // coupon" fall out of the table for free.
        $this->assertCount(10, array_unique($names));
        $this->assertSame(
            $registration->participants->pluck('full_name')->sort()->values()->all(),
            collect($names)->sort()->values()->all(),
        );

        /* ---- and the shares add back up to the figure on the registration ---- */

        $this->assertSame(75.0, round((float) $rows->sum('discount_amount'), 2));
        $this->assertSame(['7.50'], $rows->pluck('discount_amount')->unique()->values()->all());
    }

    public function test_a_thousand_codes_fund_a_hundred_groups_of_ten(): void
    {
        /*
         | The sponsor's arithmetic, asserted rather than reasoned about. Nothing is
         | actually redeemed a thousand times here — the point is that the cap counts
         | heads, so the allowance divides by the head count and not by the number of
         | registrations.
         */
        $event = $this->perHeadEvent();
        $coupon = $this->percentageCoupon(50, ['quantity' => 1000]);

        for ($group = 0; $group < 3; $group++) {
            $registration = $this->registration($event, ['addons_total' => 150], people: 10);

            $this->assertTrue($this->writer()->apply($registration, $coupon->fresh())->succeeded());
        }

        $coupon->refresh();

        // Three groups of ten is thirty uses, so 970 are left — not 997.
        $this->assertSame(30, $coupon->redeemedCount());
        $this->assertSame(970, $coupon->remaining());

        // RM7.50 a head, thirty heads.
        $this->assertSame(225.0, round((float) CouponCode::query()->where('coupon_id', $coupon->id)->sum('discount_amount'), 2));
    }

    public function test_the_same_group_is_one_use_when_the_event_does_not_charge_per_participant(): void
    {
        $event = $this->event([
            'registration_mode' => Event::MODE_GROUPING,
            'fee' => 150,
            'min_players' => 1,
            'max_players' => 20,
        ]);

        $registration = $this->registration($event, people: 10);
        $coupon = $this->percentageCoupon(50, ['quantity' => 1000]);

        $outcome = $this->writer()->apply($registration, $coupon);

        $this->assertTrue($outcome->succeeded());
        $this->assertSame(1, $outcome->uses);
        $this->assertSame(75.0, $outcome->discount);

        // One row, and it names nobody: the use covered the entry as a whole, so
        // putting one of the ten names on it would be a guess.
        $row = CouponCode::query()->where('coupon_id', $coupon->id)->sole();

        $this->assertNull($row->event_participant_id);
        $this->assertNull($row->participant_name);
        $this->assertSame('75.00', $row->discount_amount);

        $this->assertSame(999, $coupon->fresh()->remaining());
    }

    public function test_an_individual_registration_names_its_one_participant(): void
    {
        $registration = $this->registration($this->event(['fee' => 100]), people: 1);
        $coupon = $this->fixedCoupon(20, ['quantity' => 5]);

        $this->writer()->apply($registration, $coupon);

        $row = CouponCode::query()->where('coupon_id', $coupon->id)->sole();

        // One use for one entrant, so the row is attributable and says who it was.
        $this->assertSame('Member 1', $row->participant_name);
        $this->assertSame($registration->participants->first()->id, $row->event_participant_id);
    }

    public function test_a_shop_order_is_still_one_use(): void
    {
        $coupon = $this->fixedCoupon(10, ['kind' => Coupon::KIND_SHOP, 'quantity' => 5]);

        $outcome = $this->redeemer()->claimByCode($coupon->name, Coupon::KIND_SHOP, 100);

        $this->assertTrue($outcome->succeeded());
        $this->assertSame(1, $outcome->uses);
        $this->assertSame(4, $coupon->fresh()->remaining());
    }

    /* ---------------------------------------------------------------------
     | A partial application is refused
     * ------------------------------------------------------------------ */

    public function test_a_group_of_ten_against_five_remaining_is_refused_and_told_five(): void
    {
        $event = $this->perHeadEvent();
        $registration = $this->registration($event, ['addons_total' => 150], people: 10);

        $coupon = $this->percentageCoupon(50, ['quantity' => 5]);

        $outcome = $this->writer()->apply($registration, $coupon);

        $this->assertFalse($outcome->succeeded());
        $this->assertTrue($outcome->ranOut(), 'The caller has to be able to fall back to the normal price.');
        $this->assertSame(CouponOutcome::RAN_OUT, $outcome->status);

        // The numbers, so the operator's next move is obvious: top the batch up or
        // split the group.
        $this->assertSame(5, $outcome->remaining);
        $this->assertSame(10, $outcome->needed);
        $this->assertStringContainsString('5 uses', $outcome->message());
        $this->assertStringContainsString('needs 10', $outcome->message());

        // NOTHING was written. Not five rows, not a discount, not a status change.
        $this->assertSame(0, CouponCode::query()->where('coupon_id', $coupon->id)->count());
        $this->assertSame(5, $coupon->fresh()->remaining());

        $registration->refresh();
        $this->assertSame('0.00', $registration->discount_amount);
        $this->assertSame('150.00', $registration->amount);
        $this->assertNull($registration->coupon_code_id);
    }

    public function test_a_group_exactly_the_size_of_the_remaining_allowance_is_allowed(): void
    {
        $event = $this->perHeadEvent();
        $registration = $this->registration($event, ['addons_total' => 150], people: 10);

        $coupon = $this->percentageCoupon(50, ['quantity' => 10]);

        $this->assertTrue($this->writer()->apply($registration, $coupon)->succeeded());

        $coupon->refresh();

        $this->assertSame(0, $coupon->remaining());
        $this->assertTrue($coupon->isExhausted());
    }

    public function test_a_fully_spent_batch_still_says_fully_used(): void
    {
        // The original wording, which every existing caller reads. A balance of zero
        // is not a "you need more than you have" problem.
        $coupon = $this->fixedCoupon(10, ['quantity' => 1]);

        $this->redeemer()->claim($coupon, 100);

        $outcome = $this->redeemer()->claim($coupon->fresh(), 100);

        $this->assertSame(CouponOutcome::RAN_OUT, $outcome->status);
        $this->assertSame('That coupon has been fully used, so the normal price applies.', $outcome->message());
    }

    /* ---------------------------------------------------------------------
     | In unique mode the uses come out of ONE block
     * ------------------------------------------------------------------ */

    public function test_a_group_of_ten_spends_ten_codes_from_the_block_it_typed(): void
    {
        $event = $this->perHeadEvent();
        $registration = $this->registration($event, ['addons_total' => 150], people: 10);

        $coupon = $this->uniqueCoupon(['discount_type' => Coupon::DISCOUNT_PERCENTAGE, 'discount_value' => 50]);

        $siti = $this->issue($coupon, 15, ['full_name' => 'Siti Nurhaliza']);
        $ahmad = $this->issue($coupon, 15, ['full_name' => 'Ahmad Bin Ali']);

        $typed = $this->codeFrom($siti);

        $outcome = $this->writer()->applyCode($registration, $typed);

        $this->assertTrue($outcome->succeeded());
        $this->assertSame(10, $outcome->uses);
        $this->assertSame(75.0, $outcome->discount);

        // Ten of Siti's codes are gone, and Ahmad's block is untouched.
        $this->assertSame(10, $siti->fresh()->usedCount());
        $this->assertSame(5, $siti->fresh()->unusedCount());
        $this->assertSame(0, $ahmad->fresh()->usedCount());
        $this->assertSame(15, $ahmad->fresh()->unusedCount());

        // The code that was actually typed is one of the ten spent, and it is the
        // string every ledger row carries.
        $this->assertTrue(CouponIssuedCode::query()->where('code', $typed)->sole()->isUsed());
        $this->assertSame(
            [$typed],
            CouponCode::query()->where('coupon_id', $coupon->id)->pluck('code')->unique()->values()->all(),
        );

        // Each spent code is paired to the row it paid for, so it knows who used it.
        foreach ($siti->fresh()->codes()->used()->with('redemption')->get() as $code) {
            $this->assertNotNull($code->redeemerName());
            $this->assertSame('7.50', $code->redemption->discount_amount);
        }
    }

    public function test_a_group_of_ten_against_a_block_of_five_is_refused_and_names_the_handler(): void
    {
        $event = $this->perHeadEvent();
        $registration = $this->registration($event, ['addons_total' => 150], people: 10);

        $coupon = $this->uniqueCoupon(['discount_type' => Coupon::DISCOUNT_PERCENTAGE, 'discount_value' => 50]);

        $siti = $this->issue($coupon, 5, ['full_name' => 'Siti Nurhaliza']);

        // The batch as a whole has plenty: a hundred codes in somebody else's hands.
        $ahmad = $this->issue($coupon, 100, ['full_name' => 'Ahmad Bin Ali']);

        $this->assertSame(105, $coupon->fresh()->remaining());

        $outcome = $this->writer()->applyCode($registration, $this->codeFrom($siti));

        $this->assertFalse($outcome->succeeded());
        $this->assertTrue($outcome->ranOut());

        // Whose block, and how many are in it. Ahmad's hundred are irrelevant: they
        // are not Siti's to give.
        $this->assertSame(5, $outcome->remaining);
        $this->assertSame(10, $outcome->needed);
        $this->assertSame('Siti Nurhaliza', $outcome->holder);
        $this->assertStringContainsString('Siti Nurhaliza', $outcome->message());
        $this->assertStringContainsString('5 uses', $outcome->message());

        // Nothing moved, in either block.
        $this->assertSame(0, CouponCode::query()->where('coupon_id', $coupon->id)->count());
        $this->assertSame(5, $siti->fresh()->unusedCount());
        $this->assertSame(100, $ahmad->fresh()->unusedCount());
        $this->assertSame('150.00', $registration->fresh()->amount);
    }

    public function test_an_unassigned_block_is_named_clearly_when_it_runs_short(): void
    {
        $event = $this->perHeadEvent();
        $registration = $this->registration($event, ['addons_total' => 150], people: 10);

        $coupon = $this->uniqueCoupon(['discount_type' => Coupon::DISCOUNT_PERCENTAGE, 'discount_value' => 50]);
        $block = $this->issue($coupon, 3);

        $outcome = $this->writer()->applyCode($registration, $this->codeFrom($block));

        $this->assertTrue($outcome->ranOut());
        $this->assertSame('Not assigned', $outcome->holder);
        $this->assertSame(3, $outcome->remaining);
    }

    /* ---------------------------------------------------------------------
     | The race
     * ------------------------------------------------------------------ */

    public function test_two_groups_racing_for_the_last_uses_produce_exactly_one_winner(): void
    {
        $event = $this->perHeadEvent();

        // Ten uses between them, and each group wants all ten.
        $coupon = $this->percentageCoupon(50, ['quantity' => 10]);

        $first = $this->registration($event, ['addons_total' => 150], people: 10);
        $second = $this->registration($event, ['addons_total' => 150], people: 10);

        $a = $this->writer()->apply($first, $coupon->fresh());
        $b = $this->writer()->apply($second, $coupon->fresh());

        $won = array_filter([$a, $b], fn (CouponOutcome $o) => $o->succeeded());

        $this->assertCount(1, $won, 'Exactly one group may take the last ten uses.');

        $loser = $a->succeeded() ? $b : $a;

        $this->assertTrue($loser->ranOut());
        $this->assertSame(0, $loser->remaining);

        // And the ledger gained exactly ten rows: not twenty, not fifteen.
        $this->assertSame(10, CouponCode::query()->where('coupon_id', $coupon->id)->count());
        $this->assertSame(0, $coupon->fresh()->remaining());

        // The loser is charged the normal price and holds no discount.
        $loserRegistration = $a->succeeded() ? $second->fresh() : $first->fresh();

        $this->assertSame('0.00', $loserRegistration->discount_amount);
        $this->assertSame('150.00', $loserRegistration->amount);
    }

    public function test_two_groups_racing_for_one_blocks_last_codes_produce_one_winner(): void
    {
        $event = $this->perHeadEvent();

        $coupon = $this->uniqueCoupon(['discount_type' => Coupon::DISCOUNT_PERCENTAGE, 'discount_value' => 50]);
        $block = $this->issue($coupon, 10, ['full_name' => 'Siti Nurhaliza']);

        $codes = $block->codes()->orderBy('id')->pluck('code');

        $first = $this->registration($event, ['addons_total' => 150], people: 10);
        $second = $this->registration($event, ['addons_total' => 150], people: 10);

        // Two different slips of paper out of the same block of ten, so the whole
        // block is contended: ten heads need all ten codes.
        $a = $this->writer()->applyCode($first, $codes->first());
        $b = $this->writer()->applyCode($second, $codes->last());

        $this->assertCount(1, array_filter([$a, $b], fn (CouponOutcome $o) => $o->succeeded()));

        $loser = $a->succeeded() ? $b : $a;

        /*
         | The loser's own slip was one of the ten the winner spent, so it gets the
         | more specific answer: that code has been used. Either refusal is correct
         | and neither gives a discount — what matters is that the status is one of
         | the two the public form already knows how to fall back from.
         */
        $this->assertFalse($loser->succeeded());
        $this->assertSame(CouponOutcome::ALREADY_USED, $loser->status);
        $this->assertSame(0.0, $loser->discount);

        // And the ledger gained exactly ten rows: not twenty.
        $this->assertSame(10, CouponCode::query()->where('coupon_id', $coupon->id)->count());
        $this->assertSame(10, $block->fresh()->usedCount());
        $this->assertSame(0, $block->fresh()->unusedCount());

        $loserRegistration = $a->succeeded() ? $second->fresh() : $first->fresh();
        $this->assertSame('150.00', $loserRegistration->amount);
    }

    public function test_the_second_group_is_told_the_blocks_balance_when_its_own_code_survived(): void
    {
        $event = $this->perHeadEvent();

        $coupon = $this->uniqueCoupon(['discount_type' => Coupon::DISCOUNT_PERCENTAGE, 'discount_value' => 50]);

        // Fifteen codes, two groups of ten. The first takes ten and leaves five, so
        // the second one's own slip is still good but its block cannot cover it.
        $block = $this->issue($coupon, 15, ['full_name' => 'Siti Nurhaliza']);
        $codes = $block->codes()->orderBy('id')->pluck('code');

        $first = $this->registration($event, ['addons_total' => 150], people: 10);
        $second = $this->registration($event, ['addons_total' => 150], people: 10);

        $a = $this->writer()->applyCode($first, $codes->first());
        $b = $this->writer()->applyCode($second, $codes->last());

        $this->assertTrue($a->succeeded());
        $this->assertSame(10, $a->uses);

        // Counted under the same lock that wrote the first claim, so the second one
        // reads the real balance rather than the one it started with.
        $this->assertTrue($b->ranOut());
        $this->assertSame(5, $b->remaining);
        $this->assertSame(10, $b->needed);
        $this->assertSame('Siti Nurhaliza', $b->holder);
        $this->assertStringContainsString('Siti Nurhaliza', $b->message());
        $this->assertStringContainsString('5 uses', $b->message());

        $this->assertSame(10, CouponCode::query()->where('coupon_id', $coupon->id)->count());
        $this->assertSame(5, $block->fresh()->unusedCount());

        // The last code was never spent, which is the point: a refusal writes nothing.
        $this->assertFalse(CouponIssuedCode::query()->where('code', $codes->last())->sole()->isUsed());
    }

    /* ---------------------------------------------------------------------
     | The money arithmetic of the split
     * ------------------------------------------------------------------ */

    public function test_a_discount_that_does_not_divide_evenly_still_adds_back_up(): void
    {
        $event = $this->perHeadEvent();

        // Three heads and RM10 off: 3.33 three times would be RM9.99, which is a cent
        // of somebody's money gone.
        $registration = $this->registration($event, ['addons_total' => 120], people: 3);

        $coupon = $this->coupon([
            'quantity' => 100,
            'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
            'discount_value' => 25,
        ]);

        // 25% of RM120 is RM30, which divides. Force the awkward case with a fixed
        // amount capped at the charge instead.
        $awkward = $this->fixedCoupon(500, ['quantity' => 100]);

        $outcome = $this->redeemer()->claim(
            coupon: $awkward,
            charge: 10.00,
            times: 3,
            registration: $registration,
        );

        $this->assertTrue($outcome->succeeded());
        $this->assertSame(10.0, $outcome->discount, 'Capped at the charge.');

        $rows = CouponCode::query()->where('coupon_id', $awkward->id)->orderBy('id')->get();

        $this->assertCount(3, $rows);
        $this->assertSame(10.0, round((float) $rows->sum('discount_amount'), 2));
        $this->assertSame(['3.34', '3.33', '3.33'], $rows->pluck('discount_amount')->all());

        // Unused, so it stays out of the assertions above but proves the fixture is
        // doing what it says.
        $this->assertSame(0, $coupon->redeemedCount());
    }

    public function test_tracking_names_each_participant_so_a_group_does_not_read_as_duplicates(): void
    {
        $event = $this->perHeadEvent();
        $registration = $this->registration($event, ['addons_total' => 45], people: 3);

        $this->writer()->apply($registration, $this->percentageCoupon(50, ['quantity' => 50, 'name' => 'GROUPED1']));

        $response = $this->actingAs($this->userWith(['coupons.view']))
            ->get(route('admin.coupons.tracking'));

        $response->assertOk();

        // Three rows sharing a code, a reference and a timestamp. The name column is
        // what stops them reading as three duplicates of one redemption.
        $this->assertSame(3, $response->viewData('redemptions')->total());

        $response->assertSee('Used by');
        $response->assertSee('Member 1');
        $response->assertSee('Member 2');
        $response->assertSee('Member 3');

        // And the total given away is still the one figure on the registration.
        $this->assertSame(22.5, round($response->viewData('discountTotal'), 2));
        $this->assertSame('22.50', $registration->fresh()->discount_amount);
    }

    public function test_the_trail_records_how_many_participants_one_claim_covered(): void
    {
        $event = $this->perHeadEvent();
        $registration = $this->registration($event, ['addons_total' => 150], people: 10);

        $this->writer()->apply($registration, $this->percentageCoupon(50, ['quantity' => 50]));

        $this->assertDatabaseHas('activity_logs', ['action' => 'coupons.redeem']);

        $line = \App\Models\ActivityLog::query()->where('action', 'coupons.redeem')->latest('id')->first();

        $this->assertStringContainsString('10 participants', $line->description);
    }
}
