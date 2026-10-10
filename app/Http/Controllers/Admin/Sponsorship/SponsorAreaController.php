<?php

namespace App\Http\Controllers\Admin\Sponsorship;

use App\Http\Controllers\Controller;
use App\Models\CouponCode;
use App\Models\CouponIssuedCode;
use App\Models\User;
use App\Services\AdminLogger;
use App\Support\CouponSponsorship;
use App\Support\LocalTime;
use App\Support\SponsorBlockRow;
use Illuminate\Http\Request;

/**
 * A sponsor's own area: what they funded, and nothing else in the system.
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
 * Every query below starts from $request->user() — sponsoredBlocks() for the blocks
 * the rule resolves to them, and sponsoredSharedBatches() for the shared codes they
 * funded outright. There is no sponsor id anywhere in these routes, so there is no
 * number to change: another sponsor's figures are not addressable from here at all.
 * The block filter is resolved against the signed-in sponsor's own blocks, so a stray
 * id narrows to nothing rather than reaching across.
 *
 * TWO SHAPES IN ONE LIST, AND HOW THE SECOND ONE READS
 *
 * A unique batch is funded a BLOCK at a time, each handed to a named representative.
 * A shared batch has no blocks at all — the name on the poster is the code — so it
 * appears as a single row with no handler, labelled "One shared code — no block"
 * rather than left blank. See SponsorBlockRow for why that is a sentence and not a
 * dash, and why there is no invented allocation behind it.
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

        return view('admin.sponsorship.index', [
            'sponsor' => $sponsor,

            // Worked out by CouponSponsorship, which is also what the staff Report
            // and the Sponsorship tab read, so no two screens can disagree.
            'figures' => CouponSponsorship::forSponsor($sponsor),

            'blocks' => $this->rows($sponsor, $sort),
            'uses' => $this->uses($sponsor, $blockId)->paginate(self::PER_PAGE)->withQueryString(),

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

        AdminLogger::activity('sponsorship.export', sprintf(
            'Exported the sponsorship %s list as CSV.',
            $set === 'uses' ? 'usage' : 'block',
        ));

        $header = $set === 'uses'
            ? ['Code', 'Coupon', 'Handler', 'Used At', 'Used By', 'Discount']
            : ['Coupon', 'Handler', 'Issued', 'Codes', 'Used', 'Unused', 'State'];

        $rows = $set === 'uses' ? $this->uses($sponsor, $blockId) : null;
        $blocks = $set === 'blocks' ? $this->rows($sponsor, $this->sortFilter($request)) : null;

        return response()->streamDownload(function () use ($set, $header, $rows, $blocks) {
            $handle = fopen('php://output', 'wb');

            // BOM so Excel opens Malaysian names without mojibake.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $header);

            if ($set === 'uses') {
                $rows->reorder('coupon_codes.id')->chunk(200, function ($chunk) use ($handle) {
                    foreach ($chunk as $use) {
                        fputcsv($handle, $this->useRow($use));
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
     * Everything this sponsorship funded, as one list of rows.
     *
     * The blocks are counted in SQL — ten representatives would otherwise be twenty
     * extra queries and the whole point of this list is being read at a glance — and
     * the shared batches are added beside them.
     *
     * The ORDER is applied in PHP rather than in SQL, which it was before. The two
     * halves come from two tables, so there is no single query to sort; sorting the
     * merged list is the only way the chosen order means the same thing for both
     * shapes rather than quietly applying to the blocks alone.
     *
     * @return \Illuminate\Support\Collection<int, SponsorBlockRow>
     */
    private function rows(User $sponsor, string $sort)
    {
        $blocks = $sponsor->sponsoredBlocks()
            ->with(['coupon:id,name,kind,mode,discount_type,discount_value', 'holder'])
            ->withCount([
                'codes as codes_total',
                'codes as codes_used' => fn ($codes) => $codes->whereNotNull('used_at'),
            ])
            ->get()
            ->map(fn ($block) => SponsorBlockRow::fromBlock($block));

        $shared = $sponsor->sponsoredSharedBatches()
            ->withCount(['codes as uses' => fn ($codes) => $codes->whereNotNull('redeemed_at')])
            ->get()
            ->map(fn ($batch) => SponsorBlockRow::fromSharedBatch($batch, (int) $batch->uses));

        return $this->sorted($blocks->concat($shared), $sort);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SponsorBlockRow>  $rows
     * @return \Illuminate\Support\Collection<int, SponsorBlockRow>
     */
    private function sorted($rows, string $sort)
    {
        return match ($sort) {
            // Unlimited has no "left", so it sorts last rather than as zero: an
            // endless code is not the thing a reader is looking for under "most
            // left first".
            'unused' => $rows->sortByDesc(fn (SponsorBlockRow $row) => $row->left ?? -1)->values(),
            'holder' => $rows->sortBy(fn (SponsorBlockRow $row) => mb_strtolower($row->holderLabel()))->values(),
            'codes' => $rows->sortByDesc(fn (SponsorBlockRow $row) => $row->total ?? PHP_INT_MAX)->values(),
            'newest' => $rows->sortByDesc(fn (SponsorBlockRow $row) => $row->issuedAt?->getTimestamp() ?? 0)->values(),
            default => $rows->sortByDesc(fn (SponsorBlockRow $row) => $row->used)->values(),
        };
    }

    /**
     * The people who used this sponsorship's coupons.
     *
     * Read off the LEDGER rather than off the issued codes, which is what it used to
     * be. A shared batch mints nothing, so its uses exist only here; reading the
     * issued codes would have left a sponsor looking at a real "actually used" figure
     * above an empty list, which reads as a broken screen and is the kind of
     * contradiction this feature is supposed to stop.
     *
     * Two ways a ledger row belongs to this sponsorship, matching the two shapes:
     *
     *   it is the row an issued code in one of their blocks paid for, or
     *   it is any use of a shared batch they funded outright.
     *
     * The registration and the order are deliberately NOT loaded: there is nothing on
     * either that a sponsor is entitled to.
     *
     * @return \Illuminate\Database\Eloquent\Builder<CouponCode>
     */
    private function uses(User $sponsor, ?int $blockId)
    {
        $blockIds = $blockId !== null
            ? [$blockId]
            : ($sponsor->sponsoredBlocks()->pluck('coupon_allocations.id')->all() ?: [0]);

        return CouponCode::query()
            ->whereNotNull('coupon_codes.redeemed_at')
            ->where(function ($query) use ($sponsor, $blockIds, $blockId) {
                $query->whereIn('coupon_codes.id', CouponIssuedCode::query()
                    ->whereIn('coupon_allocation_id', $blockIds)
                    ->whereNotNull('coupon_code_id')
                    ->select('coupon_code_id'));

                /*
                 | Only when nothing is filtered. The filter narrows to one BLOCK, and
                 | a shared batch has none — folding it in anyway would make a
                 | "one block only" view show uses from outside that block.
                 */
                if ($blockId === null) {
                    $query->orWhereIn(
                        'coupon_codes.coupon_id',
                        $sponsor->sponsoredSharedBatches()->select('coupons.id'),
                    );
                }
            })
            ->with(['coupon:id,name', 'issuedCode.allocation.holder'])
            ->orderByDesc('coupon_codes.redeemed_at')
            ->orderByDesc('coupon_codes.id');
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

        return $request->user()->sponsoredBlocks()->whereKey($wanted)->exists()
            ? (int) $wanted
            : null;
    }

    private function sortFilter(Request $request): string
    {
        $sort = trim((string) $request->query('sort'));

        return array_key_exists($sort, self::SORTS) ? $sort : 'used';
    }

    /**
     * One row as a row of cells, in the same order as the CSV header.
     *
     * @return array<int, string>
     */
    private function blockRow(SponsorBlockRow $row): array
    {
        return [
            $row->couponName,

            // The representative by label only. Their email, phone and IC stay with
            // the office, which is the only party that needs to trace a code. A
            // shared batch says in words that there is no block, rather than leaving
            // a cell that reads as missing data.
            $row->holderLabel(),

            $row->issuedLabel(fallback: ''),
            $row->totalLabel(),
            (string) $row->used,
            $row->leftLabel(),
            $row->stateLabel(),
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
    private function useRow(CouponCode $use): array
    {
        return [
            $use->codeLabel(),
            (string) ($use->coupon?->name ?? ''),

            // Empty rather than the screen's sentence: a spreadsheet wants a blank
            // cell it can sort, not prose.
            (string) ($use->holderLabel() ?? ''),

            LocalTime::format($use->redeemed_at, fallback: ''),
            (string) ($use->participant_name ?? ''),
            number_format((float) $use->discount_amount, 2, '.', ''),
        ];
    }
}
