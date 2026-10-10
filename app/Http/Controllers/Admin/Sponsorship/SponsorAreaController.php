<?php

namespace App\Http\Controllers\Admin\Sponsorship;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CouponCode;
use App\Models\CouponIssuedCode;
use App\Models\ShopOrderItem;
use App\Models\User;
use App\Services\AdminLogger;
use App\Support\CouponSponsorship;
use App\Support\LocalTime;
use App\Support\SponsorBlockRow;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * One sponsorship, read-only: what it funded, who used it, and nothing else.
 *
 * WHY THIS IS A SEPARATE AREA RATHER THAN THE STAFF SCREENS WITH SCOPING BOLTED ON
 *
 * The handler could be confined with one middleware because a handler lives under one
 * route prefix. A sponsorship's interest spans Coupon, Event and Shop — three modules,
 * three prefixes — and every one of them has screens that total money and sit beside
 * participants' IC numbers, people's orders and other sponsors' figures. Narrowing
 * those would mean auditing every query in three modules, and one missed query is a
 * leak. So this is a small number of purpose-built screens, each written knowing it is
 * scoped, rather than many screens each taught to restrict.
 *
 * WHOSE SPONSORSHIP IS BEING READ, AND HOW THE ID IS GATED
 *
 * NO ID IN THE REQUEST MEANS "MY OWN". That is what a sponsorship account always gets
 * and the only thing it can ever get: every query starts from the resolved account —
 * sponsoredBlocks() for the blocks the rule resolves to it, sponsoredSharedBatches()
 * for the shared codes it funded outright.
 *
 * An id is accepted from STAFF ONLY, and only from staff holding sponsors.view — the
 * same permission the Sponsorship tab on User Management is behind, reused rather than
 * reinvented so there is one answer to "who may read somebody else's sponsorship". A
 * SPONSOR passing an id is REFUSED outright, not quietly handed their own: silently
 * ignoring it would hide the attempt, and being a sponsor beats holding any permission
 * here. Staff with no id are not shown their own empty figures either — they get the
 * list of sponsorships to open, because a super admin funds nothing and "nothing" is
 * not a useful answer to give them.
 *
 * THREE TABS, AND WHY ONE OF THEM MAY NOT BE THERE
 *
 *   YOUR BLOCKS  the blocks, their holders, how many codes are spent and how many are
 *                left. Always shown.
 *   EVENT        the events the sponsored coupons apply to, and who registered with
 *                them. Only when this sponsorship funds an event-kind batch.
 *   SHOP         the products, and who ordered with them. Only when it funds a
 *                shop-kind batch.
 *
 * A batch is event OR shop, never both, so the ordinary sponsorship has two tabs and
 * only one funding both kinds has three. A tab that cannot ever have anything in it is
 * not drawn, and resolveTab() means putting its slug in ?tab= cannot reach it either.
 *
 * WHAT IS DELIBERATELY NOT ON THIS SCREEN
 *
 *   a redeemer's IC or phone     the ledger stores a NAME ONLY for exactly this
 *                               reason, and NOTHING here joins back to the
 *                               participant, the registration or the order to enrich
 *                               it. The participant list under a usage row is built
 *                               from the ledger rows themselves.
 *   a buyer's address or phone   the same rule on the shop side, which is why
 *                               buyer_name is copied onto the ledger at redeem time.
 *                               What was BOUGHT is read from the order's items with a
 *                               narrow select, because a product name is catalogue
 *                               information and not personal data.
 *   a reference or a payment     what somebody paid, and whether they paid, is none of
 *                               a sponsor's business. The only money shown is the
 *                               discount this sponsorship funded.
 *   a representative's contact   the holder appears by LABEL only. The office keeps
 *                               the email, phone and IC so it can trace a code; a
 *                               sponsor only needs to know whose block it is.
 */
class SponsorAreaController extends Controller
{
    /** A thousand codes is a real ask, so the usage lists page generously. */
    private const PER_PAGE = 50;

    /**
     * Tab slug => label and icon name understood by the admin icon component.
     *
     * Your Blocks first, so it is where resolveTab() lands when a tab is asked for
     * that this sponsorship cannot have.
     */
    public const TABS = [
        'blocks' => ['label' => 'Your Blocks', 'icon' => 'tag'],
        'event' => ['label' => 'Event', 'icon' => 'clipboard'],
        'shop' => ['label' => 'Shop', 'icon' => 'bag'],
    ];

    /**
     * The one permission that lets a staff account read a sponsorship other than its
     * own. Deliberately the Sponsorship tab's own slug and not a new one.
     */
    public const STAFF_PERMISSION = 'sponsors.view';

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
        $sponsor = $this->sponsorship($request);

        // Staff, with nobody named. The list of sponsorships, which is somewhere
        // useful, rather than their own figures, which are nothing.
        if ($sponsor === null) {
            return $this->chooser();
        }

        $batches = $this->fundedBatches($sponsor);
        $tabs = $this->tabsFor($batches);
        $tab = $this->resolveTab($request->query('tab'), $tabs);

        $sort = $this->sortFilter($request);
        $blockId = $this->blockFilter($request, $sponsor);

        /*
         | Its own page name per tab, so a page number from one tab is not carried
         | into another. The tab links themselves are built from the route and the
         | slug alone, which is the other half of that: moving tab does not inherit
         | the page you were on.
         */
        $eventUses = $tab === 'event'
            ? $this->eventGroups($sponsor, $blockId)->paginate(self::PER_PAGE, ['*'], 'event_page')->withQueryString()
            : null;

        $shopUses = $tab === 'shop'
            ? $this->shopUses($sponsor, $blockId)->paginate(self::PER_PAGE, ['*'], 'shop_page')->withQueryString()
            : null;

        // Staff reading somebody else's. Their own account is never this, so the tab
        // links have to carry the id and the screen has to say whose it is.
        $isStaffView = (int) $sponsor->id !== (int) $request->user()->id;

        return view('admin.sponsorship.index', [
            'sponsor' => $sponsor,
            'isStaffView' => $isStaffView,
            'tabParams' => $isStaffView ? ['sponsor' => (int) $sponsor->id] : [],

            // Worked out by CouponSponsorship, which is also what the staff Report
            // and the Sponsorship tab read, so no two screens can disagree.
            'figures' => CouponSponsorship::forSponsor($sponsor),

            'tabs' => $tabs,
            'activeTab' => $tab,

            // Only the tab being drawn is queried, so the others cost nothing.
            'blocks' => $tab === 'blocks' ? $this->rows($sponsor, $sort) : collect(),

            'events' => $tab === 'event' ? $this->tickedEvents($batches) : collect(),
            'eventUses' => $eventUses,
            'participants' => $eventUses === null
                ? []
                : $this->participantNames($sponsor, $blockId, $eventUses->pluck('registration_id')->all()),

            'products' => $tab === 'shop' ? $this->tickedProducts($batches) : collect(),
            'shopUses' => $shopUses,
            'bought' => $shopUses === null
                ? []
                : $this->boughtNames($shopUses->pluck('shop_order_id')->all()),

            // Batch id => its name and what it applies to, so a usage row can name the
            // event or the product without reaching into the registration or the order.
            'batchLabels' => $this->batchLabels($batches),

            'sorts' => self::SORTS,
            'sort' => $sort,
            'blockId' => $blockId,
            'isFiltered' => $blockId !== null,
        ]);
    }

    /**
     * One tab's rows as a UTF-8 CSV.
     *
     * Logged through AdminLogger for a reason that is not bureaucratic: the usage
     * files are lists of members of the public by name, and a list of names is a
     * disclosure even when it is only names. If one ends up somewhere it should not,
     * the trail is what says who took it and when.
     */
    public function exportCsv(Request $request)
    {
        $sponsor = $this->sponsorship($request);

        // Nobody named and staff looking: there is no one sponsorship to export.
        abort_if($sponsor === null, 404);

        $batches = $this->fundedBatches($sponsor);

        $set = (string) $request->query('set');
        $set = array_key_exists($set, self::TABS) ? $set : 'blocks';

        // A tab this sponsorship cannot have has no file either, for the same reason
        // it is not drawn.
        abort_unless(array_key_exists($set, $this->tabsFor($batches)), 404);

        AdminLogger::activity('sponsorship.export', sprintf(
            'Exported the sponsorship %s list as CSV.',
            match ($set) {
                'event' => 'event usage',
                'shop' => 'shop usage',
                default => 'block',
            },
        ));

        $blockId = $this->blockFilter($request, $sponsor);
        $labels = $this->batchLabels($batches);

        $header = match ($set) {
            'event' => ['Code', 'Coupon', 'Event', 'Used At', 'People', 'Participants', 'Discount'],
            'shop' => ['Code', 'Coupon', 'Used At', 'Bought By', 'Products', 'Discount'],
            default => ['Coupon', 'Handler', 'Issued', 'Codes', 'Used', 'Unused', 'State'],
        };

        $rows = match ($set) {
            'event' => $this->eventGroups($sponsor, $blockId),
            'shop' => $this->shopUses($sponsor, $blockId),
            default => null,
        };

        $blocks = $set === 'blocks' ? $this->rows($sponsor, $this->sortFilter($request)) : null;

        return response()->streamDownload(function () use ($set, $header, $rows, $blocks, $sponsor, $blockId, $labels) {
            $handle = fopen('php://output', 'wb');

            // BOM so Excel opens Malaysian names without mojibake.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $header);

            if ($set === 'blocks') {
                foreach ($blocks as $block) {
                    fputcsv($handle, $this->blockRow($block));
                }

                fclose($handle);

                return;
            }

            /*
             | Streamed in chunks, with ONE lookup of the names per chunk rather than
             | one per row. A sponsorship with a thousand uses is a real ask, and the
             | names are the heavy half of both files.
             */
            foreach ($rows->cursor()->chunk(200) as $chunk) {
                if ($set === 'event') {
                    $names = $this->participantNames($sponsor, $blockId, $chunk->pluck('registration_id')->all());

                    foreach ($chunk as $use) {
                        fputcsv($handle, $this->eventRow($use, $labels, $names));
                    }

                    continue;
                }

                $bought = $this->boughtNames($chunk->pluck('shop_order_id')->all());

                foreach ($chunk as $use) {
                    fputcsv($handle, $this->shopRow($use, $labels, $bought));
                }
            }

            fclose($handle);
        }, sprintf('sponsorship-%s-%s.csv', $set, now()->format('Ymd-His')), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /* ---------------------------------------------------------------------
     | Whose sponsorship
     * ------------------------------------------------------------------ */

    /**
     * The sponsorship being read, or null when staff have not named one.
     *
     * The whole of the id gate, in one place, so no screen or export can read
     * somebody else's sponsorship by a different rule.
     */
    private function sponsorship(Request $request): ?User
    {
        $viewer = $request->user();
        $wanted = $request->query('sponsor');

        /*
         | Being a sponsor beats holding the permission. A sponsorship account is the
         | subject of this screen, never a reader of other subjects, so it can only
         | ever be handed itself.
         */
        $mayChoose = ! $viewer->isSponsor() && $viewer->hasPermission(self::STAFF_PERMISSION);

        if (! is_scalar($wanted) || trim((string) $wanted) === '') {
            return $mayChoose ? null : $viewer;
        }

        /*
         | An id was named. REFUSED rather than ignored for anybody who may not name
         | one: narrowing it to their own would hide the attempt, and a 403 is the
         | honest answer to asking for a sponsorship that is not yours.
         */
        abort_unless($mayChoose, 403);
        abort_unless(is_numeric($wanted), 404);

        return User::query()
            ->where('is_sponsor', true)
            ->whereKey((int) $wanted)
            ->firstOrFail();
    }

    /**
     * The sponsorships there are to open, with the same figures the screen shows.
     *
     * Asked of CouponSponsorship per row rather than worked out here in one clever
     * join, exactly as the Sponsorship tab does it: a second implementation of a
     * sponsor's money is how two screens come to disagree about it.
     */
    private function chooser()
    {
        $sponsors = User::query()
            ->where('is_sponsor', true)
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $figures = [];

        foreach ($sponsors as $sponsor) {
            $figures[$sponsor->id] = CouponSponsorship::forSponsor($sponsor);
        }

        return view('admin.sponsorship.choose', [
            'sponsors' => $sponsors,
            'figures' => $figures,
        ]);
    }

    /* ---------------------------------------------------------------------
     | The tabs
     * ------------------------------------------------------------------ */

    /**
     * Which tabs this sponsorship can have at all.
     *
     * A batch is event OR shop, so a sponsorship funding only event coupons has a
     * Shop tab that would be empty for ever. Not drawn rather than drawn empty: the
     * owner has already seen one screen that looked broken when it was merely empty.
     *
     * @param  Collection<int, Coupon>  $batches
     * @return array<string, array<string, string>>
     */
    private function tabsFor(Collection $batches): array
    {
        $kinds = $batches->pluck('kind')->unique();

        return array_filter(
            self::TABS,
            fn (array $tab, string $slug) => match ($slug) {
                'event' => $kinds->contains(Coupon::KIND_EVENT),
                'shop' => $kinds->contains(Coupon::KIND_SHOP),

                // Your Blocks is the sponsorship itself, and it reads as empty on
                // purpose when nothing has been tagged yet.
                default => true,
            },
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param  array<string, array<string, string>>  $allowed  Tabs this sponsorship has.
     */
    private function resolveTab(?string $tab, array $allowed): string
    {
        if (array_key_exists((string) $tab, $allowed)) {
            return (string) $tab;
        }

        // Falls back to the first tab there is rather than always to blocks, which is
        // the same rule the settings screens follow. It is also what stops a hidden
        // tab being reached by typing its slug into the query string.
        return (string) (array_key_first($allowed) ?? 'blocks');
    }

    /**
     * Every batch this sponsorship funds, by either half of the rule, in one query.
     *
     * The blocks' batches and the shared batches it funded outright. Loaded with what
     * they are ticked on, because that is what names the event or the product on a
     * usage row — read off the BATCH, never off a registration or an order.
     *
     * @return Collection<int, Coupon>
     */
    private function fundedBatches(User $sponsor): Collection
    {
        $ids = $sponsor->sponsoredBlocks()->pluck('coupon_allocations.coupon_id')->all();
        $shared = $sponsor->sponsoredSharedBatches()->pluck('coupons.id')->all();

        $all = array_values(array_unique(array_map('intval', array_merge($ids, $shared))));

        return Coupon::query()
            ->whereIn('id', $all ?: [0])
            ->with(['events:id,title,starts_at,ends_at,location', 'products:id,name'])
            ->orderBy('name')
            ->get();
    }

    /**
     * Batch id => its name and what it is ticked on, ready to print.
     *
     * @param  Collection<int, Coupon>  $batches
     * @return array<int, array<string, string>>
     */
    private function batchLabels(Collection $batches): array
    {
        $labels = [];

        foreach ($batches as $batch) {
            $labels[(int) $batch->id] = [
                'name' => (string) $batch->name,
                'applies' => $batch->kind === Coupon::KIND_SHOP
                    ? $batch->products->pluck('name')->filter()->implode(', ')
                    : $batch->events->pluck('title')->filter()->implode(', '),
            ];
        }

        return $labels;
    }

    /* ---------------------------------------------------------------------
     | Your Blocks
     * ------------------------------------------------------------------ */

    /**
     * Everything this sponsorship funded, as one list of rows.
     *
     * The blocks are counted in SQL — ten representatives would otherwise be twenty
     * extra queries and the whole point of this list is being read at a glance — and
     * the shared batches are added beside them.
     *
     * The ORDER is applied in PHP rather than in SQL. The two halves come from two
     * tables, so there is no single query to sort; sorting the merged list is the only
     * way the chosen order means the same thing for both shapes rather than quietly
     * applying to the blocks alone.
     *
     * @return Collection<int, SponsorBlockRow>
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
     * @param  Collection<int, SponsorBlockRow>  $rows
     * @return Collection<int, SponsorBlockRow>
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

    /* ---------------------------------------------------------------------
     | The Event tab
     * ------------------------------------------------------------------ */

    /**
     * The events the sponsored coupons apply to.
     *
     * Read off the BATCH's own ticked events, which is the question being answered —
     * "which event am I funding" — and the reason the screen does not have to touch a
     * registration to answer it. A sponsor could see their blocks, their figures and
     * who used a code, but never the event behind any of it.
     *
     * @param  Collection<int, Coupon>  $batches
     * @return Collection<int, array<string, mixed>>
     */
    private function tickedEvents(Collection $batches): Collection
    {
        $rows = collect();

        foreach ($batches->where('kind', Coupon::KIND_EVENT) as $batch) {
            foreach ($batch->events as $event) {
                $rows->push([
                    'title' => (string) $event->title,

                    // A typed wall-clock date, so it is NOT shifted: an event starts
                    // at the hour on the poster wherever it is read.
                    'when' => LocalTime::dateWallClock($event->starts_at),
                    'location' => (string) $event->location,
                    'coupon' => (string) $batch->name,
                    'discount' => $batch->discountLabel(),
                ]);
            }
        }

        return $rows->sortBy('title')->values();
    }

    /**
     * Who registered with this sponsorship's codes, ONE ROW PER REGISTRATION.
     *
     * Grouped, because the ledger is one row per participant covered: a group of ten
     * entering one code wrote ten rows, and ten rows that all say the same thing is
     * not a list anybody can read. The group carries the head count, the discount it
     * really funded and when — and the names of the ten are listed under it, from the
     * same ledger rows, by participantNames() below.
     *
     * @return \Illuminate\Database\Eloquent\Builder<CouponCode>
     */
    private function eventGroups(User $sponsor, ?int $blockId)
    {
        return $this->ledger($sponsor, $blockId)
            ->whereNotNull('coupon_codes.event_registration_id')
            ->selectRaw('coupon_codes.event_registration_id as registration_id')
            ->selectRaw('coupon_codes.coupon_id as coupon_id')
            ->selectRaw('MIN(coupon_codes.code) as code')
            ->selectRaw('COUNT(*) as people')
            ->selectRaw('SUM(coupon_codes.discount_amount) as discount')
            ->selectRaw('MAX(coupon_codes.redeemed_at) as used_at')

            // By registration AND batch, because the pairing is per claim: two
            // batches on one registration are two claims with their own runs of rows.
            ->groupBy('coupon_codes.event_registration_id', 'coupon_codes.coupon_id')
            ->withCasts(['used_at' => 'datetime', 'discount' => 'decimal:2', 'people' => 'integer'])
            ->orderByDesc('used_at')
            ->orderByDesc('coupon_codes.event_registration_id');
    }

    /**
     * Registration id => the names covered, IN LEDGER ORDER.
     *
     * BUILT FROM THE LEDGER ROWS AND NOTHING ELSE. Not a join to event_participants,
     * not to the registration: the ledger stores participant_name precisely so this
     * list can exist without either, and the moment that join is here an IC number
     * and a phone number are one careless column away from a screen a third party
     * reads.
     *
     * Narrowed by the same ledger scope as the list itself, so a registration id that
     * is not this sponsorship's reaches nothing. One query for the whole page.
     *
     * @param  array<int, int|string|null>  $registrationIds
     * @return array<int, array<int, string>>
     */
    private function participantNames(User $sponsor, ?int $blockId, array $registrationIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $registrationIds)));

        if ($ids === []) {
            return [];
        }

        return $this->ledger($sponsor, $blockId)
            ->whereIn('coupon_codes.event_registration_id', $ids)
            ->orderBy('coupon_codes.id')
            ->get(['coupon_codes.id', 'coupon_codes.event_registration_id', 'coupon_codes.participant_name'])
            ->groupBy('event_registration_id')
            ->map(fn ($rows) => $rows
                ->map(fn (CouponCode $row) => (string) ($row->participant_name ?? ''))
                ->all())
            ->all();
    }

    /* ---------------------------------------------------------------------
     | The Shop tab
     * ------------------------------------------------------------------ */

    /**
     * The products the sponsored coupons apply to, off the batch's own ticks.
     *
     * @param  Collection<int, Coupon>  $batches
     * @return Collection<int, array<string, mixed>>
     */
    private function tickedProducts(Collection $batches): Collection
    {
        $rows = collect();

        foreach ($batches->where('kind', Coupon::KIND_SHOP) as $batch) {
            foreach ($batch->products as $product) {
                $rows->push([
                    'name' => (string) $product->name,
                    'coupon' => (string) $batch->name,
                    'discount' => $batch->discountLabel(),
                ]);
            }
        }

        return $rows->sortBy('name')->values();
    }

    /**
     * The orders placed with this sponsorship's codes, one row each.
     *
     * Not grouped, unlike the event side: the shop claims one use for one order, so a
     * ledger row already IS an order. The buyer's name is read off the row — stored
     * there at redeem time for exactly this reason — so nothing here touches
     * shop_orders, which also holds an address, a phone number and a total.
     *
     * @return \Illuminate\Database\Eloquent\Builder<CouponCode>
     */
    private function shopUses(User $sponsor, ?int $blockId)
    {
        return $this->ledger($sponsor, $blockId)
            ->whereNotNull('coupon_codes.shop_order_id')
            ->select([
                'coupon_codes.id',
                'coupon_codes.coupon_id',
                'coupon_codes.code',
                'coupon_codes.shop_order_id',
                'coupon_codes.buyer_name',
                'coupon_codes.discount_amount',
                'coupon_codes.redeemed_at',
            ])
            ->orderByDesc('coupon_codes.redeemed_at')
            ->orderByDesc('coupon_codes.id');
    }

    /**
     * Order id => the product names on it.
     *
     * A NARROW SELECT, two columns, off the order's items. Catalogue information
     * rather than personal data, which is why reading it is allowed at all where
     * reading the buyer's details off the order is not — and the select says so, so
     * widening it is a visible act rather than an accident.
     *
     * The item rows are snapshots taken when the order was placed, so a product since
     * renamed or deleted still reads as what was actually bought.
     *
     * @param  array<int, int|string|null>  $orderIds
     * @return array<int, array<int, string>>
     */
    private function boughtNames(array $orderIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $orderIds)));

        if ($ids === []) {
            return [];
        }

        return ShopOrderItem::query()
            ->whereIn('shop_order_id', $ids)
            ->orderBy('id')
            ->get(['shop_order_id', 'name'])
            ->groupBy('shop_order_id')
            ->map(fn ($rows) => $rows->pluck('name')->filter()->unique()->values()->all())
            ->all();
    }

    /* ---------------------------------------------------------------------
     | The ledger, which every usage list narrows
     * ------------------------------------------------------------------ */

    /**
     * The ledger rows this sponsorship's codes paid for.
     *
     * Read off the LEDGER rather than off the issued codes. A shared batch mints
     * nothing, so its uses exist only here; reading the issued codes would leave a
     * sponsor looking at a real "actually used" figure above an empty list, which
     * reads as a broken screen.
     *
     * Two ways a row belongs to this sponsorship, matching the two shapes:
     *
     *   it is the row an issued code in one of their blocks paid for, or
     *   it is any use of a shared batch they funded outright.
     *
     * The registration and the order are deliberately NOT loaded: there is nothing on
     * either that a sponsor is entitled to.
     *
     * @return \Illuminate\Database\Eloquent\Builder<CouponCode>
     */
    private function ledger(User $sponsor, ?int $blockId)
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
            });
    }

    /* ---------------------------------------------------------------------
     | The filters
     * ------------------------------------------------------------------ */

    /**
     * The block filter, resolved against THIS sponsorship's own blocks.
     *
     * A number typed into the query string that belongs to somebody else answers
     * null, so the screen falls back to everything the sponsorship funded rather than
     * reaching across.
     */
    private function blockFilter(Request $request, User $sponsor): ?int
    {
        $wanted = $request->query('block');

        if (! is_scalar($wanted) || trim((string) $wanted) === '') {
            return null;
        }

        return $sponsor->sponsoredBlocks()->whereKey($wanted)->exists()
            ? (int) $wanted
            : null;
    }

    private function sortFilter(Request $request): string
    {
        $sort = trim((string) $request->query('sort'));

        return array_key_exists($sort, self::SORTS) ? $sort : 'used';
    }

    /* ---------------------------------------------------------------------
     | CSV rows
     * ------------------------------------------------------------------ */

    /**
     * One block as a row of cells, in the same order as the CSV header.
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
     * One registration's use as a row of cells.
     *
     * Participants are NAMES, from the ledger. Not an IC, not a phone number, not a
     * reference — those belong to members of the public and are not in this file.
     *
     * @param  array<int, array<string, string>>  $labels
     * @param  array<int, array<int, string>>  $participants
     * @return array<int, string>
     */
    private function eventRow(CouponCode $use, array $labels, array $participants): array
    {
        $names = $participants[(int) $use->registration_id] ?? [];

        return [
            (string) $use->code,
            $labels[(int) $use->coupon_id]['name'] ?? '',
            $labels[(int) $use->coupon_id]['applies'] ?? '',
            LocalTime::format($use->used_at, fallback: ''),
            (string) $use->people,
            implode('; ', array_filter($names)),
            number_format((float) $use->discount, 2, '.', ''),
        ];
    }

    /**
     * One order's use as a row of cells.
     *
     * Bought By is a NAME, off the ledger. No address, no phone, no email, no order
     * total: the only money is the discount this sponsorship funded.
     *
     * @param  array<int, array<string, string>>  $labels
     * @param  array<int, array<int, string>>  $bought
     * @return array<int, string>
     */
    private function shopRow(CouponCode $use, array $labels, array $bought): array
    {
        return [
            (string) ($use->code ?? ''),
            $labels[(int) $use->coupon_id]['name'] ?? '',
            LocalTime::format($use->redeemed_at, fallback: ''),
            (string) ($use->buyer_name ?? ''),
            implode('; ', $bought[(int) $use->shop_order_id] ?? []),
            number_format((float) $use->discount_amount, 2, '.', ''),
        ];
    }
}
