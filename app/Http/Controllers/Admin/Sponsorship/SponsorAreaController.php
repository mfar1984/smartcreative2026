<?php

namespace App\Http\Controllers\Admin\Sponsorship;

use App\Http\Controllers\Controller;
use App\Models\CouponIssuedCode;
use App\Services\AdminLogger;
use App\Support\CouponSponsorship;
use App\Support\LocalTime;
use Illuminate\Http\Request;

/**
 * A sponsor's own area: the blocks they funded, and nothing else in the system.
 *
 * WHY THIS IS A SEPARATE AREA RATHER THAN THE STAFF SCREENS WITH SCOPING BOLTED ON
 *
 * The handler could be confined with one middleware because a handler lives under one
 * route prefix. A sponsor's interest spans Coupon, Event and Shop — three modules,
 * three prefixes — and every one of them has screens that total money and sit beside
 * participants' IC numbers, people's orders and other sponsors' figures. Narrowing
 * those would mean auditing every query in three modules, and one missed query is a
 * leak. So this is a small number of purpose-built screens, each written knowing it is
 * scoped, rather than many screens each taught to restrict.
 *
 * HOW THE SCOPING WORKS, AND WHY IT CANNOT BE EDITED IN A URL
 *
 * Every query below starts from $request->user()->sponsoredAllocations(). There is no
 * sponsor id anywhere in these routes, so there is no number to change: another
 * sponsor's figures are not addressable from here at all. The block filter is resolved
 * against the signed-in sponsor's own blocks, so a stray id narrows to nothing rather
 * than reaching across.
 *
 * WHAT IS DELIBERATELY NOT ON THIS SCREEN
 *
 *   a redeemer's IC or phone     the ledger stores a NAME ONLY for exactly this
 *                               reason, and nothing here joins back to the
 *                               participant or the registration to enrich it.
 *   a reference or a payment     what somebody paid, and whether they paid, is none
 *                               of a sponsor's business. The only money shown is the
 *                               discount their own sponsorship funded.
 *   a representative's contact   the holder appears by LABEL only. The office keeps
 *                               the email, phone and IC so it can trace a code; a
 *                               sponsor only needs to know whose block it is. That
 *                               leaves no IC and no phone number on the page at all.
 */
class SponsorAreaController extends Controller
{
    /** A thousand codes is a real ask, so the usage list pages generously. */
    private const PER_PAGE = 50;

    /**
     * Sort slug => label, as the picker reads.
     *
     * The NGO's question is the default: whose block has gone the furthest. Every
     * option is a column already counted, so sorting costs nothing extra.
     */
    public const SORTS = [
        'used' => 'Most used first',
        'unused' => 'Most left first',
        'holder' => 'Handler name',
        'codes' => 'Biggest block first',
        'newest' => 'Newest block first',
    ];

    public function index(Request $request)
    {
        $sponsor = $request->user();

        $blockId = $this->blockFilter($request);
        $sort = $this->sortFilter($request);

        $blocks = $this->blocks($request, $sort);
        $allocationIds = $sponsor->sponsoredAllocations()->pluck('id')->all();

        return view('admin.sponsorship.index', [
            'sponsor' => $sponsor,

            // Worked out by CouponSponsorship, which is also what the staff Report
            // and the Sponsorship tab read, so no two screens can disagree.
            'figures' => CouponSponsorship::forSponsor($sponsor),

            'blocks' => $blocks,
            'uses' => $this->uses($allocationIds, $blockId)->paginate(self::PER_PAGE)->withQueryString(),

            'sorts' => self::SORTS,
            'sort' => $sort,
            'blockId' => $blockId,
            'isFiltered' => $blockId !== null,
        ]);
    }

    /**
     * Their blocks, or the people who used them, as a UTF-8 CSV.
     *
     * Logged through AdminLogger for a reason that is not bureaucratic: the usage
     * file is a list of members of the public by name, and a list of names is a
     * disclosure even when it is only names. If it ends up somewhere it should not,
     * the trail is what says who took it and when.
     */
    public function exportCsv(Request $request)
    {
        $sponsor = $request->user();

        $set = $request->query('set') === 'uses' ? 'uses' : 'blocks';
        $blockId = $this->blockFilter($request);
        $allocationIds = $sponsor->sponsoredAllocations()->pluck('id')->all();

        AdminLogger::activity('sponsorship.export', sprintf(
            'Exported the sponsorship %s list as CSV.',
            $set === 'uses' ? 'usage' : 'block',
        ));

        $header = $set === 'uses'
            ? ['Code', 'Coupon', 'Handler', 'Used At', 'Used By', 'Discount']
            : ['Coupon', 'Handler', 'Issued', 'Codes', 'Used', 'Unused', 'State'];

        $rows = $set === 'uses'
            ? $this->uses($allocationIds, $blockId)->with(['coupon:id,name', 'allocation.holder', 'redemption'])
            : null;

        $blocks = $set === 'blocks' ? $this->blocks($request, $this->sortFilter($request)) : null;

        return response()->streamDownload(function () use ($set, $header, $rows, $blocks) {
            $handle = fopen('php://output', 'wb');

            // BOM so Excel opens Malaysian names without mojibake.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $header);

            if ($set === 'uses') {
                $rows->orderBy('id')->chunk(200, function ($chunk) use ($handle) {
                    foreach ($chunk as $code) {
                        fputcsv($handle, $this->useRow($code));
                    }
                });
            } else {
                foreach ($blocks as $block) {
                    fputcsv($handle, $this->blockRow($block));
                }
            }

            fclose($handle);
        }, sprintf('sponsorship-%s-%s.csv', $set, now()->format('Ymd-His')), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * The sponsor's blocks, counted in SQL.
     *
     * One row per block: whose it is, how many codes, how many used, how many left.
     * Counted here rather than by asking each row, because ten representatives would
     * otherwise be twenty extra queries and the whole point of this list is being
     * read at a glance.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\CouponAllocation>
     */
    private function blocks(Request $request, string $sort)
    {
        $query = $request->user()->sponsoredAllocations()
            ->with(['coupon:id,name,kind,mode,discount_type,discount_value', 'holder'])
            ->withCount([
                'codes as codes_total',
                'codes as codes_used' => fn ($codes) => $codes->whereNotNull('used_at'),
            ]);

        return match ($sort) {
            'unused' => $query->orderByRaw('(codes_total - codes_used) desc')->orderBy('id')->get(),
            'holder' => $query->get()->sortBy(fn ($block) => mb_strtolower($block->holderLabel()))->values(),
            'codes' => $query->orderByDesc('codes_total')->orderBy('id')->get(),
            'newest' => $query->orderByDesc('issued_at')->orderByDesc('id')->get(),
            default => $query->orderByDesc('codes_used')->orderBy('id')->get(),
        };
    }

    /**
     * The people who used the sponsor's codes.
     *
     * Read off the issued codes, which is what ties a use to a block and so to this
     * sponsor. The ledger row is loaded for the name, the moment and the discount,
     * and the registration and the order are deliberately NOT loaded: there is
     * nothing on either that a sponsor is entitled to.
     *
     * @param  array<int, int>  $allocationIds
     * @return \Illuminate\Database\Eloquent\Builder<CouponIssuedCode>
     */
    private function uses(array $allocationIds, ?int $blockId)
    {
        return CouponIssuedCode::query()
            ->whereIn('coupon_allocation_id', $blockId !== null ? [$blockId] : ($allocationIds ?: [0]))
            ->whereNotNull('used_at')
            ->with(['coupon:id,name', 'allocation.holder', 'redemption'])
            ->orderByDesc('used_at')
            ->orderByDesc('id');
    }

    /**
     * The block filter, resolved against THIS sponsor's own blocks.
     *
     * A number typed into the query string that belongs to somebody else answers
     * null, so the screen falls back to everything this sponsor funded rather than
     * reaching across. This is the one place in the area that takes an id at all.
     */
    private function blockFilter(Request $request): ?int
    {
        $wanted = $request->query('block');

        if (! is_scalar($wanted) || trim((string) $wanted) === '') {
            return null;
        }

        return $request->user()->sponsoredAllocations()->whereKey($wanted)->exists()
            ? (int) $wanted
            : null;
    }

    private function sortFilter(Request $request): string
    {
        $sort = trim((string) $request->query('sort'));

        return array_key_exists($sort, self::SORTS) ? $sort : 'used';
    }

    /**
     * One block as a row of cells, in the same order as the CSV header.
     *
     * @return array<int, string>
     */
    private function blockRow($block): array
    {
        $total = (int) $block->codes_total;
        $used = (int) $block->codes_used;

        return [
            (string) ($block->coupon?->name ?? ''),

            // The representative by label only. Their email, phone and IC stay with
            // the office, which is the only party that needs to trace a code.
            $block->holderLabel(),

            LocalTime::format($block->issued_at, fallback: ''),
            (string) $total,
            (string) $used,
            (string) max(0, $total - $used),
            $total - $used === 0 ? 'Finished' : ($used === 0 ? 'Untouched' : 'In use'),
        ];
    }

    /**
     * One use as a row of cells.
     *
     * Used By is a NAME. Not an IC, not a phone number, not a reference — those
     * belong to a member of the public and are not in this file.
     *
     * @return array<int, string>
     */
    private function useRow(CouponIssuedCode $code): array
    {
        return [
            (string) $code->code,
            (string) ($code->coupon?->name ?? ''),
            $code->holderLabel(),

            // Empty rather than the screen's em dash: a spreadsheet column wants a
            // blank cell it can sort, not a character that reads as data.
            LocalTime::format($code->used_at, fallback: ''),

            (string) ($code->redeemerName() ?? ''),
            $code->redemption === null ? '' : number_format((float) $code->redemption->discount_amount, 2, '.', ''),
        ];
    }
}
