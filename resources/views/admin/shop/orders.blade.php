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

        {{-- Two kinds of order. Kept apart because handling them shares almost
             nothing: one has a courier and a tracking number, the other has a counter,
             a date and somebody's identity card. --}}
        <div class="flex flex-wrap gap-1 px-6 pt-4 border-b border-gray-200 bg-white">
            @foreach ($tabs as $slug => $tab)
                <a href="{{ route('admin.shop.orders', ['tab' => $slug]) }}"
                   @class([
                       'inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px transition',
                       'border-blue-600 text-blue-700' => $activeTab === $slug,
                       'border-transparent text-gray-500 hover:text-gray-800' => $activeTab !== $slug,
                   ])
                   @if ($activeTab === $slug) aria-current="page" @endif>
                    {{ $tab['label'] }}
                    <span @class([
                        'rounded-full px-2 py-0.5 text-xs font-bold',
                        'bg-blue-100 text-blue-800' => $activeTab === $slug,
                        'bg-gray-100 text-gray-600' => $activeTab !== $slug,
                    ])>{{ $tab['count'] }}</span>
                </a>
            @endforeach
        </div>

        <div class="px-6 py-3 border-b border-gray-200 bg-gray-50">
            <p class="text-sm text-gray-600">
                @if ($isOffline)
                    Bought here and collected in person at a counter. Nothing is posted, so there is
                    no courier and no postage. The buyer brings the identity card on the order, which
                    is what verifies them before you hand anything over.
                @else
                    Posted to the buyer. Postage comes from the flat rates in Settings &gt; Integration
                    &gt; Shipping, banded by the delivery state.
                @endif
            </p>
        </div>

        <x-admin.filter-bar
            :action="route('admin.shop.orders')"
            :reset="$isFiltered ? route('admin.shop.orders', ['tab' => $activeTab]) : null">

            {{-- Carries the tab through the filter form, otherwise searching would
                 silently drop the operator back onto the Online list. --}}
            <input type="hidden" name="tab" value="{{ $activeTab }}">

            <div class="relative flex-1 min-w-56">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                    <x-admin.icon name="search" class="w-4 h-4" />
                </span>
                <label for="q" class="sr-only">Search orders</label>
                <input type="search" id="q" name="q" value="{{ $search }}"
                       placeholder="{{ $isOffline ? 'Reference, name, email, phone or identity card...' : 'Reference, name, email, phone or tracking...' }}"
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

        {{-- The two figures somebody opening this screen is looking for, and the one
             action that applies to all of them at once. --}}
        <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-2.5 bg-gray-50 border-b border-gray-200">
            <p class="text-xs text-gray-500">
                {{ $orders->total() }} {{ Str::plural('order', $orders->total()) }} on this tab
            </p>

            <div class="flex flex-wrap items-center gap-3">
                <p class="text-xs text-gray-500">
                    <span class="font-semibold text-amber-700">{{ $awaitingPayment }}</span> awaiting payment
                    @if ($awaitingReceiptCheck > 0)
                        <span class="mx-1.5 text-gray-300" aria-hidden="true">|</span>
                        <span class="font-semibold text-amber-700">{{ $awaitingReceiptCheck }}</span>
                        {{ Str::plural('receipt', $awaitingReceiptCheck) }} to check
                    @endif
                    <span class="mx-1.5 text-gray-300" aria-hidden="true">|</span>
                    <span class="font-semibold text-blue-700">{{ $openCount }}</span>
                    {{ $isOffline ? 'waiting to be collected' : 'owe a parcel' }}
                </p>

                {{-- Chase everybody on the list in one press, instead of twenty-two
                     envelopes.

                     It sits here, directly under the filters and above the table,
                     because what it sends is decided by those filters: the tab, the
                     status, the method and the search box all travel with it in the
                     hidden fields below, and the count on the label is counted through
                     the same clauses. Below the filters it reads as "and to these".

                     Hidden from the start when there is nobody to chase, rather than
                     disabled: a dead button on a screen with twenty-two amber rows
                     invites a second and a third press. --}}
                @if ($canNotify && $remindableCount > 0)
                    <form action="{{ route('admin.shop.orders.payment-links') }}" method="POST"
                          onsubmit="return confirm('Email a payment link to {{ $remindableCount }} {{ Str::plural('buyer', $remindableCount) }} on this list?\n\nOnly orders still waiting for an online payment are emailed. Anything already paid, cancelled, refunded, settled by hand or reminded in the last {{ App\Models\ShopOrder::PAYMENT_LINK_COOLDOWN_HOURS }} hours is skipped.\n\nNothing is marked paid by this.');">
                        @csrf

                        {{-- The filters in force, posted back so the server narrows to
                             the same set that is on screen. The server re-reads them
                             through the same filter reader the table used and decides
                             eligibility and the amount from the order rows, never from
                             these. --}}
                        <input type="hidden" name="tab" value="{{ $activeTab }}">
                        <input type="hidden" name="q" value="{{ $search }}">
                        <input type="hidden" name="status" value="{{ $status }}">
                        <input type="hidden" name="method" value="{{ $method }}">

                        <button type="submit"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700 transition shadow-sm">
                            {{-- The same envelope as the per-row icon: the same act,
                                 done to everybody at once. --}}
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                            Send payment link to all {{ $remindableCount }}
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left">
                    <tr>
                        <th scope="col" class="{{ $head }}">Reference</th>
                        <th scope="col" class="{{ $head }}">Customer</th>
                        <th scope="col" class="{{ $head }}">{{ $isOffline ? 'Collect At' : 'Destination' }}</th>
                        <th scope="col" class="{{ $head }} text-center">Items</th>
                        <th scope="col" class="{{ $head }} text-right">Total</th>
                        <th scope="col" class="{{ $head }}">Method</th>
                        <th scope="col" class="{{ $head }} text-center">Status</th>
                        <th scope="col" class="{{ $head }}">Placed</th>
                        @if ($isOffline)
                            <th scope="col" class="{{ $head }} text-right">Hand Over</th>
                        @endif
                        <th scope="col" class="{{ $head }} text-center">Actions</th>
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

                                @if ($isOffline && filled($order->identity_card))
                                    {{-- The number the counter checks, on the list so it can be
                                         read against the document without opening the order. --}}
                                    <span class="block text-xs text-gray-500 tabular-nums mt-0.5">
                                        IC {{ $order->identity_card }}
                                    </span>
                                @endif
                            </td>

                            <td class="px-5 py-3">
                                @if ($isOffline)
                                    <span class="text-gray-700">{{ $order->collection_location ?: ($order->collection_label ?: 'Not recorded') }}</span>
                                    @if ($order->collection_at)
                                        <span class="block text-xs text-gray-500">
                                            {{ $order->collection_at->format('d M Y, g:i a') }}
                                        </span>
                                    @endif
                                @else
                                    <span class="text-gray-700">{{ $order->city }}</span>
                                    <span class="block text-xs text-gray-500">{{ $order->state }}</span>
                                @endif
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

                            @if ($isOffline)
                                {{-- What has happened to the goods, and nothing else.

                                     It used to render the hand-over BUTTON here, a
                                     green tick labelled Delivered, which is the
                                     destination of the press rather than the state of
                                     the order. On a paid order waiting for an event two
                                     weeks away that read as "already delivered" while
                                     the counter above said one waiting to be collected,
                                     and the two appeared to contradict each other. The
                                     state was always right; the cell was describing a
                                     control. The control now lives in Actions, where
                                     every other action on this table already is.

                                     data-hand-over so the regression test can assert on
                                     this cell rather than on the page, which also
                                     carries the word Delivered in the status filter. --}}
                                <td class="px-5 py-3 text-right whitespace-nowrap" data-hand-over="{{ $order->reference }}">
                                    @if ($order->isCollected())
                                        <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-green-700">
                                            <x-admin.icon name="check" class="w-3.5 h-3.5" />
                                            Collected {{ \App\Support\LocalTime::format($order->delivered_at, 'd M Y', '') }}
                                        </span>
                                    @elseif ($order->awaitsCollection())
                                        {{-- Paid, still with us. The same thing the
                                             counter at the top of this screen counts. --}}
                                        <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-700"
                                              title="Paid on {{ \App\Support\LocalTime::format($order->paid_at) }}. Not handed over yet.">
                                            <x-admin.icon name="archive" class="w-3.5 h-3.5" />
                                            Awaiting collection
                                        </span>
                                    @else
                                        <span class="text-xs text-gray-400">
                                            {{ $order->isPendingPayment() ? 'Not paid yet' : '—' }}
                                        </span>
                                    @endif
                                </td>
                            @endif

                            {{-- Actions. On both tabs, deliberately: restricting the
                                 payment link to Offline would re-introduce the
                                 fulfilment-drives-payment coupling this change exists
                                 to remove. A posted order paid by card gets stuck in
                                 exactly the same way.

                                 Escaping rule, the one the participants screen already
                                 uses: values a buyer typed go through addslashes(),
                                 values this application generated are interpolated
                                 directly. A confirm() that throws does not return
                                 false, so an apostrophe in a name would submit the form
                                 with no confirmation at all. --}}
                            <td class="px-5 py-3 whitespace-nowrap">
                                <div class="flex items-center justify-center gap-0.5">
                                    {{-- Only while there is something to collect, and
                                         only for the method the gateway can actually
                                         take. A cash on delivery or bank transfer order
                                         is settled by hand, so a link would send the
                                         buyer to a page that refuses itself. --}}
                                    @if ($canNotify && $order->awaitsGatewayPayment() && $order->paymentLinkRemindedRecently())
                                        {{-- Already chased. The same cooldown the
                                             server enforces, shown rather than hidden,
                                             so the answer to "did anybody email this
                                             one" is on the row instead of behind a
                                             press that gets refused. --}}
                                        <span class="inline-flex items-center gap-1 px-1.5 text-xs text-gray-400"
                                              title="A payment link was queued {{ $order->payment_link_sent_at->diffForHumans() }}. It can be sent again after {{ $order->paymentLinkCooldownEndsAt()?->format('g:i a, d M') }}.">
                                            <x-admin.icon name="check" class="w-3.5 h-3.5" />
                                            Sent
                                        </span>
                                    @elseif ($canNotify && $order->awaitsGatewayPayment())
                                        <form action="{{ route('admin.shop.orders.payment-link', $order) }}" method="POST"
                                              onsubmit="return confirm('Email a payment link for {{ $order->reference }} to {{ addslashes($order->customer_email) }}?\n\nThey will be asked to pay {{ $order->grandTotalLabel() }} by card or online banking. Nothing is marked paid until the money actually arrives.');">
                                            @csrf
                                            <button type="submit"
                                                    class="p-1.5 rounded-lg text-amber-600 hover:bg-amber-50 transition"
                                                    title="Send a payment link for {{ $order->reference }}"
                                                    aria-label="Send a payment link for {{ $order->reference }}">
                                                {{-- The same envelope and the same amber
                                                     as the participant payment reminder:
                                                     the same act should look the same in
                                                     both modules. --}}
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                                </svg>
                                            </button>
                                        </form>
                                    @endif

                                    {{-- Handing it over at the counter. Offered only on
                                         an order that is actually ready for it: offline,
                                         paid, and not already collected, cancelled or
                                         refunded. awaitsCollection() answers all of
                                         that, and the route re-asks it server-side.

                                         A dialog rather than confirm(), because this
                                         one asserts that goods left the building and the
                                         person pressing it should see which order,
                                         whose identity card to check and where it is
                                         being collected before they do. --}}
                                    @if ($canUpdate && $order->awaitsCollection())
                                        <button type="button" data-open-dialog="collect-{{ $order->id }}"
                                                class="p-1.5 rounded-lg text-green-600 hover:bg-green-50 transition"
                                                title="Confirm collection of {{ $order->reference }}"
                                                aria-label="Confirm collection of {{ $order->reference }}">
                                            <x-admin.icon name="check" class="w-4 h-4" />
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $isOffline ? 10 : 9 }}" class="px-5 py-12 text-center">
                                <x-admin.icon name="bag" class="w-10 h-10 mx-auto text-gray-300" />

                                <p class="text-sm font-semibold text-gray-700 mt-3">
                                    {{ $isFiltered ? 'Nothing matches those filters' : 'No orders yet' }}
                                </p>

                                <p class="text-sm text-gray-500 mt-1 max-w-md mx-auto">
                                    @if ($isFiltered)
                                        Clear the filters to see everything.
                                    @else
                                        @if ($isOffline)
                                            Nothing has been bought for collection yet. A product only lands here
                                            when its fulfilment is set to Offline, which is on the product itself
                                            under How It Reaches The Buyer.
                                        @else
                                            Orders appear here as soon as somebody checks out. The shop has to be
                                            open and a payment method switched on before anybody can.
                                        @endif
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

    {{-- ===================== Confirm collection =====================

         One dialog per row that can be handed over, declared outside the table
         because a div is not valid inside a tbody. Same hand-written pattern as
         Settings > Users: a hidden div with role=dialog, a backdrop that closes it,
         and the small script below. No library.

         The form carries nothing but the CSRF token and a note. Which order and which
         status are decided by the route binding and by the controller, so a body
         edited in the browser has nothing to aim. --}}
    @if ($canUpdate)
        @foreach ($orders as $order)
            @continue (! $order->awaitsCollection())

            <div id="collect-{{ $order->id }}" class="hidden fixed inset-0 z-50 overflow-y-auto"
                 role="dialog" aria-modal="true" aria-labelledby="collect-title-{{ $order->id }}">
                <div class="fixed inset-0 bg-gray-900/50" data-close-dialog></div>

                <div class="relative min-h-full flex items-start justify-center p-4">
                    <div class="relative w-full max-w-md bg-white rounded-xl shadow-xl my-8">
                        <div class="flex items-center justify-between gap-4 px-6 py-4 border-b border-gray-200">
                            <h2 id="collect-title-{{ $order->id }}" class="text-base font-bold text-gray-900">
                                Confirm Collection
                            </h2>
                            <button type="button" data-close-dialog
                                    class="p-1 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition"
                                    aria-label="Close">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>

                        <form action="{{ route('admin.shop.orders.collect', $order) }}" method="POST" class="p-6 space-y-4">
                            @csrf

                            <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm space-y-1">
                                <p class="font-semibold text-gray-900 tabular-nums">{{ $order->reference }}</p>
                                <p class="text-gray-700">{{ $order->customer_name }}</p>
                                <p class="text-gray-600 tabular-nums">
                                    IC to check: {{ $order->identity_card ?: 'Not recorded' }}
                                </p>
                                <p class="text-gray-600">
                                    {{ $order->items_count }} {{ Str::plural('item', $order->items_count) }},
                                    {{ $order->grandTotalLabel() }} paid
                                </p>
                                @if (filled($order->collection_location) || filled($order->collection_label))
                                    <p class="text-gray-600">
                                        {{ $order->collection_location ?: $order->collection_label }}
                                    </p>
                                @endif
                            </div>

                            <p class="text-sm text-gray-600">
                                Check the identity card against the number above before you press this.
                                It records the goods as handed over, against your name, and it cannot be
                                undone from here.
                            </p>

                            <div>
                                <label for="collect-note-{{ $order->id }}" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                    Note
                                </label>
                                <input type="text" id="collect-note-{{ $order->id }}" name="note" maxlength="255"
                                       placeholder="Who picked it up, if it was not the buyer"
                                       class="w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                                <p class="text-xs text-gray-500 mt-1">
                                    Optional. Goes on the order history next to your name.
                                </p>
                            </div>

                            <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                                <button type="button" data-close-dialog
                                        class="px-4 py-2.5 rounded-lg border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                                    Cancel
                                </button>
                                <button type="submit"
                                        class="inline-flex items-center gap-2 bg-green-600 text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:bg-green-700 transition shadow-sm">
                                    <x-admin.icon name="check" class="w-4 h-4" />
                                    Collected
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach
    @endif
@endsection

@push('scripts')
<script>
    (function () {
        function closeAll() {
            document.querySelectorAll('[role="dialog"]').forEach(function (dialog) {
                dialog.classList.add('hidden');
            });
            document.body.classList.remove('overflow-hidden');
        }

        document.querySelectorAll('[data-open-dialog]').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                const dialog = document.getElementById(trigger.dataset.openDialog);

                if (!dialog) {
                    return;
                }

                closeAll();
                dialog.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
                dialog.querySelector('input:not([type="hidden"]):not(.sr-only)')?.focus();
            });
        });

        document.querySelectorAll('[data-close-dialog]').forEach(function (trigger) {
            trigger.addEventListener('click', closeAll);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeAll();
            }
        });
    })();
</script>
@endpush
