@extends('layouts.admin')

@php
    $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
    $select = 'rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
@endphp

@section('title', 'Coupons')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Coupon</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Coupon</span>
@endsection

@section('content')
    <x-admin.page-card
        title="Coupons"
        description="The code is the coupon's name, and Uses is how many times it may be typed. Tick it on an event or a product to let that discount be used there."
        :flush="true">

        <x-slot:actions>
            <a href="{{ route('admin.coupons.tracking') }}"
               class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                <x-admin.icon name="activity" class="w-4 h-4" />
                Tracking
            </a>

            @if ($canCreate)
                <a href="{{ route('admin.coupons.create') }}"
                   class="inline-flex items-center gap-2 bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                    <x-admin.icon name="plus" class="w-4 h-4" />
                    New Coupon
                </a>
            @endif
        </x-slot:actions>

        @include('admin.partials.flash')

        <x-admin.filter-bar
            :action="route('admin.coupons.index')"
            :reset="$isFiltered ? route('admin.coupons.index') : null">

            <div class="relative flex-1 min-w-56">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                    <x-admin.icon name="search" class="w-4 h-4" />
                </span>
                <label for="q" class="sr-only">Search by coupon code</label>
                <input type="search" id="q" name="q" value="{{ $search }}"
                       placeholder="Coupon code people type..."
                       class="w-full rounded-lg border border-gray-300 pl-9 pr-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
            </div>

            <label for="kind" class="sr-only">Applies to</label>
            <select id="kind" name="kind" class="{{ $select }}">
                <option value="">Everything</option>
                @foreach ($kinds as $value => $label)
                    <option value="{{ $value }}" @selected($kind === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </x-admin.filter-bar>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left">
                    <tr>
                        <th scope="col" class="{{ $head }}">Coupon Code</th>
                        <th scope="col" class="{{ $head }}">Applies to</th>
                        <th scope="col" class="{{ $head }} text-right">Discount</th>
                        <th scope="col" class="{{ $head }} text-right">Uses</th>
                        <th scope="col" class="{{ $head }}">Expires</th>
                        <th scope="col" class="{{ $head }} text-center">State</th>
                        @if ($canUpdate || $canDelete)
                            <th scope="col" class="{{ $head }} text-center">Actions</th>
                        @endif
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @forelse ($coupons as $coupon)
                        <tr class="hover:bg-blue-50/40 align-top">
                            <td class="px-5 py-3">
                                @if ($canUpdate)
                                    <a href="{{ route('admin.coupons.edit', $coupon) }}"
                                       class="font-mono font-semibold text-blue-600 hover:underline">{{ $coupon->name }}</a>
                                @else
                                    <span class="font-mono font-semibold text-gray-900">{{ $coupon->name }}</span>
                                @endif

                                <span class="block text-xs text-gray-500 mt-0.5">
                                    {{ $coupon->designLabel() }}
                                </span>
                            </td>

                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap">
                                {{ $coupon->kindLabel() }}
                            </td>

                            <td class="px-5 py-3 text-right font-semibold text-gray-900 tabular-nums whitespace-nowrap">
                                {{ $coupon->discountLabel() }}
                            </td>

                            {{-- Used of allowed, because "3 uses" answers nothing on its
                                 own: the question is whether any are left. An unlimited
                                 coupon has no denominator, so it is said in words rather
                                 than drawn as a count against a sentinel. --}}
                            <td class="px-5 py-3 text-right text-gray-600 tabular-nums whitespace-nowrap">
                                @if ($coupon->isUnlimited())
                                    {{ number_format($coupon->redeemed_count) }}
                                    <span class="block text-xs text-gray-400">no limit</span>
                                @else
                                    {{ number_format($coupon->redeemed_count) }} / {{ number_format($coupon->quantity) }}
                                    <span class="block text-xs text-gray-400">
                                        {{ number_format(max(0, (int) $coupon->quantity - $coupon->redeemed_count)) }} uses left
                                    </span>
                                @endif
                            </td>

                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap tabular-nums">
                                {{ $coupon->expiresLabel() }}
                            </td>

                            <td class="px-5 py-3 text-center">
                                <x-admin.badge :tone="$coupon->stateTone()" :dot="true">
                                    {{ $coupon->stateLabel() }}
                                </x-admin.badge>
                            </td>

                            @if ($canUpdate || $canDelete)
                                <td class="px-5 py-3 whitespace-nowrap">
                                    <div class="flex items-center justify-center gap-1">
                                        @if ($canUpdate)
                                            <a href="{{ route('admin.coupons.edit', $coupon) }}"
                                               class="p-1.5 rounded-lg text-amber-600 hover:bg-amber-50 transition"
                                               title="Edit {{ $coupon->name }}" aria-label="Edit {{ $coupon->name }}">
                                                <x-admin.icon name="pencil" class="w-4 h-4" />
                                            </a>
                                        @endif

                                        @if ($canDelete)
                                            {{-- A coupon somebody has used is refused by the
                                                 controller, because its ledger rows carry the
                                                 figure the books rest on. --}}
                                            <form action="{{ route('admin.coupons.destroy', $coupon) }}" method="POST"
                                                  onsubmit="return confirm('Delete {{ addslashes($coupon->name) }}?\n\nThe code stops working everywhere. A coupon that has already been used cannot be deleted.');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="p-1.5 rounded-lg text-red-600 hover:bg-red-50 transition"
                                                        title="Delete {{ $coupon->name }}" aria-label="Delete {{ $coupon->name }}">
                                                    <x-admin.icon name="trash" class="w-4 h-4" />
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-12 text-center">
                                <x-admin.icon name="tag" class="w-10 h-10 mx-auto text-gray-300" />

                                <p class="text-sm font-semibold text-gray-700 mt-3">
                                    {{ $isFiltered ? 'Nothing matches those filters' : 'No coupons yet' }}
                                </p>

                                <p class="text-sm text-gray-500 mt-1 max-w-md mx-auto">
                                    @if ($isFiltered)
                                        Clear the filters to see everything.
                                    @else
                                        A coupon is created here first, then ticked on the events or shop
                                        products it applies to. Nothing is discounted until it is ticked.
                                    @endif
                                </p>

                                @if ($canCreate && ! $isFiltered)
                                    <a href="{{ route('admin.coupons.create') }}"
                                       class="inline-flex items-center gap-2 mt-5 bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition">
                                        <x-admin.icon name="plus" class="w-4 h-4" />
                                        Create the first coupon
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-5 py-3.5 border-t border-gray-200">
            @if ($coupons->hasPages())
                {{ $coupons->links() }}
            @else
                <p class="text-xs text-gray-500">
                    Showing {{ $coupons->count() }} {{ Str::plural('coupon', $coupons->count()) }}
                </p>
            @endif
        </div>

    </x-admin.page-card>
@endsection
