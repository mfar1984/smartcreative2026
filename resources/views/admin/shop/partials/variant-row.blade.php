{{--
    One option of a product, for example a shirt size.

    @param int|string $index      row index, or __INDEX__ inside the clone template
    @param array      $row        existing values, empty for a new row
    @param string     $miniInput  shared input classes
--}}
@php
    $name = "variants[{$index}]";
    $taken = (int) ($row['stock_taken'] ?? 0);
@endphp

<div data-variant-row class="grid grid-cols-1 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_7rem_7rem_2.5rem] gap-2 sm:gap-3 items-start">

    {{-- Only present for a saved option, so an edit updates the row in place
         instead of dropping it and losing its stock count. --}}
    @if (! empty($row['id']))
        <input type="hidden" name="{{ $name }}[id]" value="{{ $row['id'] }}">
    @endif

    <div>
        <label class="sm:sr-only block text-xs font-semibold text-gray-600 mb-1">Option</label>
        <input type="text" name="{{ $name }}[label]" maxlength="60"
               value="{{ $row['label'] ?? '' }}"
               placeholder="e.g. Size M"
               class="{{ $miniInput }}">
        @error("variants.{$index}.label")
            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label class="sm:sr-only block text-xs font-semibold text-gray-600 mb-1">SKU</label>
        <input type="text" name="{{ $name }}[sku]" maxlength="80"
               value="{{ $row['sku'] ?? '' }}"
               placeholder="Optional"
               class="{{ $miniInput }}">
        @error("variants.{$index}.sku")
            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label class="sm:sr-only block text-xs font-semibold text-gray-600 mb-1">Price</label>
        <input type="number" name="{{ $name }}[price]" step="0.01" min="0" max="999999.99"
               value="{{ $row['price'] ?? '' }}"
               placeholder="Same as product"
               class="{{ $miniInput }} text-right tabular-nums">
        @error("variants.{$index}.price")
            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label class="sm:sr-only block text-xs font-semibold text-gray-600 mb-1">Stock</label>
        {{-- Cannot go below what has already been ordered, otherwise the count
             would claim fewer exist than have been sold. --}}
        <input type="number" name="{{ $name }}[stock]" step="1" min="{{ $taken }}" max="1000000"
               value="{{ $row['stock'] ?? '' }}"
               placeholder="Unlimited"
               class="{{ $miniInput }} text-right tabular-nums">
        @error("variants.{$index}.stock")
            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
        @enderror
    </div>

    <div class="sm:pt-0">
        <button type="button" data-variant-remove
                @if ($taken > 0) data-variant-locked="{{ $taken }}" @endif
                class="p-2 rounded-lg text-red-600 hover:bg-red-50 transition"
                aria-label="Remove this option">
            <x-admin.icon name="trash" class="w-4 h-4" />
        </button>
    </div>

</div>
