{{--
    The shop listing.

    Category chips filter by slug rather than id, so the address stays readable and
    survives the catalogue being rebuilt. Only categories that hold something active
    are offered, because a filter that lands on an empty grid reads as a fault.
--}}
@extends('layouts.master')

@section('title', $pageTitle)

@section('content')
    @include('components.page-header', [
        'title' => $pageTitle,
        'subtitle' => $pageSubtitle,
    ])

    <section class="py-14 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">

            @if ($categories->isNotEmpty())
                <div class="flex flex-wrap items-center gap-2 mb-10 pb-8 border-b border-gray-200">
                    <span class="text-sm font-semibold text-gray-500 mr-1">Show</span>

                    <a href="{{ route('shop') }}"
                       @class([
                           'px-4 py-2 rounded-full text-sm font-semibold transition',
                           'bg-blue-600 text-white shadow-sm' => $activeCategory === null,
                           'bg-gray-100 text-gray-700 hover:bg-gray-200' => $activeCategory !== null,
                       ])
                       @if ($activeCategory === null) aria-current="page" @endif>
                        Everything
                        <span class="opacity-70">({{ $totalActive }})</span>
                    </a>

                    @foreach ($categories as $category)
                        <a href="{{ route('shop', ['category' => $category->slug]) }}"
                           @class([
                               'inline-flex items-center gap-1.5 px-4 py-2 rounded-full text-sm font-semibold transition',
                               'bg-blue-600 text-white shadow-sm' => $activeCategory?->id === $category->id,
                               'bg-gray-100 text-gray-700 hover:bg-gray-200' => $activeCategory?->id !== $category->id,
                           ])
                           @if ($activeCategory?->id === $category->id) aria-current="page" @endif>
                            {{ $category->name }}
                            <span class="opacity-70">({{ $category->products_count }})</span>
                        </a>
                    @endforeach
                </div>
            @endif

            @if ($products->isNotEmpty())
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-7">
                    @foreach ($products as $product)
                        @include('components.shop-product-card', [
                            'product' => $product,
                            'showStock' => $showsStockCount,
                        ])
                    @endforeach
                </div>

                @if ($products->hasPages())
                    <div class="mt-12">
                        {{ $products->links() }}
                    </div>
                @endif
            @else
                {{-- Two versions, because a filter that matched nothing and a shop with
                     nothing in it are different problems. --}}
                <div class="max-w-xl mx-auto text-center py-16">
                    <svg class="w-16 h-16 mx-auto text-gray-300 mb-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                    </svg>

                    @if ($activeCategory !== null)
                        <h2 class="text-xl font-bold text-gray-900 mb-3">
                            Nothing in {{ $activeCategory->name }} right now
                        </h2>
                        <p class="text-base text-gray-600 mb-7">There is other stock to look at.</p>
                        <a href="{{ route('shop') }}"
                           class="inline-flex items-center gap-2 bg-blue-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-blue-700 transition">
                            Show everything
                        </a>
                    @else
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Nothing in stock at the moment</h2>
                        <p class="text-base text-gray-600 mb-7">
                            Tell us what you need and we will quote for it directly.
                        </p>
                        <a href="{{ route('contact') }}"
                           class="inline-flex items-center gap-2 bg-blue-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-blue-700 transition">
                            Ask us for a quote
                        </a>
                    @endif
                </div>
            @endif

        </div>
    </section>

    {{-- Ordering runs through us rather than a checkout, so the shop says how up
         front instead of leaving somebody to hunt for a basket that is not there. --}}
    @if ($products->isNotEmpty())
        <section class="py-14 bg-gray-50 border-t border-gray-200">
            <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                <div class="max-w-3xl">
                    <h2 class="text-2xl md:text-3xl font-bold text-gray-900 mb-4">Ordering and bulk pricing</h2>

                    <p class="text-base text-gray-600 leading-relaxed mb-4">
                        Orders are placed with us directly. Send the item, the quantity and any
                        engraving or printing you need, and we will reply with a total including
                        delivery, plus payment instructions.
                    </p>

                    <p class="text-base text-gray-600 leading-relaxed mb-8">
                        Quantities for an event are usually cheaper than the listed price. Ask.
                    </p>

                    <div class="flex flex-wrap gap-4">
                        <a href="{{ route('contact') }}"
                           class="inline-flex items-center gap-2 bg-blue-600 text-white px-7 py-3.5 rounded-lg font-semibold hover:bg-blue-700 transition shadow-md">
                            Send an enquiry
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                            </svg>
                        </a>

                        <a href="{{ route('legal.refund') }}"
                           class="inline-flex items-center gap-2 border-2 border-gray-300 text-gray-700 px-7 py-3.5 rounded-lg font-semibold hover:bg-white transition">
                            Refund policy
                        </a>
                    </div>
                </div>
            </div>
        </section>
    @endif
@endsection
