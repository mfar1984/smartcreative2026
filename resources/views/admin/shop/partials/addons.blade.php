{{--
    Builder for a product's add-ons: extras a buyer takes alongside whichever
    option they chose, such as laser engraving on a medal.

    Rows are named addons[i][...] with nested addons[i][variants][j][...], and new
    rows are cloned from the <template> blocks at the bottom with __INDEX__ and
    __OINDEX__ swapped for real numbers. The index only has to be unique within one
    submission, so gaps left by removing a row are harmless.

    Every id and data attribute here is prefixed product-addon-, because the Options
    builder on the same page already owns #variant-list, #variant-template and
    [data-variant-row]. Sharing them would have one builder cloning the other's rows.

    @param \App\Models\ShopProduct $product
    @param string                  $input     shared input classes
    @param string                  $currency
--}}
@php
    // old() wins so a failed submission comes back exactly as it was typed,
    // otherwise fall back to what is stored.
    $addonRows = old('addons');

    if ($addonRows === null) {
        $addonRows = $product->exists
            ? $product->addons
                ->map(fn ($addon) => [
                    'id' => $addon->id,
                    'name' => $addon->name,
                    'description' => $addon->description,
                    'price' => $addon->price,
                    'max_quantity' => $addon->max_quantity,
                    'stock' => $addon->stock,
                    'stock_taken' => $addon->stock_taken,
                    'is_required' => $addon->is_required ? '1' : '0',
                    'is_active' => $addon->is_active ? '1' : '0',
                    'selection_type' => $addon->selection_type ?? \App\Models\ShopProductAddon::SELECTION_RADIO,
                    'variants' => $addon->variants
                        ->map(fn ($option) => [
                            'id' => $option->id,
                            'label' => $option->label,
                            'price' => $option->price,
                            'stock' => $option->stock,
                            'stock_taken' => $option->stock_taken,
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all()
            : [];
    }

    $addonRows = is_array($addonRows) ? $addonRows : [];

    $miniInput = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
@endphp

<x-admin.panel title="Add-ons" icon="credit-card">
    <div class="px-5 py-4">
        <p class="text-sm text-gray-600">
            Extras a buyer can take on top of the product, separately from its options.
            A medal might come in three sizes <em>and</em> offer engraving: the size is
            which medal, the add-on is what else goes with it.
            <span class="block mt-1">
                Give an add-on options when the extra itself comes in choices, for example
                engraving on the front or on both sides. Leave an option's price blank and
                it charges the add-on price; set one only where that choice costs more.
                Leave stock blank for unlimited.
            </span>
        </p>

        {{-- Above the list, so an error on a row the operator has scrolled past is
             still noticed. --}}
        @error('addons')
            <p class="text-xs text-red-600 mt-2">{{ $message }}</p>
        @enderror

        @error('addon_group_name')
            <p class="text-xs text-red-600 mt-2">{{ $message }}</p>
        @enderror

        @php
            $productAddonErrors = collect($errors->keys())->filter(fn ($key) => str_starts_with($key, 'addons.'));
        @endphp

        @if ($productAddonErrors->isNotEmpty())
            <div class="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3 mt-3">
                <x-admin.icon name="warning" class="w-4 h-4 mt-0.5 shrink-0 text-red-600" />
                <div class="text-xs text-red-800 space-y-0.5">
                    @foreach ($productAddonErrors as $key)
                        <p>{{ $errors->first($key) }}</p>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- No padding wrapper: the panel already divides its rows and field-row
         carries its own px-5 py-4, so adding another here doubled the indent and
         crowded the divider below. --}}
    <div>
        <x-admin.field-row
            label="What The Add-ons Are"
            help="Becomes the heading buyers see above the list, so it reads “Add On Items” rather than “Extras”. Leave it blank to keep Extras."
            for="addon_group_name"
            error="addon_group_name">
            <input type="text" id="addon_group_name" name="addon_group_name" maxlength="60"
                   value="{{ old('addon_group_name', $product->addon_group_name) }}"
                   placeholder="e.g. Extras"
                   class="{{ $input }} max-w-xs">
        </x-admin.field-row>
    </div>

    <div class="px-5 pb-5">
        <div id="product-addon-list" class="space-y-4">
            @foreach ($addonRows as $i => $row)
                @include('admin.shop.partials.addon-row', [
                    'index' => $i,
                    'row' => $row,
                    'miniInput' => $miniInput,
                    'currency' => $currency,
                ])
            @endforeach
        </div>

        {{-- Shown only while the list is empty, so the panel never looks broken. --}}
        <div id="product-addon-empty" @class([
            'rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-8 text-center',
            'hidden' => count($addonRows) > 0,
        ])>
            <x-admin.icon name="credit-card" class="w-6 h-6 mx-auto text-gray-400" />
            <p class="text-sm font-semibold text-gray-600 mt-2">No add-ons yet</p>
            <p class="text-xs text-gray-500 mt-0.5">
                This product is sold at its own price only. Add one below if there is an
                extra to sell with it.
            </p>
        </div>

        <button type="button" id="product-addon-add"
                class="mt-4 inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm font-semibold text-blue-700 hover:bg-blue-100 transition">
            <x-admin.icon name="plus" class="w-4 h-4" />
            Add an Add-on
        </button>
    </div>
</x-admin.panel>

{{-- ------------------------------------------------------------------
     Templates for cloning. Inert until the script copies them, so the
     __INDEX__ and __OINDEX__ placeholders are never submitted.
     ----------------------------------------------------------------- --}}
<template id="product-addon-template">
    @include('admin.shop.partials.addon-row', [
        'index' => '__INDEX__',
        'row' => [],
        'miniInput' => $miniInput,
        'currency' => $currency,
    ])
</template>

<template id="product-addon-option-template">
    @include('admin.shop.partials.addon-option-row', [
        'index' => '__INDEX__',
        'oIndex' => '__OINDEX__',
        'row' => [],
        'miniInput' => $miniInput,
        'currency' => $currency,
    ])
</template>
