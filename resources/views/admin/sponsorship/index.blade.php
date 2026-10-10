@extends('layouts.admin')

@php
    use App\Support\PaymentFigures;

    $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
    $select = 'rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
    $exportButton = 'inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3.5 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition';
@endphp

@section('title', 'Sponsorship')

@section('breadcrumb')
    {{-- No Dashboard link: a sponsorship account does not hold dashboard.view, so
         offering one would be a link straight to a 403. --}}
    <span>Sponsorship</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $sponsor->name }}</span>
@endsection

@section('content')
    <x-admin.page-card
        title="Sponsorship"
        :description="'The coupon blocks funded by ' . $sponsor->name . '. View only.'"
        :flush="true">

        <x-slot:actions>
            <a href="{{ route('admin.sponsorship.export', ['set' => 'blocks', 'sort' => $sort]) }}"
               class="{{ $exportButton }}">
                <x-admin.icon name="download" class="w-4 h-4" />
                Blocks CSV
            </a>

            <a href="{{ route('admin.sponsorship.export', ['set' => 'uses', 'block' => $blockId]) }}"
               class="{{ $exportButton }}">
                <x-admin.icon name="download" class="w-4 h-4" />
                Usage CSV
            </a>
        </x-slot:actions>

        <div class="px-5 pt-5">
            @include('admin.partials.flash')
        </div>

        {{-- ---------------- The four figures ----------------

             Kept visibly apart, the same way the staff Report keeps them apart and
             worked out by the same class. Committed is a promise somebody made,
             estimated is a guess, actually used is money that really came off real
             registrations, and what is left is worked out from the actual. Conflating
             them is how a sponsor's money appears to vanish. --}}
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

        {{-- ---------------- The blocks ---------------- --}}
        <div class="px-5 py-4 border-b border-gray-200">
            <h2 class="text-sm font-bold text-gray-900">Your Blocks</h2>
            <p class="text-xs text-gray-500 mt-0.5">
                Whose codes are finished, and whose have not been touched. Each block was
                generated and handed over on its own, so a finished block is one
                representative's share spent. A coupon that is one shared code has no
                block and nobody holding it, so it appears here as a single row.
            </p>

            <form action="{{ route('admin.sponsorship.index') }}" method="GET" class="flex flex-wrap items-center gap-2 mt-3">
                <label for="sort" class="text-xs font-semibold text-gray-600">Order by</label>
                <select id="sort" name="sort" class="{{ $select }}">
                    @foreach ($sorts as $slug => $label)
                        <option value="{{ $slug }}" @selected($sort === $slug)>{{ $label }}</option>
                    @endforeach
                </select>

                @if ($blockId !== null)
                    <input type="hidden" name="block" value="{{ $blockId }}">
                @endif

                <button type="submit" class="rounded-lg border border-transparent bg-gray-100 px-3.5 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200 transition">
                    Apply
                </button>
            </form>

            <div class="overflow-x-auto mt-3 rounded-lg border border-gray-200">
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
                            <tr @class(['hover:bg-blue-50/40 align-top', 'bg-blue-50/60' => $blockId !== null && $blockId === $block->blockId])>
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

                                <td class="px-5 py-3 text-center whitespace-nowrap">
                                    @if ($block->blockId === null)
                                        {{-- Nothing to narrow to: there is one code and
                                             every use of it is already in the list below. --}}
                                        <span class="text-xs text-gray-400">Listed below</span>
                                    @else
                                        <a href="{{ route('admin.sponsorship.index', ['block' => $block->blockId, 'sort' => $sort]) }}"
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

        {{-- ---------------- Who used the codes ---------------- --}}
        <div class="px-5 py-3 border-b border-gray-200">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-sm font-bold text-gray-900">Who Used Your Coupons</h2>
                    <p class="text-xs text-gray-500 mt-0.5">
                        By name, with the code they used and when. Nothing else about a
                        participant is held here.
                    </p>
                </div>

                @if ($isFiltered)
                    <a href="{{ route('admin.sponsorship.index', ['sort' => $sort]) }}"
                       class="text-xs font-semibold text-blue-600 hover:underline shrink-0">
                        Showing one block only — show every block
                    </a>
                @endif
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left">
                    <tr>
                        <th scope="col" class="{{ $head }}">Code</th>
                        <th scope="col" class="{{ $head }}">Coupon</th>
                        <th scope="col" class="{{ $head }}">Handler</th>
                        <th scope="col" class="{{ $head }}">Used by</th>
                        <th scope="col" class="{{ $head }}">When</th>
                        <th scope="col" class="{{ $head }} text-right">Discount</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    {{-- Read off the LEDGER, which is the only record a shared code's
                         uses leave: a shared batch mints nothing, so reading the issued
                         codes would show a real "actually used" figure above an empty
                         list. --}}
                    @forelse ($uses as $use)
                        <tr class="hover:bg-blue-50/40 align-top">
                            <td class="px-5 py-3 font-mono font-semibold text-gray-900 whitespace-nowrap">
                                {{ $use->codeLabel() }}
                            </td>

                            <td class="px-5 py-3 text-gray-600">{{ $use->coupon?->name ?? '—' }}</td>

                            <td class="px-5 py-3 text-gray-600">{{ $use->holderLabel() ?? 'One shared code — no block' }}</td>

                            {{-- The REDEEMER, by name only. Their IC, phone, reference and
                                 what they paid are on the registration and stay there. --}}
                            <td class="px-5 py-3 text-gray-700">{{ $use->participant_name ?? '—' }}</td>

                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap tabular-nums">
                                {{ $use->redeemedAtLabel() }}
                            </td>

                            <td class="px-5 py-3 text-right font-semibold text-gray-900 tabular-nums whitespace-nowrap">
                                {{ $use->discountLabel() }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-12 text-center">
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
            @if ($uses->hasPages())
                {{ $uses->links() }}
            @else
                <p class="text-xs text-gray-500">
                    Showing {{ $uses->count() }} {{ Str::plural('use', $uses->count()) }}.
                </p>
            @endif
        </div>
    </x-admin.page-card>
@endsection
