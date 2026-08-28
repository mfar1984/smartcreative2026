{{--
    Order confirmation.

    Reached through a signed link, because references run in sequence and an unsigned
    address would let anybody count upwards through other people's details.

    What it says next depends on how they chose to pay, since only the gateway settles
    itself: the other two need the buyer to do something.
--}}
@extends('layouts.master')

@section('title', $pageTitle)

@section('content')
    @include('components.page-header', [
        'title' => 'Order ' . $order->reference,
        'subtitle' => 'Thank you. Keep this reference; it is how we look your order up.',
    ])

    <section class="py-14 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl">

                {{-- ---------------- What happens next ---------------- --}}
                @if ($order->payment_method === App\Models\ShopOrder::METHOD_BANK_TRANSFER)
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-6 mb-8">
                        <h2 class="text-lg font-bold text-amber-900 mb-2">Your order is not paid yet</h2>

                        <p class="text-base text-amber-800 leading-relaxed mb-4">
                            {{ $bankNote ?: 'Transfer the total below and send us the receipt with your order reference. Nothing is dispatched until the payment shows in our account.' }}
                        </p>

                        @if ($bankAccount)
                            <dl class="rounded-lg bg-white border border-amber-200 divide-y divide-amber-100">
                                <div class="flex flex-wrap gap-x-4 px-4 py-2.5">
                                    <dt class="text-sm text-gray-500 min-w-32">Account Name</dt>
                                    <dd class="text-sm font-semibold text-gray-900">{{ $bankAccount['name'] }}</dd>
                                </div>
                                <div class="flex flex-wrap gap-x-4 px-4 py-2.5">
                                    <dt class="text-sm text-gray-500 min-w-32">Bank</dt>
                                    <dd class="text-sm font-semibold text-gray-900">{{ $bankAccount['bank'] }}</dd>
                                </div>
                                <div class="flex flex-wrap gap-x-4 px-4 py-2.5">
                                    <dt class="text-sm text-gray-500 min-w-32">Account Number</dt>
                                    <dd class="text-sm font-semibold text-gray-900 tabular-nums">{{ $bankAccount['number'] }}</dd>
                                </div>
                                <div class="flex flex-wrap gap-x-4 px-4 py-2.5 bg-amber-50/60">
                                    <dt class="text-sm text-gray-500 min-w-32">Amount</dt>
                                    <dd class="text-sm font-bold text-gray-900 tabular-nums">{{ $order->grandTotalLabel() }}</dd>
                                </div>
                                <div class="flex flex-wrap gap-x-4 px-4 py-2.5">
                                    <dt class="text-sm text-gray-500 min-w-32">Reference</dt>
                                    <dd class="text-sm font-bold text-gray-900">{{ $order->reference }}</dd>
                                </div>
                            </dl>
                        @endif
                    </div>
                @elseif ($order->payment_method === App\Models\ShopOrder::METHOD_COD)
                    <div class="rounded-lg border border-blue-200 bg-blue-50 p-6 mb-8">
                        <h2 class="text-lg font-bold text-blue-900 mb-2">Pay when it arrives</h2>

                        <p class="text-base text-blue-800 leading-relaxed">
                            {{ $codNote ?: 'Have the exact amount ready for the courier.' }}
                            You will owe <span class="font-bold">{{ $order->grandTotalLabel() }}</span> on delivery.
                        </p>
                    </div>
                @else
                    {{-- The gateway handoff is not wired to shop orders yet. Saying so is
                         better than leaving somebody waiting for a payment page that never
                         opens. --}}
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-6 mb-8">
                        <h2 class="text-lg font-bold text-amber-900 mb-2">We will send you a payment link</h2>

                        <p class="text-base text-amber-800 leading-relaxed">
                            Card and online banking for shop orders is still being connected. We will
                            email a payment link for <span class="font-bold">{{ $order->grandTotalLabel() }}</span>
                            to {{ $order->customer_email }} shortly, or you can reply to us to pay another way.
                        </p>
                    </div>
                @endif

                {{-- ---------------- The order ---------------- --}}
                <h2 class="text-xl font-bold text-gray-900 mb-4">What you ordered</h2>

                <div class="rounded-lg border border-gray-200 overflow-hidden mb-8">
                    <ul class="divide-y divide-gray-100">
                        @foreach ($order->items as $item)
                            <li class="flex flex-wrap justify-between gap-3 px-5 py-3.5">
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold text-gray-900">{{ $item->label() }}</span>
                                    <span class="block text-xs text-gray-500 tabular-nums">
                                        {{ $item->unitPriceLabel() }} &times; {{ $item->quantity }}
                                    </span>
                                </span>
                                <span class="text-sm font-semibold text-gray-900 tabular-nums whitespace-nowrap">
                                    {{ $item->lineTotalLabel() }}
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    <dl class="bg-gray-50 border-t border-gray-200 px-5 py-4 space-y-2">
                        <div class="flex justify-between gap-4 text-sm">
                            <dt class="text-gray-600">Goods</dt>
                            <dd class="font-semibold text-gray-900 tabular-nums">{{ $order->itemsTotalLabel() }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 text-sm">
                            <dt class="text-gray-600">
                                Delivery
                                @if (filled($order->shipping_label))
                                    <span class="block text-xs text-gray-500">{{ $order->shipping_label }}</span>
                                @endif
                            </dt>
                            <dd class="font-semibold text-gray-900 tabular-nums">{{ $order->shippingTotalLabel() }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 pt-2 border-t border-gray-200">
                            <dt class="text-base font-bold text-gray-900">Total</dt>
                            <dd class="text-base font-bold text-gray-900 tabular-nums">{{ $order->grandTotalLabel() }}</dd>
                        </div>
                    </dl>
                </div>

                {{-- ---------------- Where it goes ---------------- --}}
                <h2 class="text-xl font-bold text-gray-900 mb-4">Delivering to</h2>

                <div class="rounded-lg border border-gray-200 p-5 text-base text-gray-700 mb-8">
                    <p class="font-semibold text-gray-900">{{ $order->customer_name }}</p>
                    <p>{{ $order->address_line_1 }}</p>
                    @if (filled($order->address_line_2))
                        <p>{{ $order->address_line_2 }}</p>
                    @endif
                    <p>{{ $order->postcode }} {{ $order->city }}</p>
                    <p>{{ $order->state }}, {{ $order->country }}</p>
                    <p class="mt-2 text-sm text-gray-500">
                        {{ $order->customer_phone }}
                        <span class="mx-1 text-gray-300" aria-hidden="true">&bull;</span>
                        {{ $order->customer_email }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-4">
                    <a href="{{ route('shop') }}"
                       class="inline-flex items-center gap-2 bg-blue-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-blue-700 transition">
                        Keep shopping
                    </a>

                    <a href="{{ route('contact') }}"
                       class="inline-flex items-center gap-2 border-2 border-gray-300 text-gray-700 px-6 py-3 rounded-lg font-semibold hover:bg-gray-50 transition">
                        Ask about this order
                    </a>
                </div>

            </div>
        </div>
    </section>
@endsection
