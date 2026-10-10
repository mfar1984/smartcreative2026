@extends('layouts.admin')

@php
    use App\Support\PaymentFigures;

    $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
    $select = 'rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
@endphp

@section('title', 'Coupon Report')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Coupon</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Report</span>
@endsection

@section('content')
    <x-admin.page-card
        title="Coupon Report"
        description="One row per coupon: how many uses it allows, how many have been taken, how many are left, and what they gave away. Open a coupon for its sponsorship figures and who is holding its codes."
        :flush="true">

        <x-slot:actions>
            <a href="{{ route('admin.coupons.tracking') }}"
               class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                <x-admin.icon name="activity" class="w-4 h-4" />
                Tracking
            </a>

            <a href="{{ route('admin.coupons.index') }}"
               class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                <x-admin.icon name="tag" class="w-4 h-4" />
                Coupons
            </a>
        </x-slot:actions>

        <x-admin.filter-bar
            :action="route('admin.coupons.report')"
            :reset="$isFiltered ? route('admin.coupons.report') : null">

            <label for="kind" class="sr-only">Applies to</label>
            <select id="kind" name="kind" class="{{ $select }}">
                <option value="">Everything</option>
                @foreach ($kinds as $value => $label)
                    <option value="{{ $value }}" @selected($kind === $value)>{{ $label }}</option>
                @endforeach
            </select>

            <x-admin.date-range :from="$from" :to="$to" />
        </x-admin.filter-bar>

        {{-- The totals over the whole filtered set rather than the page, so they cannot
             disagree with the list the moment anybody turns a page. --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-px bg-gray-200 border-b border-gray-200">
            <div class="bg-white px-5 py-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Given away</p>
                <p class="text-xl font-bold text-gray-900 tabular-nums mt-1">
                    {{ PaymentFigures::money($summary['given']) }}
                </p>
                <p class="text-xs text-gray-500 mt-0.5">
                    over {{ number_format($summary['redemptions']) }}
                    {{ Str::plural('redemption', $summary['redemptions']) }}
                    @if ($from || $to) in this range @endif
                </p>
            </div>

            <div class="bg-white px-5 py-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Event registrations</p>
                <p class="text-xl font-bold text-gray-900 tabular-nums mt-1">
                    {{ PaymentFigures::money($summary['event_given']) }}
                </p>
                <p class="text-xs text-gray-500 mt-0.5">
                    {{ number_format($summary['event_redemptions']) }}
                    {{ Str::plural('use', $summary['event_redemptions']) }}
                </p>
            </div>

            <div class="bg-white px-5 py-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Shop</p>
                <p class="text-xl font-bold text-gray-900 tabular-nums mt-1">
                    {{ PaymentFigures::money($summary['shop_given']) }}
                </p>
                <p class="text-xs text-gray-500 mt-0.5">
                    {{ number_format($summary['shop_redemptions']) }}
                    {{ Str::plural('use', $summary['shop_redemptions']) }}
                </p>
            </div>

            <div class="bg-white px-5 py-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Uses left</p>
                <p class="text-xl font-bold text-gray-900 tabular-nums mt-1">
                    {{ number_format($summary['uses_left']) }}
                </p>
                {{-- Stock, not activity: a use spent in January is still gone in March,
                     so this is never narrowed by the date range. Coupons with no limit
                     are left out of it, having nothing to count down. --}}
                <p class="text-xs text-gray-500 mt-0.5">
                    across {{ number_format($summary['batches']) }}
                    {{ Str::plural('coupon', $summary['batches']) }}, all time
                </p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <caption class="sr-only">
                    Coupons, with how many uses each allows over its whole life and the
                    discount it gave inside the chosen date range.
                </caption>

                <thead class="bg-gray-50 text-left">
                    <tr>
                        <th scope="col" class="{{ $head }}">Coupon Code</th>
                        <th scope="col" class="{{ $head }}">Applies to</th>
                        <th scope="col" class="{{ $head }} text-right">Discount</th>
                        <th scope="col" class="{{ $head }} text-right">Uses allowed</th>
                        <th scope="col" class="{{ $head }} text-right">Redeemed</th>
                        <th scope="col" class="{{ $head }} text-right">Left</th>
                        <th scope="col" class="{{ $head }} text-right">Given</th>
                        <th scope="col" class="{{ $head }} text-center">State</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @forelse ($batches as $batch)
                        @php
                            /*
                             | An unlimited coupon has nothing to count down, so both
                             | figures are null and the cells say so rather than printing
                             | a number that could only be wrong.
                             */
                            $allowed = $batch->isUnlimited() ? null : (int) $batch->quantity;
                            $left = $allowed === null
                                ? null
                                : max(0, $allowed - (int) $batch->redeemed_total);
                        @endphp

                        <tr class="hover:bg-blue-50/40 align-top">
                            <td class="px-5 py-3">
                                {{-- The code opens the batch: its sponsorship figures, the
                                     blocks of codes issued, who handles each one and which
                                     are spent. Same permission as this list. --}}
                                <a href="{{ route('admin.coupons.report.show', $batch) }}"
                                   class="font-mono font-semibold text-blue-600 hover:underline">{{ $batch->name }}</a>
                                <span class="block text-xs text-gray-500 mt-0.5">
                                    expires {{ $batch->expiresLabel() }}
                                </span>
                            </td>

                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap">
                                {{ $batch->kindLabel() }}
                                <span class="block text-xs text-gray-400 mt-0.5">
                                    {{ $batch->modeLabel() }}
                                </span>
                            </td>

                            <td class="px-5 py-3 text-right font-semibold text-gray-900 tabular-nums whitespace-nowrap">
                                {{ $batch->discountLabel() }}
                            </td>

                            <td class="px-5 py-3 text-right text-gray-600 tabular-nums whitespace-nowrap">
                                {{ $allowed === null ? 'Unlimited' : number_format($allowed) }}
                            </td>

                            {{-- Activity: inside the range. The all-time figure is shown
                                 underneath whenever a range is narrowing it, so the Left
                                 column beside it cannot be misread as a range figure. --}}
                            <td class="px-5 py-3 text-right font-semibold text-gray-900 tabular-nums whitespace-nowrap">
                                {{ number_format((int) $batch->redeemed_in_range) }}
                                @if (($from || $to) && (int) $batch->redeemed_total !== (int) $batch->redeemed_in_range)
                                    <span class="block text-xs font-normal text-gray-400">
                                        {{ number_format((int) $batch->redeemed_total) }} all time
                                    </span>
                                @endif
                            </td>

                            <td class="px-5 py-3 text-right text-gray-600 tabular-nums whitespace-nowrap">
                                {{ $left === null ? '—' : number_format($left) }}
                            </td>

                            <td class="px-5 py-3 text-right font-semibold text-gray-900 tabular-nums whitespace-nowrap">
                                {{ PaymentFigures::money((float) ($batch->discount_in_range ?? 0)) }}
                            </td>

                            <td class="px-5 py-3 text-center">
                                <x-admin.badge :tone="$batch->stateTone()" :dot="true">
                                    {{ $batch->stateLabel() }}
                                </x-admin.badge>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-5 py-12 text-center">
                                <x-admin.icon name="tag" class="w-10 h-10 mx-auto text-gray-300" />

                                <p class="text-sm font-semibold text-gray-700 mt-3">
                                    {{ $isFiltered ? 'Nothing matches those filters' : 'No coupons yet' }}
                                </p>

                                <p class="text-sm text-gray-500 mt-1 max-w-md mx-auto">
                                    @if ($isFiltered)
                                        Clear the filters to see every coupon.
                                    @else
                                        Create a coupon and this screen will report what it gives away.
                                    @endif
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-5 py-3.5 border-t border-gray-200">
            @if ($batches->hasPages())
                {{ $batches->links() }}
            @else
                <p class="text-xs text-gray-500">
                    Showing {{ $batches->count() }} {{ Str::plural('coupon', $batches->count()) }}.
                    Uses allowed and Left are for the whole life of each coupon; Redeemed and
                    Given follow the date range.
                </p>
            @endif
        </div>

    </x-admin.page-card>
@endsection
