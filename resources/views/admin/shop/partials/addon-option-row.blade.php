{{--
    One option row inside a product add-on, for example which side gets engraved.

    @param int|string $index      add-on index, or __INDEX__ in a template
    @param int|string $oIndex     option index, or __OINDEX__ in a template
    @param array      $row
    @param string     $miniInput
    @param string     $currency
--}}
@php
    $name = "addons[{$index}][variants][{$oIndex}]";
    $taken = (int) ($row['stock_taken'] ?? 0);
@endphp

<div class="grid grid-cols-1 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,1fr)_28px] gap-2 items-start"
     data-product-addon-option-row>

    @if (! empty($row['id']))
        <input type="hidden" name="{{ $name }}[id]" value="{{ $row['id'] }}">
    @endif

    <div>
        <label class="sm:hidden block text-[11px] font-semibold text-gray-500 mb-1">Label</label>
        <input type="text" name="{{ $name }}[label]" maxlength="60"
               value="{{ $row['label'] ?? '' }}"
               placeholder="e.g. Front"
               class="{{ $miniInput }} bg-white">
        @error("addons.{$index}.variants.{$oIndex}.label")
            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label class="sm:hidden block text-[11px] font-semibold text-gray-500 mb-1">Price ({{ $currency }})</label>
        {{-- Blank charges the add-on's price, the same rule a blank option price on
             the product itself follows. Only needed where this choice costs more. --}}
        <input type="number" name="{{ $name }}[price]" step="0.01" min="0" max="999999.99"
               value="{{ $row['price'] ?? '' }}"
               placeholder="Same as add-on"
               class="{{ $miniInput }} bg-white text-right tabular-nums">
        @error("addons.{$index}.variants.{$oIndex}.price")
            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label class="sm:hidden block text-[11px] font-semibold text-gray-500 mb-1">Stock</label>
        {{-- Cannot go below what has already been ordered, otherwise the count would
             claim fewer exist than have been sold. --}}
        <input type="number" name="{{ $name }}[stock]" min="{{ $taken }}" max="1000000"
               value="{{ $row['stock'] ?? '' }}"
               placeholder="Unlimited"
               class="{{ $miniInput }} bg-white text-right tabular-nums">

        @if ($taken > 0)
            <p class="text-[11px] text-amber-700 mt-1">{{ $taken }} already ordered</p>
        @endif
        @error("addons.{$index}.variants.{$oIndex}.stock")
            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
        @enderror
    </div>

    <div class="sm:pt-1.5">
        <button type="button" data-product-addon-option-remove
                @if ($taken > 0) data-product-addon-option-locked="{{ $taken }}" @endif
                class="inline-flex items-center justify-center w-7 h-7 rounded-md text-gray-400 hover:bg-red-50 hover:text-red-600 transition"
                title="{{ $taken > 0 ? 'Already ordered, set stock to ' . $taken . ' to stop selling it' : 'Remove this option' }}"
                aria-label="Remove option">
            <x-admin.icon name="close" class="w-4 h-4" />
        </button>
    </div>
</div>
