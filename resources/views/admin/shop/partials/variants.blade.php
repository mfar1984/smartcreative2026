{{--
    Builder for a product's options, such as shirt sizes.

    Rows are named variants[i][...] and new ones are cloned from the <template> at
    the bottom with __INDEX__ swapped for a counter. The index only has to be unique
    within one submission, so gaps left by removing a row are harmless.

    @param \App\Models\ShopProduct $product
    @param string                  $input
--}}
@php
    // old() wins so a failed submission comes back exactly as it was typed,
    // otherwise fall back to what is stored.
    $variantRows = old('variants');

    if ($variantRows === null) {
        $variantRows = $product->exists
            ? $product->variants
                ->map(fn ($variant) => [
                    'id' => $variant->id,
                    'label' => $variant->label,
                    'sku' => $variant->sku,
                    'price' => $variant->price,
                    'stock' => $variant->stock,
                    'stock_taken' => $variant->stock_taken,
                ])
                ->values()
                ->all()
            : [];
    }

    $variantRows = is_array($variantRows) ? $variantRows : [];

    $miniInput = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
@endphp

<x-admin.panel title="Options" icon="tag">
    <div class="px-5 py-4">
        <p class="text-sm text-gray-600">
            Use options when the same product comes in choices, for example a shirt in S, M
            and L. Each option carries its own stock, and can carry its own price and SKU.
            Leave an option's price blank to charge the product price, and its stock blank
            for unlimited.
        </p>

        <p class="text-sm text-gray-500 mt-2">
            A product with no options is sold as one thing, using the stock figure above.
        </p>

        {{-- Above the list, so an error on a row the operator has scrolled past is
             still noticed. --}}
        @error('variants')
            <p class="text-xs text-red-600 mt-2">{{ $message }}</p>
        @enderror

        @error('option_name')
            <p class="text-xs text-red-600 mt-2">{{ $message }}</p>
        @enderror
    </div>

    <div class="px-5 pb-5">
        <x-admin.field-row
            label="What The Options Are"
            help="Becomes the heading buyers choose from, so it reads &quot;Choose a Size&quot; rather than &quot;Choose an option&quot;. Required once there is at least one option."
            for="option_name"
            error="option_name">
            <input type="text" id="option_name" name="option_name" maxlength="60"
                   value="{{ old('option_name', $product->option_name) }}"
                   placeholder="e.g. Size"
                   class="{{ $input }} max-w-xs">
        </x-admin.field-row>
    </div>

    <div class="px-5 pb-5">
        {{-- Column headings, hidden while the list is empty and on narrow screens
             where each field carries its own label instead. The heading uses
             sm:grid, so 'hidden' alone would lose to it; both are managed together
             by the script. --}}
        <div data-variant-head @class([
            'grid-cols-[minmax(0,1fr)_minmax(0,1fr)_7rem_7rem_2.5rem] gap-3 px-1 pb-2 mb-1 border-b border-gray-200',
            'hidden' => count($variantRows) === 0,
            'sm:grid' => count($variantRows) > 0,
        ])>
            <span class="text-xs font-bold uppercase tracking-wide text-gray-500">Option</span>
            <span class="text-xs font-bold uppercase tracking-wide text-gray-500">SKU</span>
            <span class="text-xs font-bold uppercase tracking-wide text-gray-500 text-right">Price</span>
            <span class="text-xs font-bold uppercase tracking-wide text-gray-500 text-right">Stock</span>
            <span class="sr-only">Remove</span>
        </div>

        <div id="variant-list" class="space-y-3">
            @foreach ($variantRows as $i => $row)
                @include('admin.shop.partials.variant-row', [
                    'index' => $i,
                    'row' => $row,
                    'miniInput' => $miniInput,
                ])
            @endforeach
        </div>

        <div id="variant-empty" @class([
            'rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-8 text-center',
            'hidden' => count($variantRows) > 0,
        ])>
            <x-admin.icon name="tag" class="w-6 h-6 mx-auto text-gray-400" />
            <p class="text-sm font-semibold text-gray-600 mt-2">No options yet</p>
            <p class="text-xs text-gray-500 mt-0.5">
                This product is sold as a single item. Add options if it comes in sizes or
                colours.
            </p>
        </div>

        <button type="button" id="variant-add"
                class="mt-4 inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm font-semibold text-blue-700 hover:bg-blue-100 transition">
            <x-admin.icon name="plus" class="w-4 h-4" />
            Add an Option
        </button>
    </div>
</x-admin.panel>

{{-- ------------------------------------------------------------------
     Template for cloning. Inert until the script copies it, so the
     __INDEX__ placeholder is never submitted.
     ----------------------------------------------------------------- --}}
<template id="variant-template">
    @include('admin.shop.partials.variant-row', [
        'index' => '__INDEX__',
        'row' => [],
        'miniInput' => $miniInput,
    ])
</template>
