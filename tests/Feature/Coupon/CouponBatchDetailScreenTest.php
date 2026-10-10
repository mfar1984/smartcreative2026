<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\CouponHolder;
use App\Models\Event;
use App\Services\Coupon\RegistrationCouponWriter;
use App\Support\LocalTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * The batch opened up: the codes, who handles them, and which are spent.
 *
 * This is the NGO's whole requirement in one screen. Ten representatives hold a share
 * of a thousand codes, and the chairman needs to see at a glance whose are finished and
 * whose have not been touched — because an untouched block means the representative
 * did not do the work, and that is a conversation he has to have.
 */
class CouponBatchDetailScreenTest extends CouponTestCase
{
    use RefreshDatabase;

    private function writer(): RegistrationCouponWriter
    {
        return app(RegistrationCouponWriter::class);
    }

    private function showAs(Coupon $coupon, array $query = [])
    {
        return $this->actingAs($this->userWith(['coupons.view']))
            ->get(route('admin.coupons.report.show', ['coupon' => $coupon] + $query));
    }

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
     | Reach
     * ------------------------------------------------------------------ */

    public function test_the_detail_needs_the_coupon_view_permission(): void
    {
        $coupon = $this->uniqueCoupon();

        $this->actingAs($this->userWith(['events.view']))
            ->get(route('admin.coupons.report.show', $coupon))
            ->assertForbidden();
    }

    public function test_the_batch_is_reachable_from_the_report(): void
    {
        $coupon = $this->uniqueCoupon(['name' => 'SPONSORB']);

        $this->actingAs($this->userWith(['coupons.view']))
            ->get(route('admin.coupons.report'))
            ->assertOk()
            ->assertSee(route('admin.coupons.report.show', $coupon), false);
    }

    /* ---------------------------------------------------------------------
     | The codes, their handlers, and who used them
     * ------------------------------------------------------------------ */

    public function test_it_lists_the_codes_their_handlers_and_which_are_used(): void
    {
        $event = $this->perHeadEvent();

        $coupon = $this->uniqueCoupon([
            'name' => 'NGOCODES',
            'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
            'discount_value' => 50,
        ]);

        $event->coupons()->attach($coupon);

        $siti = $this->issue($coupon, 4, ['full_name' => 'Siti Nurhaliza', 'email' => 'siti@ngo.test']);
        $ahmad = $this->issue($coupon, 4, ['full_name' => 'Ahmad Bin Ali']);

        // One of Siti's codes used by a group of two, so two of her four are gone.
        $registration = $this->registration($event, ['addons_total' => 30], people: 2);
        $this->assertTrue($this->writer()->applyCode($registration, $this->codeFrom($siti))->succeeded());

        $response = $this->showAs($coupon);

        $response->assertOk();

        // Both handlers, with the handler's own contact details beside them.
        $response->assertSee('Siti Nurhaliza');
        $response->assertSee('siti@ngo.test');
        $response->assertSee('Ahmad Bin Ali');

        // Every code, used and unused alike.
        foreach ($coupon->issuedCodes as $code) {
            $response->assertSee($code->code);
        }

        $response->assertSee('Used');
        $response->assertSee('Unused');

        // And the people under a used code, by NAME.
        $response->assertSee('Member 1');
        $response->assertSee('Member 2');
        $response->assertSee($registration->reference);

        $this->assertSame(2, $siti->fresh()->usedCount());
        $this->assertSame(0, $ahmad->fresh()->usedCount());
    }

    public function test_grouping_shows_whose_allocation_is_exhausted_and_whose_is_untouched(): void
    {
        $event = $this->event(['fee' => 20]);

        $coupon = $this->uniqueCoupon([
            'discount_type' => Coupon::DISCOUNT_FIXED,
            'discount_value' => 10,
        ]);

        $event->coupons()->attach($coupon);

        $finished = $this->issue($coupon, 2, ['full_name' => 'Rep Finished']);
        $started = $this->issue($coupon, 3, ['full_name' => 'Rep Started']);
        $untouched = $this->issue($coupon, 3, ['full_name' => 'Rep Untouched']);

        // Both of the first block, one of the second, none of the third.
        foreach ($finished->codes as $code) {
            $this->writer()->applyCode($this->registration($event, people: 1), $code->code);
        }

        $this->writer()->applyCode($this->registration($event, people: 1), $this->codeFrom($started));

        $response = $this->showAs($coupon);

        $response->assertOk();

        $blocks = $response->viewData('allocations')->keyBy('id');

        /* ---- the three states, counted in SQL ---- */

        $this->assertSame(2, (int) $blocks[$finished->id]->codes_used);
        $this->assertSame(2, (int) $blocks[$finished->id]->codes_total);
        $this->assertTrue($finished->fresh()->isExhausted());
        $this->assertSame('Finished', $finished->fresh()->stateLabel());

        $this->assertSame(1, (int) $blocks[$started->id]->codes_used);
        $this->assertSame('In use', $started->fresh()->stateLabel());

        $this->assertSame(0, (int) $blocks[$untouched->id]->codes_used);
        $this->assertTrue($untouched->fresh()->isUntouched());
        $this->assertSame('Untouched', $untouched->fresh()->stateLabel());

        // Said in words on the page, which is the glance the chairman needs.
        $response->assertSee('Rep Finished');
        $response->assertSee('Rep Untouched');
        $response->assertSee('Finished');
        $response->assertSee('Untouched');
    }

    public function test_an_unassigned_block_groups_under_a_clear_label(): void
    {
        $coupon = $this->uniqueCoupon();
        $this->issue($coupon, 3);

        $this->showAs($coupon)
            ->assertOk()
            ->assertSee('Not assigned');
    }

    /* ---------------------------------------------------------------------
     | Filters
     * ------------------------------------------------------------------ */

    public function test_it_filters_the_codes_by_handler(): void
    {
        $coupon = $this->uniqueCoupon();

        $siti = $this->issue($coupon, 3, ['full_name' => 'Siti Nurhaliza']);
        $ahmad = $this->issue($coupon, 3, ['full_name' => 'Ahmad Bin Ali']);

        $response = $this->showAs($coupon, ['allocation' => $siti->id]);

        $response->assertOk();

        $shown = $response->viewData('codes')->pluck('code')->all();

        $this->assertSame($siti->codes->pluck('code')->all(), $shown);

        foreach ($ahmad->codes as $code) {
            $this->assertNotContains($code->code, $shown);
        }

        $this->assertSame($siti->id, $response->viewData('allocationId'));
        $this->assertTrue($response->viewData('isFiltered'));
    }

    public function test_a_handler_filter_for_another_batch_is_dropped(): void
    {
        $mine = $this->uniqueCoupon();
        $this->issue($mine, 2, ['full_name' => 'Mine']);

        $theirs = $this->uniqueCoupon();
        $elsewhere = $this->issue($theirs, 2, ['full_name' => 'Theirs']);

        // Resolved against this batch, so a stray id cannot pull another sponsor's
        // codes onto the page.
        $response = $this->showAs($mine, ['allocation' => $elsewhere->id]);

        $response->assertOk();
        $this->assertNull($response->viewData('allocationId'));
        $this->assertCount(2, $response->viewData('codes'));
    }

    public function test_it_filters_the_codes_by_used_and_unused(): void
    {
        $event = $this->event(['fee' => 20]);

        $coupon = $this->uniqueCoupon(['discount_type' => Coupon::DISCOUNT_FIXED, 'discount_value' => 10]);
        $event->coupons()->attach($coupon);

        $block = $this->issue($coupon, 4, ['full_name' => 'Siti Nurhaliza']);
        $used = $this->codeFrom($block);

        $this->writer()->applyCode($this->registration($event, people: 1), $used);

        $usedOnly = $this->showAs($coupon, ['state' => 'used']);

        $this->assertSame([$used], $usedOnly->viewData('codes')->pluck('code')->all());
        $this->assertSame('used', $usedOnly->viewData('state'));

        $unusedOnly = $this->showAs($coupon, ['state' => 'unused']);

        $this->assertCount(3, $unusedOnly->viewData('codes'));
        $this->assertNotContains($used, $unusedOnly->viewData('codes')->pluck('code')->all());
    }

    public function test_a_nonsense_state_filter_is_dropped(): void
    {
        $coupon = $this->uniqueCoupon();
        $this->issue($coupon, 2);

        $response = $this->showAs($coupon, ['state' => 'maybe']);

        $response->assertOk();
        $this->assertSame('', $response->viewData('state'));
        $this->assertCount(2, $response->viewData('codes'));
    }

    /* ---------------------------------------------------------------------
     | A shared batch shows its uses instead
     * ------------------------------------------------------------------ */

    public function test_a_shared_batch_shows_its_uses_rather_than_codes(): void
    {
        $event = $this->perHeadEvent();

        $coupon = $this->fixedCoupon(10, ['quantity' => 50, 'name' => 'POSTER50']);
        $event->coupons()->attach($coupon);

        $registration = $this->registration($event, ['addons_total' => 60], people: 2);
        $this->writer()->applyCode($registration, 'POSTER50');

        $response = $this->showAs($coupon);

        $response->assertOk();

        // No blocks and no individual codes, because there are none to hand out.
        $this->assertNull($response->viewData('codes'));
        $this->assertCount(0, $response->viewData('allocations'));

        // Two uses, one per participant, each naming the person it covered.
        $this->assertCount(2, $response->viewData('uses'));

        $response->assertSee('POSTER50');
        $response->assertSee('Member 1');
        $response->assertSee('Member 2');
        $response->assertSee($registration->reference);
    }

    /* ---------------------------------------------------------------------
     | The four figures on the page
     * ------------------------------------------------------------------ */

    public function test_the_page_labels_the_estimate_as_an_estimate(): void
    {
        $event = $this->event(['fee' => 15]);

        $coupon = $this->uniqueCoupon([
            'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
            'discount_value' => 50,
            'committed_amount' => 2000,
        ]);

        $event->coupons()->attach($coupon);
        $this->issue($coupon, 100);

        $response = $this->showAs($coupon);

        $response->assertOk();

        // The four headings, kept apart.
        $response->assertSee('Committed');
        $response->assertSee('Estimated allocated');
        $response->assertSee('Actually given');
        $response->assertSee('Sponsorship left');

        // And the warning that the estimate is not a fact.
        $response->assertSee('ESTIMATE');
        $response->assertSee('is a guess, not a figure');

        $figures = $response->viewData('figures');

        $this->assertSame(2000.0, $figures['committed']);
        $this->assertSame(750.0, $figures['estimated']);
        $this->assertSame(0.0, $figures['actual']);
        $this->assertSame(2000.0, $figures['remaining']);
    }

    /* ---------------------------------------------------------------------
     | Issuing another block from the screen
     * ------------------------------------------------------------------ */

    public function test_issuing_another_block_needs_the_update_permission(): void
    {
        $coupon = $this->uniqueCoupon();

        $this->actingAs($this->userWith(['coupons.view']))
            ->post(route('admin.coupons.codes.store', $coupon), ['quantity' => 5])
            ->assertForbidden();

        $this->assertSame(0, $coupon->fresh()->issuedCount());
    }

    public function test_another_block_is_issued_with_its_own_handler(): void
    {
        $coupon = $this->uniqueCoupon();
        $this->issue($coupon, 10, ['full_name' => 'Siti Nurhaliza']);

        $this->actingAs($this->userWith(['coupons.view', 'coupons.update']))
            ->post(route('admin.coupons.codes.store', $coupon), [
                'quantity' => 15,
                'holder_full_name' => 'Ahmad Bin Ali',
                'holder_email' => 'ahmad@ngo.test',
                'holder_ic_number' => '900101-13-5566',
                'holder_phone' => '0198887777',
            ])
            ->assertRedirect(route('admin.coupons.report.show', $coupon))
            ->assertSessionHas('status');

        $coupon->refresh();

        // A second block, not a top-up of the first.
        $this->assertSame(2, $coupon->allocations()->count());
        $this->assertSame(25, $coupon->issuedCount());
        $this->assertSame(25, (int) $coupon->quantity);

        $second = $coupon->allocations()->with('holder')->get()->last();

        $this->assertSame(15, $second->codeCount());
        $this->assertSame('Ahmad Bin Ali', $second->holderLabel());
        $this->assertSame('900101-13-5566', $second->holder->ic_number);
    }

    public function test_a_block_may_be_issued_with_nobody_handling_it(): void
    {
        $coupon = $this->uniqueCoupon();

        $this->actingAs($this->userWith(['coupons.view', 'coupons.update']))
            ->post(route('admin.coupons.codes.store', $coupon), ['quantity' => 4])
            ->assertSessionHasNoErrors();

        $block = $coupon->fresh()->allocations()->with('holder')->sole();

        $this->assertFalse($block->hasHolder());
        $this->assertSame('Not assigned', $block->holderLabel());
        $this->assertSame(0, CouponHolder::query()->count());
    }

    public function test_a_phone_number_alone_is_not_enough_to_identify_a_handler(): void
    {
        $coupon = $this->uniqueCoupon();

        $this->actingAs($this->userWith(['coupons.view', 'coupons.update']))
            ->post(route('admin.coupons.codes.store', $coupon), [
                'quantity' => 4,
                'holder_phone' => '0198887777',
            ])
            ->assertSessionHasErrors('holder_full_name');

        // Two people on one office line would merge into a single handler and their
        // allocations would silently add together, so nothing is minted.
        $this->assertSame(0, $coupon->fresh()->issuedCount());
    }

    public function test_a_bad_handler_email_is_refused(): void
    {
        $coupon = $this->uniqueCoupon();

        $this->actingAs($this->userWith(['coupons.view', 'coupons.update']))
            ->post(route('admin.coupons.codes.store', $coupon), [
                'quantity' => 4,
                'holder_full_name' => 'Ahmad Bin Ali',
                'holder_email' => 'not-an-email',
            ])
            ->assertSessionHasErrors('holder_email');

        $this->assertSame(0, $coupon->fresh()->issuedCount());
    }

    public function test_a_shared_batch_cannot_be_asked_to_generate_codes(): void
    {
        $coupon = $this->coupon(['quantity' => 50]);

        $this->actingAs($this->userWith(['coupons.view', 'coupons.update']))
            ->post(route('admin.coupons.codes.store', $coupon), ['quantity' => 5])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(50, (int) $coupon->fresh()->quantity);
        $this->assertSame(0, $coupon->fresh()->issuedCodes()->count());
    }

    /* ---------------------------------------------------------------------
     | The CSV
     * ------------------------------------------------------------------ */

    public function test_the_csv_carries_the_codes_their_handlers_and_their_usage(): void
    {
        /*
         | Pinned, because the file carries a timestamp and the display clock is the
         | office's rather than UTC. 16:30 UTC is already 00:30 the next day in
         | Kuching, so the two dates disagree on every run — which is the condition
         | that has caught this project out four times.
         */
        Carbon::setTestNow(Carbon::parse('2026-10-09 16:30:00', 'UTC'));

        try {
            $event = $this->event(['fee' => 20]);

            $coupon = $this->uniqueCoupon([
                'name' => 'EXPORTME',
                'discount_type' => Coupon::DISCOUNT_FIXED,
                'discount_value' => 10,
            ]);

            $event->coupons()->attach($coupon);

            $block = $this->issue($coupon, 3, [
                'full_name' => 'Siti Nurhaliza',
                'email' => 'siti@ngo.test',
                'ic_number' => '880202-13-1122',
                'phone' => '0123334444',
            ]);

            $used = $this->codeFrom($block);
            $registration = $this->registration($event, people: 1);

            $this->assertTrue($this->writer()->applyCode($registration, $used)->succeeded());

            $response = $this->actingAs($this->userWith(['coupons.view']))
                ->get(route('admin.coupons.report.export', $coupon));

            $response->assertOk();
            $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

            $csv = $response->streamedContent();

            // The header, and the handler's own details: this is how a code is traced
            // back to whoever is holding it.
            // Quoted by fputcsv wherever a heading has a space in it, which is what a
            // spreadsheet expects.
            $this->assertStringContainsString('Code,Handler,"Handler Email","Handler Phone","Handler IC"', $csv);
            $this->assertStringContainsString('Siti Nurhaliza', $csv);
            $this->assertStringContainsString('siti@ngo.test', $csv);
            $this->assertStringContainsString('880202-13-1122', $csv);

            // Every code, used and unused.
            foreach ($block->codes as $code) {
                $this->assertStringContainsString($code->code, $csv);
            }

            $this->assertStringContainsString('Used', $csv);
            $this->assertStringContainsString('Unused', $csv);

            // The redeemer by NAME, and the reference. Not their IC: that belongs to a
            // member of the public and stays on the registration.
            $this->assertStringContainsString('Member 1', $csv);
            $this->assertStringContainsString($registration->reference, $csv);
            $this->assertStringContainsString('10.00', $csv);

            // On the office clock: the 10th locally, the 9th in UTC.
            $this->assertStringContainsString(
                LocalTime::format($block->codes()->used()->sole()->used_at),
                $csv,
            );

            // Excel opens Malaysian names without mojibake.
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_the_csv_is_logged_because_a_code_list_is_a_giveaway(): void
    {
        $coupon = $this->uniqueCoupon(['name' => 'LOGGEDME']);
        $this->issue($coupon, 3, ['full_name' => 'Siti Nurhaliza']);

        $this->actingAs($this->userWith(['coupons.view']))
            ->get(route('admin.coupons.report.export', $coupon))
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', ['action' => 'coupons.export']);

        $line = \App\Models\ActivityLog::query()->where('action', 'coupons.export')->sole();

        $this->assertStringContainsString('LOGGEDME', $line->description);
    }

    public function test_the_csv_carries_the_same_filters_the_screen_does(): void
    {
        $coupon = $this->uniqueCoupon();

        $siti = $this->issue($coupon, 2, ['full_name' => 'Siti Nurhaliza']);
        $ahmad = $this->issue($coupon, 2, ['full_name' => 'Ahmad Bin Ali']);

        $csv = $this->actingAs($this->userWith(['coupons.view']))
            ->get(route('admin.coupons.report.export', ['coupon' => $coupon, 'allocation' => $siti->id]))
            ->streamedContent();

        $this->assertStringContainsString('Siti Nurhaliza', $csv);
        $this->assertStringNotContainsString('Ahmad Bin Ali', $csv);

        foreach ($ahmad->codes as $code) {
            $this->assertStringNotContainsString($code->code, $csv);
        }

        // And the trail says which block was taken, not just that something was.
        $line = \App\Models\ActivityLog::query()->where('action', 'coupons.export')->sole();

        $this->assertStringContainsString('Siti Nurhaliza', $line->description);
    }

    public function test_the_csv_needs_the_coupon_view_permission(): void
    {
        $coupon = $this->uniqueCoupon();
        $this->issue($coupon, 2);

        $this->actingAs($this->userWith(['events.view']))
            ->get(route('admin.coupons.report.export', $coupon))
            ->assertForbidden();

        $this->assertDatabaseMissing('activity_logs', ['action' => 'coupons.export']);
    }

    public function test_a_shared_batch_exports_its_uses(): void
    {
        $event = $this->event(['fee' => 50]);

        $coupon = $this->fixedCoupon(10, ['quantity' => 20, 'name' => 'SHAREDCSV']);
        $event->coupons()->attach($coupon);

        $registration = $this->registration($event, people: 1);
        $this->writer()->applyCode($registration, 'SHAREDCSV');

        $csv = $this->actingAs($this->userWith(['coupons.view']))
            ->get(route('admin.coupons.report.export', $coupon))
            ->streamedContent();

        $this->assertStringContainsString('Code,"Used At","Used By",Reference', $csv);
        $this->assertStringContainsString('SHAREDCSV', $csv);
        $this->assertStringContainsString('Member 1', $csv);
        $this->assertStringContainsString($registration->reference, $csv);
    }
}
