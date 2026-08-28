{{--
    The basket.

    Quantities are the only thing posted back. Every price is resolved server side, so
    a total shown here cannot be talked into being something else by editing the page.
--}}
@extends('layouts.master')

@section('title', $pageTitle)

@section('content')
    @include('components.page-header', [
        'title' => $pageTitle,
        'subtitle' => $lines->isEmpty() ? null : 'Check the quantities, then continue to checkout.',
    ])

    <section class="py-14 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">

            @if (session('status'))
                <p class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    {{ session('status') }}
                </p>
            @endif

            @error('cart')
                <p class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</p>
            @enderror

            {{-- Stock can fall between adding something and coming back to it. Saying so
                 is better than quietly changing the number the buyer typed. --}}
            @if ($capped->isNotEmpty())
                <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3.5">
                    <p class="text-sm font-semibold text-amber-900">Some quantities were reduced</p>
                    <ul class="mt-1 space-y-0.5">
                        @foreach ($capped as $line)
                            <li class="text-sm text-amber-800">
                                {{ $line['product']->name }}@if ($line['variant']) &mdash; {{ $line['variant']->label }}@endif:
                                only {{ $line['capped_to'] }} left.
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($lines->isEmpty())
                <div class="max-w-xl mx-auto text-center py-16">
                    <svg class="w-16 h-16 mx-auto text-gray-300 mb-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                    </svg>

                    <h2 class="text-xl font-bold text-gray-900 mb-3">Your basket is empty</h2>
                    <p class="text-base text-gray-600 mb-7">Nothing has been added yet.</p>

                    <a href="{{ route('shop') }}"
                       class="inline-flex items-center gap-2 bg-blue-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-blue-700 transition">
                        Browse the shop
                    </a>
                </div>
            @else
                <form action="{{ route('cart.update') }}" method="POST">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

                        {{-- ---------------- Lines ---------------- --}}
                        <div class="lg:col-span-2 space-y-4">
                            @foreach ($lines as $line)
                                @php
                                    $product = $line['product'];
                                    $image = $product->featuredImageUrl();
                                @endphp

                                <div class="flex flex-wrap sm:flex-nowrap gap-4 rounded-lg border border-gray-200 p-4">
                                    <a href="{{ route('shop.product', $product->slug) }}"
                                       class="shrink-0 w-20 h-20 rounded-lg overflow-hidden bg-gray-900">
                                        @if ($image)
                                            <img src="{{ $image }}" alt="{{ $product->name }}" class="w-full h-full object-cover">
                                        @else
                                            <span class="flex w-full h-full items-center justify-center text-lg font-bold text-white/25" aria-hidden="true">
                                                {{ Str::upper(Str::substr($product->name, 0, 2)) }}
                                            </span>
                                        @endif
                                    </a>

                                    <div class="flex-1 min-w-0">
                                        <h2 class="text-base font-bold text-gray-900">
                                            <a href="{{ route('shop.product', $product->slug) }}" class="hover:text-blue-700 transition">
                                                {{ $product->name }}
                                            </a>
                                        </h2>

                                        @if ($line['variant'])
                                            <p class="text-sm text-gray-500 mt-0.5">
                                                {{ $product->option_name }}: <span class="font-semibold text-gray-700">{{ $line['variant']->label }}</span>
                                            </p>
                                        @endif

                                        <p class="text-sm text-gray-500 mt-0.5 tabular-nums">
                                            {{ App\Support\PaymentFigures::money($line['unit_price']) }} each
                                        </p>
                                    </div>

                                    <div class="flex items-center gap-4 sm:flex-col sm:items-end sm:gap-2">
                                        <div>
                                            <label for="qty-{{ $loop->index }}" class="sr-only">Quantity</label>
                                            <input type="number" id="qty-{{ $loop->index }}"
                                                   name="quantities[{{ $line['key'] }}]"
                                                   value="{{ $line['quantity'] }}"
                                                   min="0" max="{{ App\Support\Cart::MAX_PER_LINE }}"
                                                   class="w-20 rounded-lg border border-gray-300 px-3 py-2 text-sm text-right tabular-nums focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40">
                                        </div>

                                        <p class="text-base font-bold text-gray-900 tabular-nums whitespace-nowrap">
                                            {{ App\Support\PaymentFigures::money($line['line_total']) }}
                                        </p>
                                    </div>
                                </div>
                            @endforeach

                            <div class="flex flex-wrap items-center gap-3 pt-2">
                                <button type="submit"
                                        class="rounded-lg border-2 border-gray-300 px-5 py-2.5 text-sm font-semibold text-gray-700 hover:border-blue-400 hover:text-blue-700 transition">
                                    Update basket
                                </button>

                                <p class="text-xs text-gray-500">Set a quantity to 0 to remove a line.</p>
                            </div>
                        </div>

                        {{-- ---------------- Summary ---------------- --}}
                        <div>
                            <div class="rounded-lg border border-gray-200 bg-gray-50 p-6 lg:sticky lg:top-24">
                                <h2 class="text-lg font-bold text-gray-900 mb-4">Summary</h2>

                                <dl class="space-y-2.5 pb-4 border-b border-gray-200">
                                    <div class="flex justify-between gap-4 text-sm">
                                        <dt class="text-gray-600">Goods</dt>
                                        <dd class="font-semibold text-gray-900 tabular-nums">
                                            {{ App\Support\PaymentFigures::money($itemsTotal) }}
                                        </dd>
                                    </div>

                                    {{-- Postage needs a destination, which is asked for at checkout.
                                         Quoting a figure here would be a guess shown as a price. --}}
                                    <div class="flex justify-between gap-4 text-sm">
                                        <dt class="text-gray-600">Delivery</dt>
                                        <dd class="text-gray-500">Worked out at checkout</dd>
                                    </div>
                                </dl>

                                @if ($freeShippingThreshold !== null)
                                    <p class="text-xs mt-3 {{ $itemsTotal >= $freeShippingThreshold ? 'text-green-700 font-semibold' : 'text-gray-500' }}">
                                        @if ($itemsTotal >= $freeShippingThreshold)
                                            Delivery is free on this order.
                                        @else
                                            Spend {{ App\Support\PaymentFigures::money($freeShippingThreshold - $itemsTotal) }}
                                            more for free delivery.
                                        @endif
                                    </p>
                                @endif

                                <a href="{{ route('checkout') }}"
                                   class="mt-5 flex items-center justify-center gap-2 w-full bg-blue-600 text-white px-6 py-3.5 rounded-lg font-semibold hover:bg-blue-700 transition shadow-sm">
                                    Continue to checkout
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                                    </svg>
                                </a>

                                <a href="{{ route('shop') }}" class="block text-center text-sm font-semibold text-gray-600 hover:text-blue-700 transition mt-3">
                                    Keep shopping
                                </a>

                                @if (filled($shippingNote))
                                    <p class="text-xs text-gray-500 mt-5 pt-4 border-t border-gray-200">{{ $shippingNote }}</p>
                                @endif
                            </div>
                        </div>

                    </div>
                </form>
            @endif

        </div>
    </section>
@endsection
