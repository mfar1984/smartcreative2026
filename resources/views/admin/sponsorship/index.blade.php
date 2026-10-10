@extends('layouts.admin')

@php
    use App\Support\PaymentFigures;

    $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
    $select = 'rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
    $exportButton = 'inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition shrink-0';
@endphp

@section('title', 'Sponsorship')

@section('breadcrumb')
    {{-- No Dashboard link for a sponsorship account: it does not hold dashboard.view,
         so offering one would be a link straight to a 403. Staff reading somebody
         else's sponsorship do hold it, and get the way back. --}}
    @if ($isStaffView)
        <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
        <span class="mx-1.5 text-gray-300">/</span>
        <a href="{{ route('admin.sponsorship.index') }}" class="hover:text-gray-700 transition">Sponsorship</a>
    @else
        <span>Sponsorship</span>
    @endif

    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $sponsor->name }}</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>{{ $tabs[$activeTab]['label'] ?? '' }}</span>
@endsection

@section('content')
    <x-admin.settings-shell
        title="Sponsorship"
        :description="'The coupon blocks funded by ' . $sponsor->name . '. View only.'"
        :tabs="$tabs"
        :active-tab="$activeTab"
        route="admin.sponsorship.index"
        :route-params="$tabParams">

        {{-- ---------------- The four figures ----------------

             Above the tabs, because they describe the sponsorship as a whole rather
             than any one tab. Kept visibly apart, the same way the staff Report keeps
             them apart and worked out by the same class. Committed is a promise
             somebody made, estimated is a guess, actually used is money that really
             came off real registrations, and what is left is worked out from the
             actual. Conflating them is how a sponsor's money appears to vanish. --}}
        <x-slot:summary>
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-px bg-gray-200 border-y border-gray-200">
                <div class="bg-white px-5 py-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Committed</p>
                    <p class="text-xl font-bold text-gray-900 tabular-nums mt-1">
                        {{ $figures['committed'] === null ? '—' : PaymentFigures::money($figures['committed']) }}
                    </p>
                    <p class="text-xs text-gray-500 mt-0.5">
                        {{ $figures['committed'] === null ? 'no pledge recorded yet' : 'what you pledged' }}
                    </p>
                </div>

                <div class="bg-white px-5 py-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Estimated allocated</p>
                    <p class="text-xl font-bold text-gray-900 tabular-nums mt-1">
                        {{ $figures['estimated'] === null ? '—' : PaymentFigures::money($figures['estimated']) }}
                    </p>
                    <p class="text-xs text-gray-500 mt-0.5">
                        @if ($figures['estimated'] === null)
                            no single value can be put on these codes yet
                        @else
                            <span class="font-semibold text-amber-700">ESTIMATE</span>
                            &middot; {{ number_format($figures['codes']) }}
                            {{ Str::plural('code', $figures['codes']) }} issued to you
                        @endif
                    </p>
                </div>

                <div class="bg-white px-5 py-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Actually used</p>
                    <p class="text-xl font-bold text-gray-900 tabular-nums mt-1">
                        {{ PaymentFigures::money($figures['actual']) }}
                    </p>
                    <p class="text-xs text-gray-500 mt-0.5">
                        the real discount on {{ number_format($figures['used']) }}
                        {{ Str::plural('code', $figures['used']) }}
                    </p>
                </div>

                <div class="bg-white px-5 py-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Sponsorship left</p>
                    <p class="text-xl font-bold text-gray-900 tabular-nums mt-1">
                        {{ $figures['remaining'] === null ? '—' : PaymentFigures::money($figures['remaining']) }}
                    </p>
                    <p class="text-xs text-gray-500 mt-0.5">
                        {{ $figures['remaining'] === null
                            ? 'needs a committed amount'
                            : 'committed less what was actually used' }}
                    </p>
                </div>
            </div>

            @unless ($figures['exact'])
                <p class="px-5 py-2.5 text-xs text-amber-800 bg-amber-50 border-b border-amber-200">
                    <span class="font-semibold">Estimated allocated is a guess, not a figure.</span>
                    What a percentage coupon is really worth depends on what the person using it
                    was being charged. Only <span class="font-semibold">Actually used</span> is
                    money that moved.
                    @if ($figures['unpriced'] > 0)
                        {{ $figures['unpriced'] }}
                        {{ Str::plural('coupon', $figures['unpriced']) }}
                        {{ $figures['unpriced'] === 1 ? 'has' : 'have' }}
                        no value that can be worked out yet, so {{ $figures['unpriced'] === 1 ? 'it is' : 'they are' }}
                        left out of the estimate entirely.
                    @endif
                </p>
            @endunless
        </x-slot:summary>

        @include('admin.partials.flash')

        @if ($isStaffView)
            <p class="text-xs text-gray-500 mb-4">
                Read only, and read as the office rather than as the sponsorship.
                <a href="{{ route('admin.sponsorship.index') }}" class="font-semibold text-blue-600 hover:underline">
                    Open another sponsorship
                </a>
            </p>
        @endif

        {{-- ==================== Your Blocks ==================== --}}
        @if ($activeTab === 'blocks')
            <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
                <x-admin.section-intro
                    title="Your Blocks"
                    description="Whose codes are finished, and whose have not been touched. A coupon that is one shared code has no block and nobody holding it, so it appears as a single row."
                    icon="tag"
                    class="mb-0" />

                <a href="{{ route('admin.sponsorship.export', array_merge($tabParams, ['set' => 'blocks', 'sort' => $sort])) }}"
                   class="{{ $exportButton }}">
                    <x-admin.icon name="download" class="w-4 h-4" />
                    Blocks CSV
                </a>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <form action="{{ route('admin.sponsorship.index') }}" method="GET"
                      class="flex flex-wrap items-center gap-2 px-5 py-3 border-b border-gray-200 bg-gray-50">
                    <input type="hidden" name="tab" value="blocks">

                    @if ($isStaffView)
                        <input type="hidden" name="sponsor" value="{{ $sponsor->id }}">
                    @endif

                    <label for="sort" class="text-xs font-semibold text-gray-600">Order by</label>
                    <select id="sort" name="sort" class="{{ $select }}">
                        @foreach ($sorts as $slug => $label)
                            <option value="{{ $slug }}" @selected($sort === $slug)>{{ $label }}</option>
                        @endforeach
                    </select>

                    <button type="submit" class="rounded-lg border border-transparent bg-gray-200 px-3.5 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-300 transition">
                        Apply
                    </button>
                </form>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <caption class="sr-only">
                            Every block of codes funded by this sponsorship, how many have been
                            used and how many are left.
                        </caption>

                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="{{ $head }}">Handler</th>
                                <th scope="col" class="{{ $head }}">Coupon</th>
                                <th scope="col" class="{{ $head }}">Issued</th>
                                <th scope="col" class="{{ $head }} text-right">Codes</th>
                                <th scope="col" class="{{ $head }} text-right">Used</th>
                                <th scope="col" class="{{ $head }} text-right">Left</th>
                                <th scope="col" class="{{ $head }} text-center">State</th>
                                <th scope="col" class="{{ $head }} text-center">Who used it</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            @forelse ($blocks as $block)
                                {{-- Every figure and label is already worked out on the row.
                                     See App\Support\SponsorBlockRow: a block of minted codes
                                     and a whole shared batch are two different shapes, and
                                     the row is what lets them read as one list without one
                                     of them pretending to be the other. --}}
                                <tr class="hover:bg-blue-50/40 align-top">
                                    {{-- The representative by NAME only. Their email, phone and
                                         IC stay with the office, which is the party that needs
                                         to trace a code. A shared code was never handed to
                                         anybody, so it says so instead of showing a blank. --}}
                                    <td class="px-5 py-3">
                                        <span @class(['font-semibold text-gray-900', 'text-gray-500 italic' => ! $block->hasHolder()])>
                                            {{ $block->holderLabel() }}
                                        </span>
                                    </td>

                                    <td class="px-5 py-3 text-gray-600">
                                        {{ $block->couponName }}
                                        <span class="block text-xs text-gray-400">
                                            {{ $block->discountLabel }}
                                        </span>
                                    </td>

                                    <td class="px-5 py-3 text-gray-600 whitespace-nowrap tabular-nums">
                                        {{ $block->issuedLabel() }}
                                    </td>

                                    <td class="px-5 py-3 text-right text-gray-600 tabular-nums">{{ $block->totalLabel() }}</td>
                                    <td class="px-5 py-3 text-right font-semibold text-gray-900 tabular-nums">{{ number_format($block->used) }}</td>
                                    <td class="px-5 py-3 text-right text-gray-600 tabular-nums">{{ $block->leftLabel() }}</td>

                                    <td class="px-5 py-3 text-center">
                                        <x-admin.badge :tone="$block->stateTone()" :dot="true">{{ $block->stateLabel() }}</x-admin.badge>
                                    </td>

                                    {{-- Straight to the usage tab this block's uses are on,
                                         narrowed to this block. A shared code has no block to
                                         narrow to, so it says where its uses are instead. --}}
                                    <td class="px-5 py-3 text-center whitespace-nowrap">
                                        @if ($block->blockId === null)
                                            <a href="{{ route('admin.sponsorship.index', array_merge($tabParams, ['tab' => $block->usageTab()])) }}"
                                               class="text-xs font-semibold text-blue-600 hover:underline">
                                                {{ $block->usageTab() === 'shop' ? 'Shop tab' : 'Event tab' }}
                                            </a>
                                        @else
                                            <a href="{{ route('admin.sponsorship.index', array_merge($tabParams, ['tab' => $block->usageTab(), 'block' => $block->blockId])) }}"
                                               class="text-xs font-semibold text-blue-600 hover:underline">
                                                Show
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-5 py-8 text-center text-sm text-gray-500">
                                        No coupon blocks have been tagged to this sponsorship yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- ==================== Event ==================== --}}
        @if ($activeTab === 'event')
            <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
                <x-admin.section-intro
                    title="Event"
                    description="The events your coupons apply to, and who registered with them. Open a row to see the people that registration covered."
                    icon="clipboard"
                    class="mb-0" />

                <a href="{{ route('admin.sponsorship.export', array_merge($tabParams, ['set' => 'event', 'block' => $blockId])) }}"
                   class="{{ $exportButton }}">
                    <x-admin.icon name="download" class="w-4 h-4" />
                    Event CSV
                </a>
            </div>

            {{-- The events themselves, read off the coupon's own ticks. This is what a
                 sponsor could not see at all before: their blocks, their figures and
                 the names, but never the event they were funding. --}}
            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
                <div class="px-5 py-3 border-b border-gray-200 bg-gray-50">
                    <h3 class="text-sm font-bold text-gray-900">Events your coupons apply to</h3>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="{{ $head }}">Event</th>
                                <th scope="col" class="{{ $head }}">Starts</th>
                                <th scope="col" class="{{ $head }}">Where</th>
                                <th scope="col" class="{{ $head }}">Coupon</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            @forelse ($events as $row)
                                <tr class="hover:bg-blue-50/40 align-top">
                                    <td class="px-5 py-3 font-semibold text-gray-900">{{ $row['title'] }}</td>
                                    <td class="px-5 py-3 text-gray-600 whitespace-nowrap tabular-nums">{{ $row['when'] }}</td>
                                    <td class="px-5 py-3 text-gray-600">{{ $row['location'] }}</td>
                                    <td class="px-5 py-3 text-gray-600">
                                        {{ $row['coupon'] }}
                                        <span class="block text-xs text-gray-400">{{ $row['discount'] }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-5 py-8 text-center text-sm text-gray-500">
                                        Your coupons have not been ticked on an event yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <div class="flex flex-wrap items-start justify-between gap-3 px-5 py-3 border-b border-gray-200 bg-gray-50">
                    <div>
                        <h3 class="text-sm font-bold text-gray-900">Who registered with your codes</h3>
                        <p class="text-xs text-gray-500 mt-0.5">
                            One row per registration, with the people it covered listed under it
                            by name. Nothing else about a participant is held here.
                        </p>
                    </div>

                    @if ($isFiltered)
                        <a href="{{ route('admin.sponsorship.index', array_merge($tabParams, ['tab' => 'event'])) }}"
                           class="text-xs font-semibold text-blue-600 hover:underline shrink-0">
                            Showing one block only — show every block
                        </a>
                    @endif
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="{{ $head }}">Code</th>
                                <th scope="col" class="{{ $head }}">Event</th>
                                <th scope="col" class="{{ $head }}">When</th>
                                <th scope="col" class="{{ $head }}">Who joined</th>
                                <th scope="col" class="{{ $head }} text-right">Discount</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            {{-- Grouped by registration, because the ledger is one row per
                                 participant covered: a group of ten entering one code wrote
                                 ten rows. The names under the row are those same ledger
                                 rows — nothing joins back to the registration. --}}
                            @forelse ($eventUses as $use)
                                @php
                                    $names = $participants[(int) $use->registration_id] ?? [];
                                    $batch = $batchLabels[(int) $use->coupon_id] ?? ['name' => '—', 'applies' => ''];
                                @endphp

                                <tr class="hover:bg-blue-50/40 align-top">
                                    {{-- The batch underneath, unless the code IS the
                                         batch name, which is what a shared code is:
                                         printing it twice reads as a mistake. --}}
                                    <td class="px-5 py-3 font-mono font-semibold text-gray-900 whitespace-nowrap">
                                        {{ $use->code ?? $batch['name'] }}
                                        @if ($use->code !== null && $use->code !== $batch['name'])
                                            <span class="block text-xs font-normal text-gray-400">{{ $batch['name'] }}</span>
                                        @endif
                                    </td>

                                    <td class="px-5 py-3 text-gray-600">{{ $batch['applies'] !== '' ? $batch['applies'] : 'Not ticked on an event' }}</td>

                                    <td class="px-5 py-3 text-gray-600 whitespace-nowrap tabular-nums">
                                        {{ \App\Support\LocalTime::format($use->used_at) }}
                                    </td>

                                    {{-- Click to open: the people that registration covered, by
                                         name only. Their IC, phone, reference and what they paid
                                         are on the registration and stay there. --}}
                                    <td class="px-5 py-3 text-gray-700">
                                        <details>
                                            {{-- One expression rather than two lines of
                                                 Blade: "10 participants" has to read as
                                                 one phrase, and two interpolations put a
                                                 newline between the number and the word. --}}
                                            <summary class="cursor-pointer text-sm font-semibold text-blue-600 hover:underline">
                                                {{ number_format((int) $use->people) . ' ' . Str::plural('participant', (int) $use->people) }}
                                            </summary>

                                            <ul class="mt-2 text-sm text-gray-700">
                                                @forelse ($names as $name)
                                                    <li class="py-0.5">{{ $name !== '' ? $name : '—' }}</li>
                                                @empty
                                                    <li class="py-0.5 text-gray-500 italic">No names were recorded against this use.</li>
                                                @endforelse
                                            </ul>
                                        </details>
                                    </td>

                                    <td class="px-5 py-3 text-right font-semibold text-gray-900 tabular-nums whitespace-nowrap">
                                        {{ PaymentFigures::money((float) $use->discount) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-5 py-12 text-center">
                                        <x-admin.icon name="tag" class="w-10 h-10 mx-auto text-gray-300" />
                                        <p class="text-sm font-semibold text-gray-700 mt-3">Nothing has been used yet</p>
                                        <p class="text-sm text-gray-500 mt-1">
                                            A row appears here the moment somebody registers with one of your codes.
                                        </p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="px-5 py-3.5 border-t border-gray-200">
                    @if ($eventUses->hasPages())
                        {{ $eventUses->links() }}
                    @else
                        <p class="text-xs text-gray-500">
                            Showing {{ $eventUses->count() }}
                            {{ Str::plural('registration', $eventUses->count()) }}.
                        </p>
                    @endif
                </div>
            </div>
        @endif

        {{-- ==================== Shop ==================== --}}
        @if ($activeTab === 'shop')
            <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
                <x-admin.section-intro
                    title="Shop"
                    description="The products your coupons apply to, and the orders placed with them: who bought, and what they bought."
                    icon="bag"
                    class="mb-0" />

                <a href="{{ route('admin.sponsorship.export', array_merge($tabParams, ['set' => 'shop', 'block' => $blockId])) }}"
                   class="{{ $exportButton }}">
                    <x-admin.icon name="download" class="w-4 h-4" />
                    Shop CSV
                </a>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
                <div class="px-5 py-3 border-b border-gray-200 bg-gray-50">
                    <h3 class="text-sm font-bold text-gray-900">Products your coupons apply to</h3>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="{{ $head }}">Product</th>
                                <th scope="col" class="{{ $head }}">Coupon</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            @forelse ($products as $row)
                                <tr class="hover:bg-blue-50/40 align-top">
                                    <td class="px-5 py-3 font-semibold text-gray-900">{{ $row['name'] }}</td>
                                    <td class="px-5 py-3 text-gray-600">
                                        {{ $row['coupon'] }}
                                        <span class="block text-xs text-gray-400">{{ $row['discount'] }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="2" class="px-5 py-8 text-center text-sm text-gray-500">
                                        Your coupons have not been ticked on a product yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <div class="flex flex-wrap items-start justify-between gap-3 px-5 py-3 border-b border-gray-200 bg-gray-50">
                    <div>
                        <h3 class="text-sm font-bold text-gray-900">Orders placed with your codes</h3>
                        <p class="text-xs text-gray-500 mt-0.5">
                            Who ordered, and what they bought. No address, no phone number and no
                            order total: the only money here is the discount you funded.
                        </p>
                    </div>

                    @if ($isFiltered)
                        <a href="{{ route('admin.sponsorship.index', array_merge($tabParams, ['tab' => 'shop'])) }}"
                           class="text-xs font-semibold text-blue-600 hover:underline shrink-0">
                            Showing one block only — show every block
                        </a>
                    @endif
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="{{ $head }}">Code</th>
                                <th scope="col" class="{{ $head }}">Bought by</th>
                                <th scope="col" class="{{ $head }}">Products</th>
                                <th scope="col" class="{{ $head }}">When</th>
                                <th scope="col" class="{{ $head }} text-right">Discount</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            {{-- The buyer's NAME, off the ledger row the redemption wrote.
                                 The products are the order's own line snapshots, which is
                                 catalogue information; nothing here reads the order itself. --}}
                            @forelse ($shopUses as $use)
                                @php
                                    $batch = $batchLabels[(int) $use->coupon_id] ?? ['name' => '—', 'applies' => ''];
                                    $items = $bought[(int) $use->shop_order_id] ?? [];
                                @endphp

                                <tr class="hover:bg-blue-50/40 align-top">
                                    <td class="px-5 py-3 font-mono font-semibold text-gray-900 whitespace-nowrap">
                                        {{ $use->code ?? $batch['name'] }}
                                        @if ($use->code !== null && $use->code !== $batch['name'])
                                            <span class="block text-xs font-normal text-gray-400">{{ $batch['name'] }}</span>
                                        @endif
                                    </td>

                                    <td class="px-5 py-3 text-gray-700">{{ $use->buyer_name ?? '—' }}</td>

                                    <td class="px-5 py-3 text-gray-600">
                                        @forelse ($items as $item)
                                            <span class="block">{{ $item }}</span>
                                        @empty
                                            <span class="text-gray-400">—</span>
                                        @endforelse
                                    </td>

                                    <td class="px-5 py-3 text-gray-600 whitespace-nowrap tabular-nums">
                                        {{ $use->redeemedAtLabel() }}
                                    </td>

                                    <td class="px-5 py-3 text-right font-semibold text-gray-900 tabular-nums whitespace-nowrap">
                                        {{ $use->discountLabel() }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-5 py-12 text-center">
                                        <x-admin.icon name="bag" class="w-10 h-10 mx-auto text-gray-300" />
                                        <p class="text-sm font-semibold text-gray-700 mt-3">Nothing has been bought yet</p>
                                        <p class="text-sm text-gray-500 mt-1">
                                            A row appears here the moment somebody orders with one of your codes.
                                        </p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="px-5 py-3.5 border-t border-gray-200">
                    @if ($shopUses->hasPages())
                        {{ $shopUses->links() }}
                    @else
                        <p class="text-xs text-gray-500">
                            Showing {{ $shopUses->count() }} {{ Str::plural('order', $shopUses->count()) }}.
                        </p>
                    @endif
                </div>
            </div>
        @endif
    </x-admin.settings-shell>
@endsection
