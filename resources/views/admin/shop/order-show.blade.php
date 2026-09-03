@extends('layouts.admin')

@php
    use App\Models\ShopOrder;

    $input = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';

    $tones = [
        ShopOrder::STATUS_PENDING_PAYMENT => 'amber',
        ShopOrder::STATUS_PAID => 'blue',
        ShopOrder::STATUS_PACKING => 'purple',
        ShopOrder::STATUS_SHIPPED => 'blue',
        ShopOrder::STATUS_DELIVERED => 'green',
        ShopOrder::STATUS_CANCELLED => 'gray',
        ShopOrder::STATUS_REFUNDED => 'red',
    ];

    /*
     | Paid is not offered here. It has its own form and its own permission, because
     | pressing it asserts money was received and takes the stock off.
     */
    $moves = collect($transitions)->except(ShopOrder::STATUS_PAID);
@endphp

@section('title', 'Order ' . $order->reference)

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Shop</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.shop.orders') }}" class="hover:text-gray-700 transition">Orders</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $order->reference }}</span>
@endsection

@section('content')
    <x-admin.page-card
        :title="'Order ' . $order->reference"
        :description="$order->created_at->format('d M Y, g:i a') . ' · ' . $order->methodLabel()"
        :back="route('admin.shop.orders')">

        <x-slot:actions>
            <x-admin.badge :tone="$tones[$order->status] ?? 'gray'" :dot="true">
                {{ $order->statusLabel() }}
            </x-admin.badge>
        </x-slot:actions>

        {{-- ==================== Money waiting ==================== --}}
        @if ($order->awaitsManualPayment())
            <div class="flex flex-wrap items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-5 py-4 mb-5">
                <x-admin.icon name="warning" class="w-5 h-5 shrink-0 mt-0.5 text-amber-600" />

                <div class="flex-1 min-w-64">
                    <p class="text-sm font-semibold text-amber-900">
                        {{ $order->grandTotalLabel() }} is outstanding, paid by {{ Str::lower($order->methodLabel()) }}
                    </p>
                    <p class="text-sm text-amber-800 mt-0.5">
                        Nothing about this order can be observed from here, so somebody has to confirm
                        the money arrived. Confirming also takes the stock off.
                    </p>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

            {{-- ==================== The order ==================== --}}
            <div class="lg:col-span-2 space-y-5">

                <x-admin.panel title="Items" icon="bag" :flush="true">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 text-left">
                                <tr>
                                    <th scope="col" class="px-5 py-2.5 text-xs font-bold uppercase tracking-wide text-gray-500">Item</th>
                                    <th scope="col" class="px-5 py-2.5 text-xs font-bold uppercase tracking-wide text-gray-500">SKU</th>
                                    <th scope="col" class="px-5 py-2.5 text-xs font-bold uppercase tracking-wide text-gray-500 text-right">Unit</th>
                                    <th scope="col" class="px-5 py-2.5 text-xs font-bold uppercase tracking-wide text-gray-500 text-center">Qty</th>
                                    <th scope="col" class="px-5 py-2.5 text-xs font-bold uppercase tracking-wide text-gray-500 text-right">Total</th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-gray-100">
                                @foreach ($order->items as $item)
                                    <tr>
                                        <td class="px-5 py-3">
                                            @if ($item->product)
                                                <a href="{{ route('admin.shop.products.edit', $item->product) }}"
                                                   class="font-semibold text-blue-600 hover:underline">{{ $item->name }}</a>
                                            @else
                                                {{-- The catalogue entry is gone; the line is the record now. --}}
                                                <span class="font-semibold text-gray-900">{{ $item->name }}</span>
                                                <span class="block text-xs text-gray-400">No longer in the catalogue</span>
                                            @endif

                                            @if (filled($item->variant_label))
                                                <span class="block text-xs text-gray-500">{{ $item->variant_label }}</span>
                                            @endif
                                        </td>

                                        <td class="px-5 py-3">
                                            <code class="text-xs text-gray-500">{{ $item->sku ?: '—' }}</code>
                                        </td>

                                        <td class="px-5 py-3 text-right tabular-nums text-gray-700">{{ $item->unitPriceLabel() }}</td>
                                        <td class="px-5 py-3 text-center tabular-nums text-gray-700">{{ $item->quantity }}</td>
                                        <td class="px-5 py-3 text-right tabular-nums font-semibold text-gray-900">{{ $item->lineTotalLabel() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>

                            <tfoot class="bg-gray-50 border-t border-gray-200">
                                <tr>
                                    <td colspan="4" class="px-5 py-2 text-right text-gray-600">Goods</td>
                                    <td class="px-5 py-2 text-right tabular-nums font-semibold text-gray-900">{{ $order->itemsTotalLabel() }}</td>
                                </tr>
                                <tr>
                                    <td colspan="4" class="px-5 py-2 text-right text-gray-600">
                                        Delivery
                                        @if (filled($order->shipping_label))
                                            <span class="text-xs text-gray-500">({{ $order->shipping_label }})</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-2 text-right tabular-nums font-semibold text-gray-900">{{ $order->shippingTotalLabel() }}</td>
                                </tr>
                                <tr class="border-t border-gray-200">
                                    <td colspan="4" class="px-5 py-3 text-right font-bold text-gray-900">Total</td>
                                    <td class="px-5 py-3 text-right tabular-nums font-bold text-gray-900">{{ $order->grandTotalLabel() }}</td>
                                </tr>

                                @if ($order->isRefunded())
                                    <tr>
                                        <td colspan="4" class="px-5 py-2 text-right text-red-700">Refunded</td>
                                        <td class="px-5 py-2 text-right tabular-nums font-semibold text-red-700">
                                            -{{ App\Support\PaymentFigures::money((float) $order->refunded_amount) }}
                                        </td>
                                    </tr>
                                @endif
                            </tfoot>
                        </table>
                    </div>
                </x-admin.panel>

                {{-- ==================== Moving it along ==================== --}}
                @if ($canUpdate && $moves->isNotEmpty())
                    <x-admin.panel title="Move This Order Along" icon="activity">
                        <form action="{{ route('admin.shop.orders.status', $order) }}" method="POST" class="px-5 py-4 space-y-4">
                            @csrf
                            @method('PUT')

                            @error('status')
                                <p class="text-sm text-red-600">{{ $message }}</p>
                            @enderror

                            <x-admin.field-row label="Move To" for="status" :required="true" error="status"
                                               help="Only the moves this order can actually make are listed.">
                                <select id="status" name="status" required class="{{ $input }} max-w-xs bg-white">
                                    @foreach ($moves as $slug => $text)
                                        <option value="{{ $slug }}">{{ $text }}</option>
                                    @endforeach
                                </select>
                            </x-admin.field-row>

                            {{-- Tracking is asked for here rather than on its own screen,
                                 because the moment it is known is the moment somebody marks
                                 the parcel shipped. --}}
                            <x-admin.field-row label="Courier" for="courier_name" error="courier_name"
                                               help="Fill these in when marking it shipped. Left alone otherwise.">
                                <input type="text" id="courier_name" name="courier_name" maxlength="190"
                                       value="{{ old('courier_name', $order->courier_name) }}"
                                       placeholder="e.g. J&T Express" class="{{ $input }} max-w-sm">
                            </x-admin.field-row>

                            <x-admin.field-row label="Tracking Number" for="tracking_number" error="tracking_number">
                                <input type="text" id="tracking_number" name="tracking_number" maxlength="190"
                                       value="{{ old('tracking_number', $order->tracking_number) }}"
                                       class="{{ $input }} max-w-sm tabular-nums">
                            </x-admin.field-row>

                            <x-admin.field-row label="Tracking Link" for="tracking_url" error="tracking_url"
                                               help="The courier's own page for this parcel, if you have it.">
                                <input type="url" id="tracking_url" name="tracking_url" maxlength="500"
                                       value="{{ old('tracking_url', $order->tracking_url) }}"
                                       class="{{ $input }}">
                            </x-admin.field-row>

                            <x-admin.field-row label="Note" for="note" error="note"
                                               help="Added to the history below. Optional.">
                                <input type="text" id="note" name="note" maxlength="255" value="{{ old('note') }}"
                                       class="{{ $input }}">
                            </x-admin.field-row>

                            <div class="flex justify-end">
                                <button type="submit"
                                        class="inline-flex items-center gap-2 bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                                    Save
                                </button>
                            </div>
                        </form>
                    </x-admin.panel>
                @elseif ($order->isClosed())
                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4">
                        <p class="text-sm text-gray-600">
                            This order is {{ Str::lower($order->statusLabel()) }} and nothing further happens to
                            it. Reopening one would let it come back to life without anybody deciding so.
                        </p>
                    </div>
                @endif

                {{-- ==================== History ==================== --}}
                <x-admin.panel title="History" icon="activity" :flush="true">
                    <ol class="divide-y divide-gray-100">
                        @forelse ($order->events as $event)
                            <li class="flex flex-wrap items-start justify-between gap-3 px-5 py-3">
                                <div class="min-w-0">
                                    @if ($event->statusLabel())
                                        <span class="text-sm font-semibold text-gray-900">{{ $event->statusLabel() }}</span>
                                    @else
                                        <span class="text-sm font-semibold text-gray-700">Note</span>
                                    @endif

                                    @if (filled($event->note))
                                        <span class="block text-sm text-gray-600 mt-0.5">{{ $event->note }}</span>
                                    @endif

                                    <span class="block text-xs text-gray-400 mt-0.5">{{ $event->actor() }}</span>
                                </div>

                                <span class="text-xs text-gray-500 whitespace-nowrap tabular-nums">
                                    {{ $event->created_at->format('d M Y, g:i a') }}
                                </span>
                            </li>
                        @empty
                            <li class="px-5 py-6 text-sm text-gray-500">Nothing recorded yet.</li>
                        @endforelse
                    </ol>
                </x-admin.panel>
            </div>

            {{-- ==================== Sidebar ==================== --}}
            <div class="space-y-5">

                {{--
                    Proof of a bank transfer, above Confirm Payment on purpose: it is what
                    somebody reads before deciding, so it comes before the button that
                    asserts the money arrived.
                --}}
                @if ($order->payment_method === ShopOrder::METHOD_BANK_TRANSFER)
                    <x-admin.panel title="Payment Receipt" icon="clipboard">
                        <div class="px-5 py-4">
                            @if ($order->hasPaymentReceipt())
                                <p class="text-xs text-gray-500">
                                    Sent by the buyer {{ $order->payment_receipt_uploaded_at?->format('d M Y, g:i a') }}
                                </p>

                                @if ($order->paymentReceiptIsImage())
                                    {{-- Shown inline so a transfer can be checked without
                                         downloading a file first. --}}
                                    <a href="{{ $order->paymentReceiptUrl() }}" target="_blank" rel="noopener"
                                       class="block mt-3 rounded-lg border border-gray-200 overflow-hidden hover:border-blue-400 transition">
                                        <img src="{{ $order->paymentReceiptUrl() }}"
                                             alt="Payment receipt for order {{ $order->reference }}"
                                             class="w-full h-auto">
                                    </a>
                                @endif

                                <a href="{{ $order->paymentReceiptUrl() }}" target="_blank" rel="noopener"
                                   class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-blue-600 hover:text-blue-700 hover:underline">
                                    <x-admin.icon name="eye" class="w-4 h-4" />
                                    Open the full receipt
                                </a>

                                @unless ($order->isPaid())
                                    <p role="status" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-2.5 text-xs text-amber-800 leading-relaxed">
                                        A receipt is not a payment. Check the amount and the date against the
                                        bank account before confirming this order.
                                    </p>
                                @endunless
                            @else
                                <p class="text-sm text-gray-500">
                                    The buyer has not sent a receipt yet.
                                </p>

                                <p class="text-xs text-gray-500 mt-2 leading-relaxed">
                                    They were emailed the account details and a link to upload one when the
                                    order was placed. Nothing stops you confirming this order without it, if
                                    you can see the money in the account.
                                </p>
                            @endif
                        </div>
                    </x-admin.panel>
                @endif

                @if ($canConfirmPayment && $order->canMoveTo(ShopOrder::STATUS_PAID))
                    <x-admin.panel title="Confirm Payment" icon="cash">
                        <form action="{{ route('admin.shop.orders.payment', $order) }}" method="POST"
                              onsubmit="return confirm('Mark {{ $order->reference }} as paid?\n\nThis records that {{ $order->grandTotalLabel() }} was received and takes the stock off. It cannot be undone from here.');"
                              class="px-5 py-4 space-y-4">
                            @csrf
                            @method('PUT')

                            @error('payment')
                                <p class="text-sm text-red-600">{{ $message }}</p>
                            @enderror

                            <p class="text-sm text-gray-600">
                                Only press this once the money is actually in the account or in hand.
                            </p>

                            <div>
                                <label for="payment_reference" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                    Reference
                                </label>
                                <input type="text" id="payment_reference" name="payment_reference" maxlength="190"
                                       value="{{ old('payment_reference') }}"
                                       placeholder="Transfer reference or receipt number"
                                       class="{{ $input }}">
                                <p class="text-xs text-gray-500 mt-1">Optional, but worth keeping for a bank transfer.</p>
                            </div>

                            <button type="submit"
                                    class="w-full inline-flex items-center justify-center gap-2 bg-green-600 text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:bg-green-700 transition shadow-sm">
                                <x-admin.icon name="cash" class="w-4 h-4" />
                                Mark {{ $order->grandTotalLabel() }} received
                            </button>
                        </form>
                    </x-admin.panel>
                @endif

                {{--
                    Refund. Only on a paid order with something left to return, so the
                    form never offers an action the controller would refuse.
                --}}
                @if ($canRefund && $order->isPaid() && $order->netAmount() > 0)
                    @php
                        $refundable = $order->netAmount();
                        $viaGateway = $order->payment_method === ShopOrder::METHOD_GATEWAY
                            && filled($order->payment_reference);
                    @endphp

                    <x-admin.panel title="Refund" icon="credit-card">
                        <form action="{{ route('admin.shop.orders.refund', $order) }}" method="POST"
                              onsubmit="return confirm('Refund this order?\n\nThis sends money back and cannot be undone from here.');"
                              class="px-5 py-4 space-y-4">
                            @csrf

                            @error('refund')
                                <p class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</p>
                            @enderror

                            <p class="text-sm text-gray-600">
                                @if ($viaGateway)
                                    This was paid through {{ $order->methodLabel() }}. The gateway is asked first,
                                    and nothing is recorded here unless it agrees.
                                @else
                                    This was settled by {{ $order->methodLabel() }}, so there is no gateway to call.
                                    Pressing this records a refund you are sending by hand.
                                @endif
                            </p>

                            @if ($order->refunded_amount > 0)
                                <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                    {{ $order->refundedLabel() }} has already gone back.
                                    {{ $order->netLabel() }} is left.
                                </p>
                            @endif

                            <div>
                                <label for="amount" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                    Amount <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-500">RM</span>
                                    <input type="number" id="amount" name="amount" required
                                           step="0.01" min="0.01" max="{{ number_format($refundable, 2, '.', '') }}"
                                           value="{{ old('amount', number_format($refundable, 2, '.', '')) }}"
                                           class="{{ $input }} pl-10 tabular-nums">
                                </div>
                                <p class="text-xs text-gray-500 mt-1">
                                    Up to {{ $order->netLabel() }}. Lower it for a partial refund.
                                </p>
                            </div>

                            <div>
                                <label for="reason" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                    Reason <span class="text-red-500">*</span>
                                </label>
                                <textarea id="reason" name="reason" rows="2" required maxlength="255"
                                          placeholder="Why is this being refunded?"
                                          class="{{ $input }}">{{ old('reason') }}</textarea>
                                <p class="text-xs text-gray-500 mt-1">
                                    Kept on the order. A refund nobody can explain later is worse than no refund.
                                </p>
                            </div>

                            <button type="submit"
                                    class="w-full inline-flex items-center justify-center gap-2 bg-red-600 text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:bg-red-700 transition shadow-sm">
                                <x-admin.icon name="credit-card" class="w-4 h-4" />
                                Refund
                            </button>
                        </form>
                    </x-admin.panel>
                @endif

                <x-admin.panel title="Customer" icon="users">
                    <div class="px-5 py-4 text-sm space-y-1">
                        <p class="font-semibold text-gray-900">{{ $order->customer_name }}</p>
                        <p class="text-gray-600">
                            <a href="mailto:{{ $order->customer_email }}" class="text-blue-600 hover:underline">{{ $order->customer_email }}</a>
                        </p>
                        <p class="text-gray-600">
                            <a href="tel:{{ $order->customer_phone }}" class="text-blue-600 hover:underline">{{ $order->customer_phone }}</a>
                        </p>

                        @if ($order->isOffline())
                            {{-- The number the counter checks against the document they are
                                 shown. Given its own line and label rather than sitting in the
                                 contact details, because it is used for a decision. --}}
                            <p class="pt-2 mt-1 border-t border-gray-100">
                                <span class="block text-xs text-gray-500">Identity card to check</span>
                                <span class="block font-semibold text-gray-900 tabular-nums">
                                    {{ $order->identity_card ?: 'Not recorded' }}
                                </span>
                            </p>
                        @endif
                    </div>
                </x-admin.panel>

                @if ($order->isOffline())
                    <x-admin.panel title="Collected At" icon="identification">
                        <div class="px-5 py-4 text-sm space-y-1">
                            <p class="font-semibold text-gray-900">
                                {{ $order->collection_label ?: 'Collection point' }}
                            </p>

                            @if (filled($order->collection_location))
                                <p class="text-gray-700">{{ $order->collection_location }}</p>
                            @endif

                            @if ($order->collection_at)
                                <p class="text-gray-700">
                                    {{ $order->collection_at->format('l, d M Y') }} at {{ $order->collection_at->format('g:i a') }}
                                </p>
                            @endif

                            @if ($order->collection_event_id && $order->collectionEvent)
                                <p class="pt-2">
                                    <a href="{{ route('admin.event.registration.show', $order->collection_event_id) }}"
                                       class="text-xs font-semibold text-blue-600 hover:underline">
                                        Open the event
                                    </a>
                                </p>
                            @endif

                            {{-- Said plainly, because this is where somebody would otherwise
                                 look for a tracking number and wonder why there is none. --}}
                            <p class="text-xs text-gray-500 pt-2 mt-1 border-t border-gray-100 leading-relaxed">
                                Nothing is posted for this order, so there is no courier, no tracking and
                                no postage charge.
                            </p>
                        </div>
                    </x-admin.panel>
                @endif

                <x-admin.panel :title="$order->isOffline() ? 'Buyer Address' : 'Delivering To'" icon="archive">
                    <div class="px-5 py-4 text-sm text-gray-700 space-y-0.5">
                        <p>{{ $order->address_line_1 }}</p>
                        @if (filled($order->address_line_2))
                            <p>{{ $order->address_line_2 }}</p>
                        @endif
                        <p>{{ $order->postcode }} {{ $order->city }}</p>
                        <p>{{ $order->state }}, {{ $order->country }}</p>

                        @if ($order->isOffline())
                            <p class="text-xs text-gray-500 pt-2">
                                Contact details only. Nothing is sent here.
                            </p>
                        @elseif ($order->weightGrams() > 0)
                            <p class="text-xs text-gray-500 pt-2 tabular-nums">
                                Parcel weight {{ number_format($order->weightGrams() / 1000, 3) }} kg
                            </p>
                        @endif
                    </div>
                </x-admin.panel>

                @if (filled($order->tracking_number) || filled($order->courier_name))
                    <x-admin.panel title="Tracking" icon="globe">
                        <div class="px-5 py-4 text-sm space-y-1">
                            @if (filled($order->courier_name))
                                <p class="text-gray-700">{{ $order->courier_name }}</p>
                            @endif

                            @if (filled($order->tracking_number))
                                <p class="font-semibold text-gray-900 tabular-nums">{{ $order->tracking_number }}</p>
                            @endif

                            @if (filled($order->tracking_url))
                                <a href="{{ $order->tracking_url }}" target="_blank" rel="noopener"
                                   class="inline-flex items-center gap-1.5 text-blue-600 hover:underline font-semibold">
                                    Track this parcel
                                    <x-admin.icon name="eye" class="w-3.5 h-3.5" />
                                </a>
                            @endif

                            @if ($order->isReceiptConfirmed())
                                <p class="text-xs text-green-700 font-semibold pt-2">
                                    Receipt confirmed by the buyer on
                                    {{ $order->received_confirmed_at->format('d M Y, g:i a') }}
                                </p>
                            @endif
                        </div>
                    </x-admin.panel>
                @endif

                <x-admin.panel title="Payment" icon="credit-card">
                    <div class="px-5 py-4 text-sm space-y-1">
                        <p class="text-gray-700">{{ $order->methodLabel() }}</p>

                        @if ($order->isPaid())
                            <p class="text-green-700 font-semibold">
                                Paid {{ $order->paid_at->format('d M Y, g:i a') }}
                            </p>
                        @else
                            <p class="text-amber-700 font-semibold">Not paid</p>
                        @endif

                        @if (filled($order->payment_reference))
                            <p class="text-xs text-gray-500 tabular-nums pt-1">{{ $order->payment_reference }}</p>
                        @endif
                    </div>
                </x-admin.panel>
            </div>

        </div>
    </x-admin.page-card>
@endsection
