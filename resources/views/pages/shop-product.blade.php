{{--
    One product.

    There is no Add to Cart because there is no checkout. The options are listed with
    their price and stock so a buyer can see exactly what to ask for, and the enquiry
    note explains how to order. A button that led nowhere would be worse than saying
    plainly how this works.

    Copy fields are plain text by design, rendered escaped. The description keeps its
    line breaks through `nl2br(e(...))`, so the text the operator typed is what
    appears without markup being executed.
--}}
@extends('layouts.master')

@section('title', $product->seo_title ?: $product->name)

@push('head')
    @php
        $metaDescription = $product->seo_description ?: $product->short_description;
    @endphp

    @if (filled($metaDescription))
        <meta name="description" content="{{ Str::limit(strip_tags($metaDescription), 180) }}">
    @endif

    @if (filled($product->seo_keywords))
        <meta name="keywords" content="{{ $product->seo_keywords }}">
    @endif
@endpush

@section('content')
    @php
        $images = $product->images->filter(fn ($image) => $image->url() !== null)->values();
        $main = $images->first();
        $soldOut = $product->isSoldOut();
        $left = $product->stockLeft();
        $discount = $product->discountPercent();
        $specs = $product->specificationRows();
        $highlights = $product->highlightLines();
        $included = $product->includedLines();
    @endphp

    @include('components.page-header', [
        'title' => $product->name,
        'subtitle' => $product->short_description,
    ])

    <section class="py-14 bg-white">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">

            {{-- Trail back to the shop, and to the category if it has one. --}}
            <nav aria-label="Breadcrumb" class="flex flex-wrap items-center gap-1.5 text-sm text-gray-500 mb-8">
                <a href="{{ route('shop') }}" class="hover:text-blue-600 transition">Shop</a>

                @if ($product->categories->isNotEmpty())
                    <span class="text-gray-300" aria-hidden="true">/</span>
                    <a href="{{ route('shop', ['category' => $product->categories->first()->slug]) }}"
                       class="hover:text-blue-600 transition">
                        {{ $product->categories->first()->name }}
                    </a>
                @endif

                <span class="text-gray-300" aria-hidden="true">/</span>
                <span class="font-semibold text-gray-700">{{ $product->name }}</span>
            </nav>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 lg:gap-14">

                {{-- ==================== Pictures ==================== --}}
                <div>
                    <div class="relative aspect-square rounded-lg overflow-hidden bg-gray-900 border border-gray-200">
                        @if ($main)
                            <img id="product-main-image"
                                 src="{{ $main->url() }}"
                                 alt="{{ $main->altText() }}"
                                 @class(['w-full h-full object-cover', 'opacity-40' => $soldOut])>
                        @else
                            <div class="w-full h-full bg-gradient-to-br from-gray-900 via-gray-800 to-blue-900 flex items-center justify-center">
                                <span class="text-6xl font-bold text-white/25" aria-hidden="true">
                                    {{ Str::upper(Str::substr($product->name, 0, 2)) }}
                                </span>
                            </div>
                        @endif

                        @if ($soldOut)
                            <span class="absolute inset-0 flex items-center justify-center">
                                <span class="bg-gray-900/85 text-white text-sm font-bold uppercase tracking-widest px-5 py-2.5 rounded">
                                    Sold Out
                                </span>
                            </span>
                        @elseif ($discount !== null)
                            <span class="absolute top-4 left-4 bg-red-600 text-white text-sm font-bold px-3 py-2 rounded">
                                {{ $discount }}% off
                            </span>
                        @endif
                    </div>

                    @if ($images->count() > 1)
                        <div class="grid grid-cols-4 sm:grid-cols-5 gap-3 mt-3">
                            @foreach ($images as $image)
                                <button type="button"
                                        data-product-thumb
                                        data-src="{{ $image->url() }}"
                                        data-alt="{{ $image->altText() }}"
                                        @class([
                                            'aspect-square rounded-lg overflow-hidden border-2 transition',
                                            'border-blue-600' => $loop->first,
                                            'border-gray-200 hover:border-gray-400' => ! $loop->first,
                                        ])
                                        aria-label="Show picture {{ $loop->iteration }}">
                                    <img src="{{ $image->url() }}" alt="" class="w-full h-full object-cover">
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- ==================== The offer ==================== --}}
                <div>
                    @if (filled($product->brand))
                        <p class="text-sm font-bold uppercase tracking-wider text-blue-600 mb-2">{{ $product->brand }}</p>
                    @endif

                    <h1 class="text-2xl md:text-3xl font-bold text-gray-900 mb-4">{{ $product->name }}</h1>

                    <div class="flex flex-wrap items-baseline gap-3 mb-2">
                        <span class="text-3xl font-bold text-gray-900 tabular-nums">
                            {{ $product->priceSummaryLabel() }}
                        </span>

                        @if ($product->isOnOffer())
                            <span class="text-lg text-gray-400 line-through tabular-nums">
                                {{ App\Support\PaymentFigures::money((float) $product->compare_at_price) }}
                            </span>
                        @endif
                    </div>

                    {{-- Stock, stated once, here. --}}
                    <p class="text-sm mb-6">
                        @if ($soldOut)
                            <span class="inline-flex items-center gap-1.5 text-red-700 font-semibold">
                                <span class="w-2 h-2 rounded-full bg-red-500" aria-hidden="true"></span>
                                Currently unavailable
                            </span>
                        @elseif (! $product->track_inventory)
                            <span class="inline-flex items-center gap-1.5 text-green-700 font-semibold">
                                <span class="w-2 h-2 rounded-full bg-green-500" aria-hidden="true"></span>
                                Made to order
                            </span>
                        @elseif ($showsStockCount && $product->isLowStock())
                            <span class="inline-flex items-center gap-1.5 text-amber-700 font-semibold">
                                <span class="w-2 h-2 rounded-full bg-amber-500" aria-hidden="true"></span>
                                Only {{ $left }} left
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5 text-green-700 font-semibold">
                                <span class="w-2 h-2 rounded-full bg-green-500" aria-hidden="true"></span>
                                Available
                            </span>
                        @endif

                        @if (filled($product->sku))
                            <span class="mx-2 text-gray-300" aria-hidden="true">|</span>
                            <span class="text-gray-500">SKU <span class="tabular-nums">{{ $product->sku }}</span></span>
                        @endif
                    </p>

                    {{-- ==================== Options ==================== --}}
                    @if ($product->hasVariants())
                        <div class="rounded-lg border border-gray-200 overflow-hidden mb-6">
                            <div class="px-4 py-3 bg-gray-50 border-b border-gray-200">
                                <h2 class="text-sm font-bold text-gray-900">
                                    {{ $product->option_name }} options
                                </h2>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    Quote the one you want when you enquire.
                                </p>
                            </div>

                            <ul class="divide-y divide-gray-100">
                                @foreach ($product->variants as $variant)
                                    @php
                                        $variantSoldOut = $variant->isSoldOut();
                                        $variantLeft = $variant->stockLeft();
                                    @endphp

                                    <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                                        <span class="min-w-0">
                                            <span @class([
                                                'text-sm font-semibold',
                                                'text-gray-900' => ! $variantSoldOut,
                                                'text-gray-400 line-through' => $variantSoldOut,
                                            ])>
                                                {{ $variant->label }}
                                            </span>

                                            @if ($variantSoldOut)
                                                <span class="block text-xs text-red-600 font-semibold">Sold out</span>
                                            @elseif ($showsStockCount && $variantLeft !== null && $variantLeft <= $product->low_stock_threshold)
                                                <span class="block text-xs text-amber-700">{{ $variantLeft }} left</span>
                                            @endif
                                        </span>

                                        <span @class([
                                            'text-sm tabular-nums',
                                            'font-semibold text-gray-900' => ! $variantSoldOut,
                                            'text-gray-400' => $variantSoldOut,
                                        ])>
                                            {{ App\Support\PaymentFigures::money($variant->unitPrice()) }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    {{-- ==================== Highlights ==================== --}}
                    @if ($highlights !== [])
                        <ul class="space-y-2.5 mb-6">
                            @foreach ($highlights as $line)
                                <li class="flex gap-3 text-base text-gray-700">
                                    <svg class="w-5 h-5 shrink-0 mt-0.5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                    </svg>
                                    <span>{{ $line }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- ==================== Buy, or enquire ==================== --}}
                    @if ($canBuy && ! $soldOut)
                        {{-- The option chooser is a radio group rather than a select, so
                             a sold out size can be shown, struck through and disabled
                             instead of quietly missing from a dropdown. --}}
                        <form action="{{ route('cart.store') }}" method="POST" class="rounded-lg border border-gray-200 p-5 mb-6">
                            @csrf
                            <input type="hidden" name="product_id" value="{{ $product->id }}">

                            @error('cart')
                                <p class="text-sm text-red-600 mb-4">{{ $message }}</p>
                            @enderror

                            @if ($product->hasVariants())
                                <fieldset class="mb-5">
                                    <legend class="text-sm font-bold text-gray-900 mb-2.5">
                                        Choose a {{ Str::lower($product->option_name) }}
                                    </legend>

                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($product->variants as $variant)
                                            @php $variantSoldOut = $variant->isSoldOut(); @endphp

                                            <label @class([
                                                'relative inline-flex items-center gap-2 rounded-lg border px-3.5 py-2.5 text-sm transition',
                                                'border-gray-300 cursor-pointer hover:border-blue-400 has-checked:border-blue-600 has-checked:bg-blue-50 has-checked:font-semibold' => ! $variantSoldOut,
                                                'border-gray-200 bg-gray-50 text-gray-400 cursor-not-allowed' => $variantSoldOut,
                                            ])>
                                                <input type="radio" name="variant_id" value="{{ $variant->id }}"
                                                       @disabled($variantSoldOut)
                                                       @checked(! $variantSoldOut && $loop->first)
                                                       class="text-blue-600 focus:ring-blue-500/40 disabled:opacity-40">

                                                <span @class(['line-through' => $variantSoldOut])>{{ $variant->label }}</span>

                                                <span class="text-xs tabular-nums {{ $variantSoldOut ? 'text-gray-400' : 'text-gray-500' }}">
                                                    {{ App\Support\PaymentFigures::money($variant->unitPrice()) }}
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>

                                    @error('variant_id')
                                        <p class="text-xs text-red-600 mt-2">{{ $message }}</p>
                                    @enderror
                                </fieldset>
                            @endif

                            <div class="flex flex-wrap items-end gap-3">
                                <div>
                                    <label for="quantity" class="block text-sm font-bold text-gray-900 mb-1.5">Quantity</label>
                                    <input type="number" id="quantity" name="quantity" value="1" min="1" max="{{ App\Support\Cart::MAX_PER_LINE }}"
                                           class="w-24 rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-right tabular-nums focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40">
                                </div>

                                <button type="submit"
                                        class="inline-flex items-center gap-2 bg-blue-600 text-white px-7 py-3 rounded-lg font-semibold hover:bg-blue-700 transition shadow-sm">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                                    </svg>
                                    Add to Basket
                                </button>
                            </div>
                        </form>
                    @else
                        {{-- Nothing can take money, or there is nothing left to sell. Either
                             way an Add to Basket button would lead nowhere, so the enquiry
                             route is offered instead. --}}
                        <div class="rounded-lg border border-blue-200 bg-blue-50 p-5 mb-6">
                            <h2 class="text-base font-bold text-blue-900 mb-2">
                                {{ $soldOut ? 'Out of stock' : 'How to order' }}
                            </h2>

                            <p class="text-sm text-blue-800 leading-relaxed mb-4">
                                {{ $soldOut ? 'This has sold out. Tell us what you need and we will let you know when it is back, or quote for a batch made to order.' : $enquiryNote }}
                            </p>

                            <a href="{{ route('contact') }}"
                               class="inline-flex items-center gap-2 bg-blue-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-blue-700 transition shadow-sm">
                                Enquire about this
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                                </svg>
                            </a>
                        </div>
                    @endif

                    {{-- ==================== Included ==================== --}}
                    @if ($included !== [])
                        <div class="rounded-lg border border-gray-200 p-5">
                            <h2 class="text-sm font-bold text-gray-900 mb-3">Included with purchase</h2>

                            <ul class="space-y-2">
                                @foreach ($included as $line)
                                    <li class="flex gap-2.5 text-sm text-gray-700">
                                        <svg class="w-4 h-4 shrink-0 mt-0.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                        </svg>
                                        <span>{{ $line }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>

            </div>
        </div>
    </section>

    {{-- ==================== Description and specs ==================== --}}
    @if (filled($product->description) || $specs !== [])
        <section class="py-14 bg-gray-50 border-t border-gray-200">
            <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-10">

                    @if (filled($product->description))
                        <div class="lg:col-span-2">
                            <h2 class="text-2xl md:text-3xl font-bold text-gray-900 mb-5">Details</h2>

                            {{-- Escaped, then line breaks turned into markup. Written in the
                                 admin area, so rendering it as HTML would let anyone who
                                 reaches an admin account run script in every visitor's
                                 browser. --}}
                            <div class="text-base text-gray-700 leading-relaxed">
                                {!! nl2br(e($product->description)) !!}
                            </div>
                        </div>
                    @endif

                    @if ($specs !== [])
                        <div @class(['lg:col-span-1' => filled($product->description), 'lg:col-span-3' => ! filled($product->description)])>
                            <h2 class="text-2xl md:text-3xl font-bold text-gray-900 mb-5">Specifications</h2>

                            <dl class="rounded-lg border border-gray-200 bg-white overflow-hidden divide-y divide-gray-100">
                                @foreach ($specs as $row)
                                    @if ($row['value'] === '')
                                        {{-- A line with no colon. Shown as its own heading rather
                                             than dropped, so a typo is visible. --}}
                                        <div class="px-4 py-2.5 bg-gray-50">
                                            <dt class="text-sm font-bold text-gray-900">{{ $row['label'] }}</dt>
                                        </div>
                                    @else
                                        <div class="flex flex-wrap gap-x-4 px-4 py-2.5">
                                            <dt class="text-sm text-gray-500 min-w-32">{{ $row['label'] }}</dt>
                                            <dd class="text-sm font-semibold text-gray-900">{{ $row['value'] }}</dd>
                                        </div>
                                    @endif
                                @endforeach

                                {{-- Shipping figures are stored on the product, so they are shown
                                     here rather than repeated by hand in the specifications. --}}
                                @if ($product->weightKg() !== null)
                                    <div class="flex flex-wrap gap-x-4 px-4 py-2.5">
                                        <dt class="text-sm text-gray-500 min-w-32">Weight</dt>
                                        <dd class="text-sm font-semibold text-gray-900 tabular-nums">{{ $product->weightKg() }} kg</dd>
                                    </div>
                                @endif

                                @if ($product->dimensionsLabel() !== null)
                                    <div class="flex flex-wrap gap-x-4 px-4 py-2.5">
                                        <dt class="text-sm text-gray-500 min-w-32">Dimensions</dt>
                                        <dd class="text-sm font-semibold text-gray-900 tabular-nums">{{ $product->dimensionsLabel() }}</dd>
                                    </div>
                                @endif
                            </dl>
                        </div>
                    @endif

                </div>
            </div>
        </section>
    @endif

    {{-- ==================== Related ==================== --}}
    @if ($related->isNotEmpty())
        <section class="py-14 bg-white border-t border-gray-200">
            <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                <h2 class="text-2xl md:text-3xl font-bold text-gray-900 mb-8">You might also want</h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-7">
                    @foreach ($related as $other)
                        @include('components.shop-product-card', [
                            'product' => $other,
                            'showStock' => $showsStockCount,
                        ])
                    @endforeach
                </div>
            </div>
        </section>
    @endif
@endsection

@push('scripts')
<script>
    /*
     | Picture switcher. Swaps the main image for the thumbnail that was pressed and
     | moves the selected outline with it.
     */
    (function () {
        const main = document.getElementById('product-main-image');
        const thumbs = document.querySelectorAll('[data-product-thumb]');

        if (!main || thumbs.length === 0) {
            return;
        }

        thumbs.forEach(function (thumb) {
            thumb.addEventListener('click', function () {
                main.src = thumb.dataset.src;
                main.alt = thumb.dataset.alt || '';

                thumbs.forEach(function (other) {
                    other.classList.remove('border-blue-600');
                    other.classList.add('border-gray-200', 'hover:border-gray-400');
                });

                thumb.classList.add('border-blue-600');
                thumb.classList.remove('border-gray-200', 'hover:border-gray-400');
            });
        });
    })();
</script>
@endpush
