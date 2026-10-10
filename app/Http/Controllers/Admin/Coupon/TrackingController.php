<?php

namespace App\Http\Controllers\Admin\Coupon;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CouponCode;
use App\Support\LocalDateRange;
use App\Support\MonitorScope;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Every coupon that has actually been used.
 *
 * Read straight off coupon_codes, which is the only record of a redemption, so this
 * screen cannot disagree with the discount sitting on a registration or an order.
 */
class TrackingController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request)
    {
        $couponId = (int) $request->query('coupon', 0);
        $from = LocalDateRange::parse($request->query('from'));
        $to = LocalDateRange::parse($request->query('to'));

        /*
         | Narrowed to redemptions that happened on an assigned event for a monitoring
         | account, and left exactly as it was for everybody else.
         |
         | The redemption is the right unit to scope here rather than the batch. A
         | batch is routinely ticked on several events at once, so refusing a shared
         | one outright would hide a discount really given on the monitor's own event,
         | while showing the batch unscoped would list another organiser's entrants by
         | name. Scoping the rows answers both. A SHOP redemption carries an order
         | rather than a registration, belongs to no event at all, and is therefore not
         | theirs to read.
         */
        $redemptions = MonitorScope::redemptionsOnAssignedEvents(
            CouponCode::query()
                ->redeemed()
                ->with(['coupon', 'registration.event', 'order']),
            $request->user(),
        )
            ->when($couponId > 0, fn (Builder $query) => $query->where('coupon_id', $couponId))

            /*
             | The date range, converted rather than compared.
             |
             | redeemed_at is stored in UTC and the screen shows it on the office
             | clock through LocalTime, so a bare whereDate against the picker's value
             | compares a local date against a UTC column. This project has already
             | shipped that bug once on the Logging screen: a redemption at 04:00 in
             | Kuala Lumpur is stored on the previous UTC day, so filtering "today"
             | lost it. The boundaries are turned into the UTC instants that local day
             | actually covers instead.
             */
            ->when($from !== null, fn (Builder $query) => $query->where('redeemed_at', '>=', $this->fromInstant($from)))
            ->when($to !== null, fn (Builder $query) => $query->where('redeemed_at', '<=', $this->toInstant($to)))

            ->orderByDesc('redeemed_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.coupon.tracking', [
            'redemptions' => $redemptions,
            // The batch picker, narrowed the same way the list is so it cannot offer a
            // batch the request scope would then refuse.
            'coupons' => MonitorScope::couponsOnAssignedEvents(Coupon::query(), $request->user())
                ->orderBy('name')
                ->get(['id', 'name', 'kind']),
            'couponId' => $couponId,
            'from' => $from,
            'to' => $to,
            'isFiltered' => $couponId > 0 || $from !== null || $to !== null,

            /*
             | What the rows on screen gave away, summed over the whole filtered set
             | rather than the page. A badge that only counted the visible twenty-five
             | would disagree with the list the moment anybody turned a page.
             */
            'discountTotal' => (float) MonitorScope::redemptionsOnAssignedEvents(
                CouponCode::query()->redeemed(),
                $request->user(),
            )
                ->when($couponId > 0, fn (Builder $query) => $query->where('coupon_id', $couponId))
                ->when($from !== null, fn (Builder $query) => $query->where('redeemed_at', '>=', $this->fromInstant($from)))
                ->when($to !== null, fn (Builder $query) => $query->where('redeemed_at', '<=', $this->toInstant($to)))
                ->sum('discount_amount'),
        ]);
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /** The UTC instant a local 'from' day begins at. */
    private function fromInstant(string $date): Carbon
    {
        return LocalDateRange::startsAt($date);
    }

    /** The UTC instant a local 'to' day ends at, so the range is inclusive. */
    private function toInstant(string $date): Carbon
    {
        return LocalDateRange::endsAt($date);
    }
}
