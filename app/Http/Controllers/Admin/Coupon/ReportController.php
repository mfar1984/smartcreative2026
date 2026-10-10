<?php

namespace App\Http\Controllers\Admin\Coupon;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CouponCode;
use App\Models\CouponIssuedCode;
use App\Models\User;
use App\Services\AdminLogger;
use App\Support\CouponHolderIdentity;
use App\Support\CouponSponsorship;
use App\Support\LocalDateRange;
use App\Support\LocalTime;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

    /** A thousand codes is a real ask, so the detail page pages generously. */
    private const CODES_PER_PAGE = 100;

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

    /**
     * One batch, opened up: its money, its blocks, and its codes.
     *
     * The sponsor's four figures sit at the top, kept visibly apart — committed,
     * ESTIMATED, ACTUAL and remaining — because conflating them is how a sponsor's
     * money appears to vanish. CouponSponsorship works them out; this only renders
     * them.
     *
     * Below that, the NGO's question: one row per block showing whose it is, how many
     * are used and how many are untouched. Then the codes themselves, filterable by
     * block and by used or unused.
     *
     * A SHARED batch has no blocks and no individual codes, so it shows its uses
     * instead — the same ledger, read as a list of who used the one shared code.
     */
    public function show(Request $request, Coupon $coupon)
    {
        $allocationId = $this->allocationFilter($request, $coupon);
        $state = $this->stateFilter($request);

        return view('admin.coupon.report-show', [
            'coupon' => $coupon,
            'figures' => CouponSponsorship::figures($coupon),

            'allocations' => $this->allocations($coupon),
            'codes' => $coupon->isUnique()
                ? $this->codes($coupon, $allocationId, $state)->paginate(self::CODES_PER_PAGE)->withQueryString()
                : null,
            'uses' => $coupon->isUnique()
                ? null
                : $this->uses($coupon)->paginate(self::CODES_PER_PAGE)->withQueryString(),

            'allocationId' => $allocationId,
            'state' => $state,
            'isFiltered' => $allocationId !== null || $state !== '',
            'canIssue' => $request->user()->hasPermission('coupons.update'),

            /*
             | The sponsorship accounts a block can be tagged to. Loaded only for
             | somebody who may actually tag one, because for everybody else it is a
             | list of accounts with no control to use it on.
             */
            'sponsors' => $request->user()->hasPermission('coupons.update')
                ? User::query()->where('is_sponsor', true)->orderBy('name')->get(['id', 'name'])
                : collect(),
        ]);
    }

    /**
     * The codes, or the uses, as a UTF-8 CSV.
     *
     * Carries the same filters the screen does, so the file is exactly the set on
     * screen. Logged through AdminLogger for a reason that is not bureaucratic: this
     * file is a list of working codes, and with the handler columns it is a list of
     * names, emails, phone numbers and IC numbers. If it ends up somewhere it should
     * not, the trail is what says who took it and when.
     *
     * The redeemer appears by NAME ONLY. A participant's IC and phone are on their
     * registration and deliberately stay there.
     */
    public function exportCsv(Request $request, Coupon $coupon)
    {
        $allocationId = $this->allocationFilter($request, $coupon);
        $state = $this->stateFilter($request);

        AdminLogger::activity('coupons.export', sprintf(
            'Exported the %s %s list as CSV%s.',
            $coupon->name,
            $coupon->isUnique() ? 'code' : 'use',
            $this->filterLabel($coupon, $allocationId, $state),
        ));

        $header = $coupon->isUnique()
            ? ['Code', 'Handler', 'Handler Email', 'Handler Phone', 'Handler IC', 'Issued', 'Status', 'Used At', 'Used By', 'Reference', 'Discount']
            : ['Code', 'Used At', 'Used By', 'Reference', 'Used On', 'Discount'];

        $rows = $coupon->isUnique()
            ? $this->codes($coupon, $allocationId, $state)->with(['allocation.holder', 'redemption.registration', 'redemption.order'])
            : $this->uses($coupon)->with(['registration', 'order']);

        return response()->streamDownload(function () use ($rows, $header, $coupon) {
            $handle = fopen('php://output', 'wb');

            // BOM so Excel opens Malaysian names without mojibake.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $header);

            $rows->orderBy('id')->chunk(200, function ($chunk) use ($handle, $coupon) {
                foreach ($chunk as $row) {
                    fputcsv($handle, $coupon->isUnique()
                        ? $this->codeRow($row)
                        : $this->useRow($row));
                }
            });

            fclose($handle);
        }, sprintf('coupon-%s-%s.csv', Str::lower($coupon->name), now()->format('Ymd-His')), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * The blocks, with their counts in SQL.
     *
     * Counted here rather than by asking each row: ten representatives would be
     * twenty extra queries, and the whole point of this list is being read at a
     * glance.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\CouponAllocation>
     */
    private function allocations(Coupon $coupon)
    {
        if (! $coupon->isUnique()) {
            return collect();
        }

        return $coupon->allocations()
            ->with(['holder', 'sponsor:id,name'])
            ->withCount([
                'codes as codes_total',
                'codes as codes_used' => fn ($query) => $query->whereNotNull('used_at'),
            ])
            ->orderBy('id')
            ->get()
            /*
             | The batch is handed to each row rather than fetched by it. The screen
             | shows whose block each one is through
             | CouponAllocation::effectiveSponsor(), which falls back to the batch's
             | own sponsorship — and that would be one query per block for a batch
             | already loaded and sitting right here.
             */
            ->each(fn ($allocation) => $allocation->setRelation('coupon', $coupon));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<CouponIssuedCode>
     */
    private function codes(Coupon $coupon, ?int $allocationId, string $state)
    {
        return CouponIssuedCode::query()
            ->where('coupon_id', $coupon->id)
            ->when($allocationId !== null, fn ($query) => $query->where('coupon_allocation_id', $allocationId))
            ->when($state === 'used', fn ($query) => $query->whereNotNull('used_at'))
            ->when($state === 'unused', fn ($query) => $query->whereNull('used_at'))
            ->with(['allocation.holder', 'redemption.registration', 'redemption.order'])
            ->orderBy('id');
    }

    /**
     * A shared batch's uses: the ledger, one row per participant covered.
     *
     * @return \Illuminate\Database\Eloquent\Builder<CouponCode>
     */
    private function uses(Coupon $coupon)
    {
        return CouponCode::query()
            ->where('coupon_id', $coupon->id)
            ->whereNotNull('redeemed_at')
            ->with(['registration', 'order'])
            ->orderByDesc('id');
    }

    /** The block filter, resolved against this batch so a stray id cannot leak rows. */
    private function allocationFilter(Request $request, Coupon $coupon): ?int
    {
        $wanted = $request->query('allocation');

        if (! is_string($wanted) || trim($wanted) === '') {
            return null;
        }

        return $coupon->allocations()->whereKey($wanted)->exists() ? (int) $wanted : null;
    }

    private function stateFilter(Request $request): string
    {
        $state = trim((string) $request->query('state'));

        return in_array($state, ['used', 'unused'], true) ? $state : '';
    }

    /** What was filtered, in words, for the trail entry. */
    private function filterLabel(Coupon $coupon, ?int $allocationId, string $state): string
    {
        $parts = [];

        if ($allocationId !== null) {
            $parts[] = sprintf(
                'block %d (%s)',
                $allocationId,
                $coupon->allocations()->with('holder')->find($allocationId)?->holderLabel() ?? CouponHolderIdentity::UNASSIGNED,
            );
        }

        if ($state !== '') {
            $parts[] = $state;
        }

        return $parts === [] ? '' : ', filtered to '.implode(' and ', $parts);
    }

    /**
     * One issued code as a row of cells, in the same order as the CSV header.
     *
     * Used By is the redeemer's NAME. Not their IC, not their phone — those belong to
     * a member of the public and stay on the registration. The IC in this file is the
     * HANDLER's, which is there precisely so a code can be traced back to whoever was
     * given it.
     *
     * @return array<int, string>
     */
    private function codeRow(CouponIssuedCode $code): array
    {
        $holder = $code->allocation?->holder;
        $redemption = $code->redemption;

        return [
            $code->code,
            $code->holderLabel(),
            (string) ($holder?->email ?? ''),
            (string) ($holder?->phone ?? ''),
            (string) ($holder?->ic_number ?? ''),
            $code->allocation === null ? '' : LocalTime::format($code->allocation->issued_at),
            $code->stateLabel(),

            // Empty rather than the screen's em dash: a spreadsheet column wants a
            // blank cell it can sort, not a character that reads as data.
            $code->used_at === null ? '' : LocalTime::format($code->used_at),

            (string) ($code->redeemerName() ?? ''),
            (string) ($redemption?->usedOnReference() ?? ''),
            $redemption === null ? '' : number_format((float) $redemption->discount_amount, 2, '.', ''),
        ];
    }

    /**
     * One use of a shared code as a row of cells.
     *
     * @return array<int, string>
     */
    private function useRow(CouponCode $use): array
    {
        return [
            $use->codeLabel(),
            LocalTime::format($use->redeemed_at),
            (string) ($use->participant_name ?? ''),
            (string) ($use->usedOnReference() ?? ''),
            $use->usedOnLabel(),
            number_format((float) $use->discount_amount, 2, '.', ''),
        ];
    }

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
