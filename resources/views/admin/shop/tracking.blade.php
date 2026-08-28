@extends('layouts.admin')

@php
    use App\Http\Controllers\Admin\Shop\TrackingController;

    $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
    $mini = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
@endphp

@section('title', 'Tracking')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Shop</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Tracking</span>
@endsection

@section('content')
    {{-- Live courier sync is not built. Said here rather than leaving somebody to
         wonder why nothing appears by itself. --}}
    <div class="flex flex-wrap items-start gap-3 rounded-lg border border-blue-200 bg-blue-50 px-5 py-4 mb-5">
        <x-admin.icon name="warning" class="w-5 h-5 shrink-0 mt-0.5 text-blue-600" />

        <div class="flex-1 min-w-64">
            <p class="text-sm font-semibold text-blue-900">Tracking numbers are entered by hand for now</p>
            <p class="text-sm text-blue-800 mt-0.5">
                {{ $shippingSummary }}
                Booking parcels and pulling their status back from EasyParcel is the next
                piece of work; the credentials it will use are already on the Shipping tab.
            </p>
        </div>

        <a href="{{ route('admin.settings.integration', ['tab' => 'shipping']) }}"
           class="inline-flex items-center gap-2 rounded-lg border border-blue-300 bg-white px-4 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-50 transition">
            <x-admin.icon name="cog" class="w-4 h-4" />
            Shipping settings
        </a>
    </div>

    <x-admin.settings-shell
        title="Tracking"
        description="The same orders as the Orders screen, read from the delivery end: what still has to go out, and where the parcels are."
        :tabs="$tabs"
        :active-tab="$activeTab"
        route="admin.shop.tracking">

        @if (session('status'))
            <p class="mb-5 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('status') }}
            </p>
        @endif

        @if ($errors->any())
            <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3.5">
                @foreach ($errors->all() as $message)
                    <p class="text-sm text-red-800">{{ $message }}</p>
                @endforeach
            </div>
        @endif

        <form action="{{ route('admin.shop.tracking') }}" method="GET" class="flex flex-wrap items-center gap-2 mb-5">
            <input type="hidden" name="tab" value="{{ $activeTab }}">

            <div class="relative flex-1 min-w-56">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                    <x-admin.icon name="search" class="w-4 h-4" />
                </span>
                <label for="q" class="sr-only">Search orders</label>
                <input type="search" id="q" name="q" value="{{ $search }}"
                       placeholder="Reference, name, postcode or tracking number..."
                       class="w-full rounded-lg border border-gray-300 pl-9 pr-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
            </div>

            <button type="submit" class="rounded-lg bg-gray-100 px-3.5 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200 transition">
                Search
            </button>

            @if ($isFiltered)
                <a href="{{ route('admin.shop.tracking', ['tab' => $activeTab]) }}"
                   class="px-3 py-2 text-sm font-semibold text-gray-500 hover:text-gray-800 transition">
                    Reset
                </a>
            @endif
        </form>

        <div class="rounded-lg border border-gray-200 bg-white overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left">
                        <tr>
                            <th scope="col" class="{{ $head }}">Order</th>
                            <th scope="col" class="{{ $head }}">Going To</th>
                            <th scope="col" class="{{ $head }} text-right">Weight</th>
                            @if ($activeTab === 'to-send')
                                <th scope="col" class="{{ $head }}">Courier &amp; Tracking</th>
                            @else
                                <th scope="col" class="{{ $head }}">Tracking</th>
                                <th scope="col" class="{{ $head }}">Buyer Confirmed</th>
                            @endif
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        @forelse ($orders as $order)
                            <tr class="hover:bg-blue-50/40 align-top">
                                <td class="px-5 py-3 whitespace-nowrap">
                                    <a href="{{ route('admin.shop.orders.show', $order) }}"
                                       class="font-semibold text-blue-600 hover:underline tabular-nums">{{ $order->reference }}</a>

                                    <span class="block text-xs text-gray-500">{{ $order->customer_name }}</span>

                                    <span class="block text-xs text-gray-400">
                                        {{ $order->itemCount() }} {{ Str::plural('item', $order->itemCount()) }}
                                        &middot; {{ $order->methodLabel() }}
                                    </span>
                                </td>

                                <td class="px-5 py-3">
                                    <span class="text-gray-700">{{ $order->postcode }} {{ $order->city }}</span>
                                    <span class="block text-xs text-gray-500">{{ $order->state }}</span>
                                    <span class="block text-xs text-gray-400">{{ $order->customer_phone }}</span>
                                </td>

                                <td class="px-5 py-3 text-right whitespace-nowrap tabular-nums text-gray-600">
                                    @if ($order->weightGrams() > 0)
                                        {{ number_format($order->weightGrams() / 1000, 3) }} kg
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>

                                @if ($activeTab === 'to-send')
                                    {{-- Entered on the row, because filling in a tracking number
                                         and marking the parcel gone are one act at the counter. --}}
                                    <td class="px-5 py-3">
                                        @if ($canUpdate)
                                            <form action="{{ route('admin.shop.tracking.update', $order) }}" method="POST"
                                                  class="grid grid-cols-1 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] gap-2 items-start">
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="return_tab" value="in-transit">

                                                <div>
                                                    <label class="sr-only" for="courier-{{ $order->id }}">Courier</label>
                                                    <input type="text" id="courier-{{ $order->id }}" name="courier_name" maxlength="190"
                                                           value="{{ $order->courier_name }}"
                                                           placeholder="Courier" class="{{ $mini }}">
                                                </div>

                                                <div>
                                                    <label class="sr-only" for="tracking-{{ $order->id }}">Tracking number</label>
                                                    <input type="text" id="tracking-{{ $order->id }}" name="tracking_number" required maxlength="190"
                                                           value="{{ $order->tracking_number }}"
                                                           placeholder="Tracking number" class="{{ $mini }} tabular-nums">
                                                </div>

                                                <button type="submit"
                                                        class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-blue-700 transition whitespace-nowrap">
                                                    Mark sent
                                                </button>
                                            </form>
                                        @else
                                            <span class="text-xs text-gray-400">You cannot change these.</span>
                                        @endif
                                    </td>
                                @else
                                    <td class="px-5 py-3">
                                        @if (filled($order->tracking_number))
                                            <span class="block font-semibold text-gray-900 tabular-nums">{{ $order->tracking_number }}</span>
                                        @endif

                                        @if (filled($order->courier_name))
                                            <span class="block text-xs text-gray-500">{{ $order->courier_name }}</span>
                                        @endif

                                        @if ($order->shipped_at)
                                            <span class="block text-xs text-gray-400">
                                                Sent {{ $order->shipped_at->format('d M Y') }}
                                            </span>
                                        @endif

                                        @if (filled($order->tracking_url))
                                            <a href="{{ $order->tracking_url }}" target="_blank" rel="noopener"
                                               class="inline-flex items-center gap-1 text-xs font-semibold text-blue-600 hover:underline mt-0.5">
                                                Track
                                                <x-admin.icon name="eye" class="w-3 h-3" />
                                            </a>
                                        @endif
                                    </td>

                                    <td class="px-5 py-3">
                                        @if ($order->isReceiptConfirmed())
                                            <x-admin.badge tone="green" :dot="true">Confirmed</x-admin.badge>
                                            <span class="block text-xs text-gray-400 mt-1">
                                                {{ $order->received_confirmed_at->format('d M Y') }}
                                            </span>
                                        @else
                                            <x-admin.badge tone="gray">Not yet</x-admin.badge>

                                            {{-- The signed link the buyer presses. Shown so it can be
                                                 copied into a message until the email is wired. --}}
                                            <input type="text" readonly
                                                   value="{{ TrackingController::receiptLink($order) }}"
                                                   onclick="this.select()"
                                                   class="mt-1.5 w-full max-w-xs rounded border border-gray-200 bg-gray-50 px-2 py-1 text-xs text-gray-500"
                                                   aria-label="Confirmation link for {{ $order->reference }}">
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $activeTab === 'to-send' ? 4 : 5 }}" class="px-5 py-12 text-center">
                                    <x-admin.icon name="archive" class="w-10 h-10 mx-auto text-gray-300" />

                                    <p class="text-sm font-semibold text-gray-700 mt-3">
                                        {{ $isFiltered ? 'Nothing matches that search' : 'Nothing here' }}
                                    </p>

                                    <p class="text-sm text-gray-500 mt-1 max-w-md mx-auto">
                                        @if ($isFiltered)
                                            Clear the search to see everything on this tab.
                                        @elseif ($activeTab === 'to-send')
                                            No paid orders are waiting to go out. Orders appear here once payment
                                            is confirmed.
                                        @elseif ($activeTab === 'in-transit')
                                            Nothing is on the road. Orders move here when you mark them sent.
                                        @else
                                            Nothing has been delivered yet.
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
        </div>

    </x-admin.settings-shell>
@endsection
