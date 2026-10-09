<?php

namespace App\Http\Controllers\Admin\Coupon;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CouponCode;
use App\Support\LocalTime;
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
        $from = $this->parseDate($request->query('from'));
        $to = $this->parseDate($request->query('to'));

        $redemptions = CouponCode::query()
            ->redeemed()
            ->with(['coupon', 'registration.event', 'order'])
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
            'coupons' => Coupon::query()->orderBy('name')->get(['id', 'name', 'kind']),
            'couponId' => $couponId,
            'from' => $from,
            'to' => $to,
            'isFiltered' => $couponId > 0 || $from !== null || $to !== null,

            /*
             | What the rows on screen gave away, summed over the whole filtered set
             | rather than the page. A badge that only counted the visible twenty-five
             | would disagree with the list the moment anybody turned a page.
             */
            'discountTotal' => (float) CouponCode::query()
                ->redeemed()
                ->when($couponId > 0, fn (Builder $query) => $query->where('coupon_id', $couponId))
                ->when($from !== null, fn (Builder $query) => $query->where('redeemed_at', '>=', $this->fromInstant($from)))
                ->when($to !== null, fn (Builder $query) => $query->where('redeemed_at', '<=', $this->toInstant($to)))
                ->sum('discount_amount'),
        ]);
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * A picker value normalised to a bare Y-m-d, or null when it is not a date.
     *
     * The day it names is a LOCAL day. Which UTC instants that covers is decided
     * below, not here. A malformed value is dropped rather than guessed at, so a bad
     * string cannot widen the range to something nobody asked for.
     */
    private function parseDate(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** The UTC instant a local 'from' day begins at. */
    private function fromInstant(string $date): Carbon
    {
        return Carbon::parse($date, LocalTime::zone())->startOfDay()->utc();
    }

    /** The UTC instant a local 'to' day ends at, so the range is inclusive. */
    private function toInstant(string $date): Carbon
    {
        return Carbon::parse($date, LocalTime::zone())->endOfDay()->utc();
    }
}
