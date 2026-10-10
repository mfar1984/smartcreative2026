@extends('layouts.admin')

@php
    use App\Support\LocalTime;
    use App\Support\PaymentFigures;

    $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
    $select = 'rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
@endphp

@section('title', 'Coupon Tracking')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Coupon</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Tracking</span>
@endsection

@section('content')
    <x-admin.page-card
        title="Coupon Tracking"
        description="Every code that has been used, what it was used on, and what it took off."
        :flush="true">

        <x-slot:actions>
            <a href="{{ route('admin.coupons.index') }}"
               class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                <x-admin.icon name="tag" class="w-4 h-4" />
                Coupons
            </a>
        </x-slot:actions>

        <x-admin.filter-bar
            :action="route('admin.coupons.tracking')"
            :reset="$isFiltered ? route('admin.coupons.tracking') : null">

            <label for="coupon" class="sr-only">Coupon</label>
            <select id="coupon" name="coupon" class="{{ $select }}">
                <option value="">All coupons</option>
                @foreach ($coupons as $batch)
                    <option value="{{ $batch->id }}" @selected($couponId === $batch->id)>{{ $batch->name }}</option>
                @endforeach
            </select>

            <x-admin.date-range :from="$from" :to="$to" />
        </x-admin.filter-bar>

        {{-- What the filtered set gave away, over every page rather than the
             twenty-five on screen: a figure that only counted the visible rows would
             disagree with the list the moment anybody turned a page. --}}
        <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-2.5 bg-gray-50 border-b border-gray-200">
            <p class="text-xs text-gray-500">
                {{ number_format($redemptions->total()) }}
                {{ Str::plural('redemption', $redemptions->total()) }}
                @if ($isFiltered) matching those filters @endif
            </p>
            <p class="text-xs text-gray-500">
                <span class="font-semibold text-gray-700">{{ PaymentFigures::money($discountTotal) }}</span>
                given away
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left">
                    <tr>
                        <th scope="col" class="{{ $head }}">Code typed</th>
                        <th scope="col" class="{{ $head }}">Coupon</th>
                        <th scope="col" class="{{ $head }}">Used on</th>

                        {{-- Who this row covered.
                             A use is a PARTICIPANT now, so a group of ten entering one
                             code writes ten rows that share a code, a reference and a
                             timestamp. Without this column they read as ten duplicates;
                             with it they read as what they are. Blank when the use covers
                             the registration as a whole rather than one named head. --}}
                        <th scope="col" class="{{ $head }}">Used by</th>

                        <th scope="col" class="{{ $head }}">Reference</th>
                        <th scope="col" class="{{ $head }} text-right">Discount</th>
                        <th scope="col" class="{{ $head }}">When</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @forelse ($redemptions as $redemption)
                        <tr class="hover:bg-blue-50/40 align-top">
                            <td class="px-5 py-3 font-mono font-semibold text-gray-900 whitespace-nowrap">
                                {{ $redemption->codeLabel() }}
                            </td>

                            <td class="px-5 py-3 whitespace-nowrap">
                                <span class="font-mono text-gray-700">{{ $redemption->coupon?->name ?? '—' }}</span>
                                <span class="block text-xs text-gray-500 mt-0.5">
                                    {{ $redemption->coupon?->kindLabel() ?? '' }}
                                </span>
                            </td>

                            <td class="px-5 py-3 text-gray-600">
                                {{ $redemption->usedOnLabel() }}
                            </td>

                            {{-- The REDEEMER's name, and only their name. Their IC, phone
                                 and payment details are on the registration and stay
                                 there. --}}
                            <td class="px-5 py-3 text-gray-700">
                                {{ $redemption->participant_name ?? '—' }}
                            </td>

                            <td class="px-5 py-3 whitespace-nowrap">
                                @if ($redemption->registration !== null)
                                    <a href="{{ route('admin.event.participants.show', $redemption->registration) }}"
                                       class="font-semibold text-blue-600 hover:underline">
                                        {{ $redemption->registration->reference }}
                                    </a>
                                @elseif ($redemption->order !== null)
                                    <a href="{{ route('admin.shop.orders.show', $redemption->order) }}"
                                       class="font-semibold text-blue-600 hover:underline">
                                        {{ $redemption->order->reference }}
                                    </a>
                                @else
                                    {{-- A nullOnDelete left behind: the registration or order was
                                         deleted but the record of the discount stays, because it
                                         is what the books were built on. --}}
                                    <span class="text-xs text-gray-400">Record removed</span>
                                @endif
                            </td>

                            <td class="px-5 py-3 text-right font-semibold text-gray-900 tabular-nums whitespace-nowrap">
                                {{ $redemption->discountLabel() }}
                            </td>

                            {{-- Through LocalTime, which is the single place that decides the
                                 display clock. redeemed_at is a real instant, so it is shifted. --}}
                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap tabular-nums">
                                {{ LocalTime::format($redemption->redeemed_at) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-12 text-center">
                                <x-admin.icon name="activity" class="w-10 h-10 mx-auto text-gray-300" />

                                <p class="text-sm font-semibold text-gray-700 mt-3">
                                    {{ $isFiltered ? 'Nothing matches those filters' : 'Nothing has been redeemed yet' }}
                                </p>

                                <p class="text-sm text-gray-500 mt-1 max-w-md mx-auto">
                                    @if ($isFiltered)
                                        Clear the filters to see everything.
                                    @else
                                        A row appears here the moment somebody uses a code, with the
                                        discount it actually gave.
                                    @endif
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-5 py-3.5 border-t border-gray-200">
            @if ($redemptions->hasPages())
                {{ $redemptions->links() }}
            @else
                <p class="text-xs text-gray-500">
                    Showing {{ $redemptions->count() }} {{ Str::plural('redemption', $redemptions->count()) }}
                </p>
            @endif
        </div>

    </x-admin.page-card>
@endsection
