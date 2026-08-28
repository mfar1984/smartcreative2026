@extends('layouts.admin')

@php
    $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
    $select = 'rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';

    $tones = [
        App\Models\ShopOrder::STATUS_PENDING_PAYMENT => 'amber',
        App\Models\ShopOrder::STATUS_PAID => 'blue',
        App\Models\ShopOrder::STATUS_PACKING => 'purple',
        App\Models\ShopOrder::STATUS_SHIPPED => 'blue',
        App\Models\ShopOrder::STATUS_DELIVERED => 'green',
        App\Models\ShopOrder::STATUS_CANCELLED => 'gray',
        App\Models\ShopOrder::STATUS_REFUNDED => 'red',
    ];
@endphp

@section('title', 'Orders')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Shop</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Orders</span>
@endsection

@section('content')
    <x-admin.page-card
        title="Orders"
        description="Everything bought through the shop. Cash on delivery and bank transfers have to be confirmed by hand."
        :flush="true">

        <x-admin.filter-bar
            :action="route('admin.shop.orders')"
            :reset="$isFiltered ? route('admin.shop.orders') : null">

            <div class="relative flex-1 min-w-56">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                    <x-admin.icon name="search" class="w-4 h-4" />
                </span>
                <label for="q" class="sr-only">Search orders</label>
                <input type="search" id="q" name="q" value="{{ $search }}"
                       placeholder="Reference, name, email, phone or tracking..."
                       class="w-full rounded-lg border border-gray-300 pl-9 pr-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
            </div>

            <label for="status" class="sr-only">Status</label>
            <select id="status" name="status" class="{{ $select }}">
                <option value="">All statuses</option>
                @foreach ($statuses as $slug => $text)
                    <option value="{{ $slug }}" @selected($status === $slug)>{{ $text }}</option>
                @endforeach
            </select>

            <label for="method" class="sr-only">Payment method</label>
            <select id="method" name="method" class="{{ $select }}">
                <option value="">Any method</option>
                @foreach ($methods as $slug => $text)
                    <option value="{{ $slug }}" @selected($method === $slug)>{{ $text }}</option>
                @endforeach
            </select>
        </x-admin.filter-bar>

        {{-- The two figures somebody opening this screen is looking for. --}}
        <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-2.5 bg-gray-50 border-b border-gray-200">
            <p class="text-xs text-gray-500">
                {{ $orders->total() }} {{ Str::plural('order', $orders->total()) }} in total
            </p>
            <p class="text-xs text-gray-500">
                <span class="font-semibold text-amber-700">{{ $awaitingPayment }}</span> awaiting payment
                <span class="mx-1.5 text-gray-300" aria-hidden="true">|</span>
                <span class="font-semibold text-blue-700">{{ $openCount }}</span> owe a parcel
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left">
                    <tr>
                        <th scope="col" class="{{ $head }}">Reference</th>
                        <th scope="col" class="{{ $head }}">Customer</th>
                        <th scope="col" class="{{ $head }}">Destination</th>
                        <th scope="col" class="{{ $head }} text-center">Items</th>
                        <th scope="col" class="{{ $head }} text-right">Total</th>
                        <th scope="col" class="{{ $head }}">Method</th>
                        <th scope="col" class="{{ $head }} text-center">Status</th>
                        <th scope="col" class="{{ $head }}">Placed</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @forelse ($orders as $order)
                        <tr class="hover:bg-blue-50/40 align-top">
                            <td class="px-5 py-3 whitespace-nowrap">
                                <a href="{{ route('admin.shop.orders.show', $order) }}"
                                   class="font-semibold text-blue-600 hover:underline tabular-nums">{{ $order->reference }}</a>

                                @if ($order->isRefunded())
                                    <span class="block mt-1">
                                        <x-admin.badge tone="red">Refunded</x-admin.badge>
                                    </span>
                                @endif
                            </td>

                            <td class="px-5 py-3">
                                <span class="font-semibold text-gray-900">{{ $order->customer_name }}</span>
                                <span class="block text-xs text-gray-500">{{ $order->customer_phone }}</span>
                            </td>

                            <td class="px-5 py-3">
                                <span class="text-gray-700">{{ $order->city }}</span>
                                <span class="block text-xs text-gray-500">{{ $order->state }}</span>
                            </td>

                            <td class="px-5 py-3 text-center tabular-nums text-gray-600">
                                {{ $order->items_count }}
                            </td>

                            <td class="px-5 py-3 text-right whitespace-nowrap tabular-nums font-semibold text-gray-900">
                                {{ $order->grandTotalLabel() }}
                            </td>

                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap">
                                {{ $order->methodLabel() }}
                            </td>

                            <td class="px-5 py-3 text-center">
                                <x-admin.badge :tone="$tones[$order->status] ?? 'gray'" :dot="true">
                                    {{ $order->statusLabel() }}
                                </x-admin.badge>
                            </td>

                            <td class="px-5 py-3 whitespace-nowrap text-gray-600">
                                {{ $order->created_at->format('d M Y') }}
                                <span class="block text-xs text-gray-400">{{ $order->created_at->format('g:i a') }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-5 py-12 text-center">
                                <x-admin.icon name="bag" class="w-10 h-10 mx-auto text-gray-300" />

                                <p class="text-sm font-semibold text-gray-700 mt-3">
                                    {{ $isFiltered ? 'Nothing matches those filters' : 'No orders yet' }}
                                </p>

                                <p class="text-sm text-gray-500 mt-1 max-w-md mx-auto">
                                    @if ($isFiltered)
                                        Clear the filters to see everything.
                                    @else
                                        Orders appear here as soon as somebody checks out. The shop has to be
                                        open and a payment method switched on before anybody can.
                                    @endif
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-5 py-3.5 border-t border-gray-200">
            @if ($orders->hasPages())
                {{ $orders->links() }}
            @else
                <p class="text-xs text-gray-500">
                    Showing {{ $orders->count() }} {{ Str::plural('order', $orders->count()) }}
                </p>
            @endif
        </div>

    </x-admin.page-card>
@endsection
