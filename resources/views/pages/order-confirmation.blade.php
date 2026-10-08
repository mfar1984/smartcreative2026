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

                {{-- Flashes from the pay route, rendered here rather than inside a
                     payment-state branch, and this placement is load-bearing.

                     ShopOrderPaymentController::pay() sets the payment error bag in
                     exactly the cases where awaitsGatewayPayment() is false — refunded,
                     cancelled, a manual method, a zero total — so an @error inside the
                     branch guarded on awaitsGatewayPayment() is the one branch
                     guaranteed not to be rendering when there is something to say. No
                     public layout renders either of these, so without this pair the
                     buyer presses Pay, lands back on an unchanged page, and nothing
                     explains why. --}}
                @if (session('status'))
                    <p role="status" class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-base text-blue-900 mb-6">
                        {{ session('status') }}
                    </p>
                @endif

                @error('payment')
                    <p role="alert" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-base text-red-800 mb-6">
                        {{ $message }}
                    </p>
                @enderror

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
                    {{-- Closed before paid, for the same reason pay() orders its guards
                         that way: isPaid() is paid_at !== null and survives a refund, so
                         without this first branch a refunded order would read "Payment
                         received. We have your RM 25.00." --}}
                    @if ($order->isClosed())
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-6 mb-8">
                            <h2 class="text-lg font-bold text-gray-900 mb-2">
                                This order is {{ strtolower($order->statusLabel()) }}
                            </h2>
                            <p class="text-base text-gray-700 leading-relaxed">
                                {{ $order->status === App\Models\ShopOrder::STATUS_REFUNDED
                                    ? 'The payment has been returned to you. Nothing further is owed.'
                                    : 'It was cancelled, so there is nothing to pay.' }}
                                Contact us quoting {{ $order->reference }} if that is not what you expected.
                            </p>
                        </div>
                    @elseif ($order->isPaid())
                        <div role="status" class="rounded-lg border border-green-200 bg-green-50 p-6 mb-8">
                            <h2 class="text-lg font-bold text-green-900 mb-2">Payment received</h2>
                            <p class="text-base text-green-800 leading-relaxed">
                                We have your {{ $order->grandTotalLabel() }}.
                                {{ $order->isOffline()
                                    ? 'We will email you the collection details.'
                                    : 'Your order is being prepared for dispatch.' }}
                            </p>
                        </div>
                    @elseif ($gatewayReady && $order->awaitsGatewayPayment())
                        <div class="rounded-lg border border-amber-200 bg-amber-50 p-6 mb-8">
                            <h2 class="text-lg font-bold text-amber-900 mb-2">Your order is not paid yet</h2>

                            @if ($paymentOutcome === 'failure')
                                <p class="text-base text-amber-800 leading-relaxed mb-4">
                                    That payment did not go through, so nothing has been charged. You can try
                                    again below.
                                </p>
                            @elseif ($paymentOutcome === 'cancel')
                                <p class="text-base text-amber-800 leading-relaxed mb-4">
                                    You cancelled the payment, so nothing has been charged. The order is still
                                    here when you are ready.
                                </p>
                            @else
                                <p class="text-base text-amber-800 leading-relaxed mb-4">
                                    Pay {{ $order->grandTotalLabel() }} on our gateway's own page. We never see
                                    your card number, and nothing is released until the payment shows.
                                </p>
                            @endif

                            {{-- No @error here. It lives above every branch, because the
                                 cases that set it are the cases this branch does not
                                 render in. --}}

                            <form action="{{ $payUrl }}" method="POST">
                                @csrf
                                <button type="submit"
                                        class="inline-flex items-center gap-2 bg-blue-600 text-white px-7 py-3 rounded-lg font-semibold hover:bg-blue-700 transition shadow-sm">
                                    Pay {{ $order->grandTotalLabel() }} now
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                                    </svg>
                                </button>
                            </form>
                        </div>
                    @else
                        <div class="rounded-lg border border-amber-200 bg-amber-50 p-6 mb-8">
                            <h2 class="text-lg font-bold text-amber-900 mb-2">We cannot take this payment online right now</h2>
                            <p class="text-base text-amber-800 leading-relaxed">
                                Please contact us quoting {{ $order->reference }} and we will arrange payment of
                                {{ $order->grandTotalLabel() }} another way.
                            </p>
                        </div>
                    @endif
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
                @if ($order->isOffline())
                    {{-- Nothing is posted, so "Delivering to" and a street address were
                         simply wrong here. This is now the page we actively email buyers
                         and send them back to, so it says what they actually need. --}}
                    <h2 class="text-xl font-bold text-gray-900 mb-4">Collecting from</h2>

                    <div class="rounded-lg border border-gray-200 p-5 text-base text-gray-700 mb-8">
                        <p class="font-semibold text-gray-900">{{ $order->collection_location ?: $order->collection_label }}</p>

                        @if ($order->collection_at)
                            {{-- ->format() directly, not through LocalTime: this is entered
                                 through a datetime-local input and stored as wall-clock, so
                                 converting it would shift it by a further eight hours. --}}
                            <p>{{ \App\Support\LocalTime::formatWallClock($order->collection_at) }}</p>
                        @endif

                        <p class="mt-2 text-sm text-gray-500">
                            Bring the identity card or passport number on this order. We check the
                            document before handing anything over.
                        </p>

                        <p class="mt-2 text-sm text-gray-500">
                            {{ $order->customer_name }}
                            <span class="mx-1 text-gray-300" aria-hidden="true">&bull;</span>
                            {{ $order->customer_phone }}
                            <span class="mx-1 text-gray-300" aria-hidden="true">&bull;</span>
                            {{ $order->customer_email }}
                        </p>
                    </div>
                @else
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
                @endif

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
