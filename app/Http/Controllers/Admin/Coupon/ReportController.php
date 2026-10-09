<?php

namespace App\Http\Controllers\Admin\Coupon;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CouponCode;
use App\Support\LocalDateRange;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

/**
 * What every batch has actually given away.
 *
 * The summary to Tracking's list: Tracking is one row per redemption, this is one row
 * per batch. Both read coupon_codes, which is the only record of a redemption, so
 * neither can disagree with the discount sitting on a registration or an order.
 *
 * TWO KINDS OF FIGURE, AND THEY ANSWER DIFFERENT QUESTIONS
 *
 *   stock      uses allowed, uses spent in total, and uses left. Always for the whole
 *              life of the batch, because "how many are left" cannot be a figure for a
 *              date range: a use spent in January is still gone in March.
 *   activity   redemptions and the discount given. Inside the date range, because that
 *              is what the range is for.
 *
 * The screen labels which is which rather than letting the reader assume, since a
 * filtered page showing an unfiltered remaining count is otherwise confusing.
 */
class ReportController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request)
    {
        $kind = trim((string) $request->query('kind'));
        $kind = array_key_exists($kind, Coupon::KINDS) ? $kind : '';

        $from = LocalDateRange::parse($request->query('from'));
        $to = LocalDateRange::parse($request->query('to'));

        $batches = Coupon::query()
            /*
             | Counted and summed in SQL rather than by asking each row. A page of
             | twenty-five batches would otherwise be a hundred queries, and
             | Coupon::remaining() is one of them per batch.
             |
             | There is no stock count to make: `quantity` IS the allowance, and what
             | is left is that less the ledger rows counted here.
             */
            ->withCount([
                'codes as redeemed_total' => fn ($query) => $query->whereNotNull('redeemed_at'),
                'codes as redeemed_in_range' => fn ($query) => $this->redeemedInRange($query, $from, $to),
            ])
            ->withSum(
                ['codes as discount_in_range' => fn ($query) => $this->redeemedInRange($query, $from, $to)],
                'discount_amount',
            )
            ->when($kind !== '', fn (Builder $query) => $query->where('kind', $kind))
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.coupon.report', [
            'batches' => $batches,
            'kinds' => Coupon::KINDS,
            'kind' => $kind,
            'from' => $from,
            'to' => $to,
            'isFiltered' => $kind !== '' || $from !== null || $to !== null,

            /*
             | The totals over the whole filtered set rather than the page, for the
             | same reason Tracking does it: a figure that only counted the visible
             | rows would disagree with the list the moment anybody turned a page.
             */
            'summary' => $this->summary($kind, $from, $to),
        ]);
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * Redemptions inside the chosen range.
     *
     * The range is CONVERTED, never compared. redeemed_at is stored in UTC and the
     * screen shows it on the office clock, so a bare whereDate against the picker's
     * value would compare a local date against a UTC column — the bug this project
     * has already fixed twice, where a redemption at 04:00 in Kuala Lumpur sits on
     * the previous UTC day and is lost from "today". LocalDateRange turns each
     * boundary into the instant that local day actually begins and ends at.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<CouponCode>|QueryBuilder  $query
     */
    private function redeemedInRange($query, ?string $from, ?string $to)
    {
        // Qualified, because this same closure is used on a query joined to
        // `coupons`, where a bare column name would be ambiguous to read even
        // where it is not ambiguous to the database.
        $query->whereNotNull('coupon_codes.redeemed_at');

        if ($from !== null) {
            $query->where('coupon_codes.redeemed_at', '>=', LocalDateRange::startsAt($from));
        }

        if ($to !== null) {
            $query->where('coupon_codes.redeemed_at', '<=', LocalDateRange::endsAt($to));
        }

        return $query;
    }

    /**
     * The figures across every batch the filters allow.
     *
     * The event and shop split is taken from the batch each redemption belongs to,
     * which is the same thing the rows on screen are grouped by, so the two halves
     * always add up to the total beside them.
     *
     * @return array<string, mixed>
     */
    private function summary(string $kind, ?string $from, ?string $to): array
    {
        $rows = CouponCode::query()
            ->join('coupons', 'coupons.id', '=', 'coupon_codes.coupon_id')
            ->when($kind !== '', fn ($query) => $query->where('coupons.kind', $kind))
            ->tap(fn ($query) => $this->redeemedInRange($query, $from, $to))
            ->groupBy('coupons.kind')
            ->select('coupons.kind')
            ->selectRaw('COUNT(*) as uses')
            ->selectRaw('COALESCE(SUM(coupon_codes.discount_amount), 0) as given')
            ->get()
            ->keyBy('kind');

        $eventGiven = (float) ($rows[Coupon::KIND_EVENT]->given ?? 0);
        $shopGiven = (float) ($rows[Coupon::KIND_SHOP]->given ?? 0);

        return [
            'batches' => Coupon::query()
                ->when($kind !== '', fn (Builder $query) => $query->where('kind', $kind))
                ->count(),

            'redemptions' => (int) $rows->sum('uses'),
            'given' => round($eventGiven + $shopGiven, 2),

            'event_redemptions' => (int) ($rows[Coupon::KIND_EVENT]->uses ?? 0),
            'event_given' => round($eventGiven, 2),
            'shop_redemptions' => (int) ($rows[Coupon::KIND_SHOP]->uses ?? 0),
            'shop_given' => round($shopGiven, 2),

            /*
             | Uses still to be given out, over the whole life of every batch the
             | filters allow: each capped batch's allowance less what it has spent.
             |
             | Unlimited batches are left out of it. They have no remaining count,
             | which is the point of them, and folding them in would need a sentinel
             | that any real figure could exceed.
             |
             | Floored per batch rather than over the sum, so a cap that somehow sits
             | below its own ledger cannot borrow headroom from the batch beside it.
             */
            'uses_left' => (int) Coupon::query()
                ->where('quantity', '>', 0)
                ->when($kind !== '', fn (Builder $query) => $query->where('kind', $kind))
                ->withCount(['codes as spent' => fn ($query) => $query->whereNotNull('redeemed_at')])
                ->get()
                ->sum(fn (Coupon $batch) => max(0, (int) $batch->quantity - (int) $batch->spent)),
        ];
    }
}
