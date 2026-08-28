{{--
    One product on the shop grid.

    Shared by the shop listing and the related products strip, so the two can never
    drift apart.

    A product with no picture falls back to a lettered tile rather than a broken
    image or a grey box, so a catalogue can be built before the photography is done
    without the shop looking unfinished.

    @param \App\Models\ShopProduct $product
    @param bool                    $showStock  whether "4 left" is published
--}}
@php
    $image = $product->featuredImageUrl();
    $soldOut = $product->isSoldOut();
    $left = $product->stockLeft();
    $discount = $product->discountPercent();
@endphp

<article class="group flex flex-col rounded-lg border border-gray-200 shadow-sm overflow-hidden hover:shadow-xl hover:border-blue-200 transition">

    <a href="{{ route('shop.product', $product->slug) }}" class="block relative aspect-square overflow-hidden bg-gray-900">
        @if ($image)
            <img src="{{ $image }}"
                 alt="{{ $product->featuredImage()?->altText() ?? $product->name }}"
                 loading="lazy"
                 @class([
                     'w-full h-full object-cover transition duration-500',
                     'group-hover:scale-105' => ! $soldOut,
                     // Sold out is greyed rather than hidden, so a returning buyer
                     // can still see the item is real.
                     'opacity-40' => $soldOut,
                 ])>
        @else
            <div @class([
                'w-full h-full bg-gradient-to-br from-gray-900 via-gray-800 to-blue-900 flex items-center justify-center',
                'opacity-60' => $soldOut,
            ])>
                <span class="text-5xl font-bold text-white/25" aria-hidden="true">
                    {{ Str::upper(Str::substr($product->name, 0, 2)) }}
                </span>
            </div>
        @endif

        @if ($soldOut)
            <span class="absolute inset-0 flex items-center justify-center">
                <span class="bg-gray-900/85 text-white text-xs font-bold uppercase tracking-widest px-4 py-2 rounded">
                    Sold Out
                </span>
            </span>
        @elseif ($discount !== null)
            <span class="absolute top-3 left-3 bg-red-600 text-white text-xs font-bold px-2.5 py-1.5 rounded">
                {{ $discount }}% off
            </span>
        @endif

        @if (! $soldOut && $product->is_featured)
            <span class="absolute top-3 right-3 bg-amber-400 text-amber-900 text-xs font-bold uppercase tracking-wide px-2.5 py-1.5 rounded">
                Featured
            </span>
        @endif
    </a>

    <div class="flex flex-col flex-1 p-5">
        @if ($product->categories->isNotEmpty())
            <p class="text-xs font-bold uppercase tracking-wide text-blue-600 mb-1.5">
                {{ $product->categories->first()->name }}
            </p>
        @endif

        <h3 class="text-base font-bold text-gray-900 leading-snug mb-2">
            <a href="{{ route('shop.product', $product->slug) }}" class="hover:text-blue-700 transition">
                {{ $product->name }}
            </a>
        </h3>

        @if (filled($product->short_description))
            <p class="text-sm text-gray-600 leading-relaxed mb-4">
                {{ Str::limit($product->short_description, 110) }}
            </p>
        @endif

        <div class="mt-auto pt-3 border-t border-gray-100">
            <div class="flex flex-wrap items-baseline gap-2">
                <span class="text-lg font-bold text-gray-900 tabular-nums">
                    {{ $product->priceSummaryLabel() }}
                </span>

                @if ($product->isOnOffer())
                    <span class="text-sm text-gray-400 line-through tabular-nums">
                        {{ App\Support\PaymentFigures::money((float) $product->compare_at_price) }}
                    </span>
                @endif
            </div>

            <p class="text-xs mt-1.5">
                @if ($soldOut)
                    <span class="text-red-600 font-semibold">Currently unavailable</span>
                @elseif ($showStock && $product->isLowStock())
                    <span class="text-amber-700 font-semibold">Only {{ $left }} left</span>
                @elseif ($product->hasVariants())
                    <span class="text-gray-500">
                        {{ $product->variants->count() }}
                        {{ Str::lower($product->option_name ?: 'option') }}{{ $product->variants->count() === 1 ? '' : 's' }}
                        available
                    </span>
                @else
                    <span class="text-green-700 font-semibold">Available</span>
                @endif
            </p>
        </div>
    </div>

</article>
