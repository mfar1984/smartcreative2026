<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\CouponCode;
use App\Services\Coupon\CouponRedeemer;
use App\Support\LocalTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * The Report screen's figures.
 *
 * Stock and activity are different questions and the screen answers both: uses allowed
 * and left are for the whole life of a coupon, redeemed and given follow the date
 * range. A test that only checked the unfiltered totals would miss the one bug this
 * screen can actually have.
 *
 * The date range is the other half. redeemed_at is stored in UTC and shown on the
 * office clock, so a redemption at 04:00 local sits on the previous UTC day — the bug
 * this project has fixed twice. The last test here is the one that holds it.
 */
class CouponReportScreenTest extends CouponTestCase
{
    use RefreshDatabase;

    private function reportAs(array $query = [])
    {
        return $this->actingAs($this->userWith(['coupons.view']))
            ->get(route('admin.coupons.report', $query));
    }

    /* ---------------------------------------------------------------------
     | Reach
     * ------------------------------------------------------------------ */

    public function test_the_report_needs_the_coupon_view_permission(): void
    {
        $this->actingAs($this->userWith(['events.view']))
            ->get(route('admin.coupons.report'))
            ->assertForbidden();
    }

    public function test_the_report_is_reachable_with_the_coupon_view_permission(): void
    {
        // Reusing Part A's permission rather than inventing a slug: the same batches
        // read from the other end.
        $this->reportAs()->assertOk()->assertSee('Coupon Report');
    }

    /* ---------------------------------------------------------------------
     | The figures
     * ------------------------------------------------------------------ */

    public function test_it_reports_uses_allowed_redeemed_and_remaining_for_a_coupon(): void
    {
        $coupon = $this->fixedCoupon(15, ['quantity' => 10, 'name' => 'TENOFF']);

        $redeemer = app(CouponRedeemer::class);

        for ($i = 0; $i < 3; $i++) {
            $redeemer->claim($coupon->fresh(), 100);
        }

        $response = $this->reportAs();

        $response->assertOk();
        $response->assertSee('TENOFF');
        $response->assertSee('Uses allowed');

        // 10 allowed, 3 used, 7 left, RM45 given.
        $batch = $response->viewData('batches')->firstWhere('name', 'TENOFF');

        $this->assertSame(10, (int) $batch->quantity);
        $this->assertSame(3, (int) $batch->redeemed_total);
        $this->assertSame(3, (int) $batch->redeemed_in_range);
        $this->assertSame(45.0, (float) $batch->discount_in_range);

        $summary = $response->viewData('summary');

        $this->assertSame(45.0, $summary['given']);
        $this->assertSame(3, $summary['redemptions']);
        $this->assertSame(7, $summary['uses_left']);
    }

    public function test_an_unlimited_coupon_has_no_stock_to_report(): void
    {
        $coupon = $this->fixedCoupon(5, ['quantity' => 0, 'name' => 'ALWAYS']);

        app(CouponRedeemer::class)->claim($coupon, 100);
        app(CouponRedeemer::class)->claim($coupon->fresh(), 100);

        $response = $this->reportAs();

        $response->assertSee('Unlimited');

        $batch = $response->viewData('batches')->firstWhere('name', 'ALWAYS');

        $this->assertSame(2, (int) $batch->redeemed_total);
        $this->assertSame(10.0, (float) $batch->discount_in_range);

        // It has nothing to count down, so it is left out of the uses-left figure
        // rather than folded in and turned into a negative.
        $this->assertSame(0, $response->viewData('summary')['uses_left']);
    }

    public function test_it_splits_the_total_between_events_and_the_shop(): void
    {
        $eventBatch = $this->fixedCoupon(20, ['kind' => Coupon::KIND_EVENT, 'quantity' => 5]);
        $shopBatch = $this->fixedCoupon(30, ['kind' => Coupon::KIND_SHOP, 'quantity' => 5]);

        $redeemer = app(CouponRedeemer::class);

        $redeemer->claim($eventBatch, 100);
        $redeemer->claim($eventBatch->fresh(), 100);
        $redeemer->claim($shopBatch, 100);

        $summary = $this->reportAs()->viewData('summary');

        $this->assertSame(70.0, $summary['given']);
        $this->assertSame(3, $summary['redemptions']);

        $this->assertSame(40.0, $summary['event_given']);
        $this->assertSame(2, $summary['event_redemptions']);

        $this->assertSame(30.0, $summary['shop_given']);
        $this->assertSame(1, $summary['shop_redemptions']);

        // The two halves add up to the total beside them.
        $this->assertSame($summary['given'], round($summary['event_given'] + $summary['shop_given'], 2));
    }

    public function test_the_kind_filter_narrows_both_the_rows_and_the_totals(): void
    {
        $eventBatch = $this->fixedCoupon(20, ['kind' => Coupon::KIND_EVENT, 'quantity' => 5, 'name' => 'EVTONE']);
        $shopBatch = $this->fixedCoupon(30, ['kind' => Coupon::KIND_SHOP, 'quantity' => 5, 'name' => 'SHPONE']);

        app(CouponRedeemer::class)->claim($eventBatch, 100);
        app(CouponRedeemer::class)->claim($shopBatch, 100);

        $response = $this->reportAs(['kind' => Coupon::KIND_SHOP]);

        $response->assertSee('SHPONE');
        $response->assertDontSee('EVTONE');

        $summary = $response->viewData('summary');

        $this->assertSame(30.0, $summary['given']);
        $this->assertSame(1, $summary['batches']);
        $this->assertSame(0.0, $summary['event_given']);
    }

    public function test_a_coupon_that_has_never_been_used_reports_zeroes_rather_than_nothing(): void
    {
        $this->fixedCoupon(15, ['quantity' => 4, 'name' => 'UNUSED']);

        $response = $this->reportAs();

        $batch = $response->viewData('batches')->firstWhere('name', 'UNUSED');

        $this->assertSame(4, (int) $batch->quantity);
        $this->assertSame(0, (int) $batch->redeemed_total);
        $this->assertSame(0.0, (float) ($batch->discount_in_range ?? 0));
        $this->assertSame(4, $response->viewData('summary')['uses_left']);
    }

    /* ---------------------------------------------------------------------
     | The date range
     * ------------------------------------------------------------------ */

    public function test_the_range_narrows_the_activity_but_not_the_stock(): void
    {
        $coupon = $this->fixedCoupon(10, ['quantity' => 10, 'name' => 'RANGED']);
        $redeemer = app(CouponRedeemer::class);

        // Two last month, one today.
        Carbon::setTestNow(now()->subMonth());
        $redeemer->claim($coupon->fresh(), 100);
        $redeemer->claim($coupon->fresh(), 100);
        Carbon::setTestNow();

        $redeemer->claim($coupon->fresh(), 100);

        $response = $this->reportAs([
            'from' => now()->toDateString(),
            'to' => now()->toDateString(),
        ]);

        $batch = $response->viewData('batches')->firstWhere('name', 'RANGED');

        // Activity, narrowed.
        $this->assertSame(1, (int) $batch->redeemed_in_range);
        $this->assertSame(10.0, (float) $batch->discount_in_range);

        // Stock, not narrowed: a use spent last month is still gone.
        $this->assertSame(10, (int) $batch->quantity);
        $this->assertSame(3, (int) $batch->redeemed_total);
        $this->assertSame(7, $response->viewData('summary')['uses_left']);

        $this->assertSame(10.0, $response->viewData('summary')['given']);
        $this->assertSame(1, $response->viewData('summary')['redemptions']);
    }

    public function test_a_redemption_in_the_small_hours_is_counted_on_its_local_day(): void
    {
        /*
         | The bug this project has fixed twice. 04:00 in Kuala Lumpur is 20:00 the
         | previous day in UTC, which is how the column is stored, so comparing the
         | picker's local date against it loses the row. Asking for today must find a
         | redemption made at 04:00 today.
         */
        $coupon = $this->fixedCoupon(10, ['quantity' => 5, 'name' => 'EARLY']);

        $localMorning = Carbon::parse(now()->toDateString() . ' 04:00:00', LocalTime::zone());

        Carbon::setTestNow($localMorning->copy()->utc());
        app(CouponRedeemer::class)->claim($coupon, 100);
        Carbon::setTestNow();

        $redemption = CouponCode::query()->redeemed()->sole();

        // Stored on the previous UTC day, which is exactly the trap.
        $this->assertSame(
            $localMorning->toDateString(),
            $redemption->redeemed_at->copy()->setTimezone(LocalTime::zone())->toDateString(),
        );

        $response = $this->reportAs([
            'from' => $localMorning->toDateString(),
            'to' => $localMorning->toDateString(),
        ]);

        $batch = $response->viewData('batches')->firstWhere('name', 'EARLY');

        $this->assertSame(1, (int) $batch->redeemed_in_range, 'A local-morning redemption fell out of its own day.');
        $this->assertSame(10.0, (float) $batch->discount_in_range);
    }

    public function test_a_malformed_date_is_dropped_rather_than_widening_the_range(): void
    {
        $coupon = $this->fixedCoupon(10, ['quantity' => 5]);
        app(CouponRedeemer::class)->claim($coupon, 100);

        $response = $this->reportAs(['from' => 'not-a-date', 'to' => '']);

        $response->assertOk();
        $this->assertNull($response->viewData('from'));
        $this->assertNull($response->viewData('to'));
        $this->assertSame(10.0, $response->viewData('summary')['given']);
    }

    public function test_the_report_is_linked_from_the_sidebar(): void
    {
        $this->actingAs($this->userWith(['coupons.view']))
            ->get(route('admin.coupons.tracking'))
            ->assertOk();

        // The nav tree itself is asserted in CouponAdminScreenTest; this is the route
        // existing under the group's own permission.
        $this->reportAs()->assertOk();
    }
}
