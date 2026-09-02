{{--
    One option row inside an add-on, for example a shirt size.

    @param int|string $index      add-on index, or __INDEX__ in a template
    @param int|string $vIndex     option index, or __VINDEX__ in a template
    @param array      $row
    @param string     $miniInput
    @param string     $currency
--}}
@php
    $name = "addons[{$index}][variants][{$vIndex}]";
    $taken = (int) ($row['stock_taken'] ?? 0);
@endphp

<div class="grid grid-cols-1 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,1fr)_28px] gap-2 items-start"
     data-variant-row>

    @if (! empty($row['id']))
        <input type="hidden" name="{{ $name }}[id]" value="{{ $row['id'] }}">
    @endif

    <div>
        <label class="sm:hidden block text-[11px] font-semibold text-gray-500 mb-1">Label</label>
        <input type="text" name="{{ $name }}[label]" maxlength="60"
               value="{{ $row['label'] ?? '' }}"
               placeholder="e.g. Size M"
               class="{{ $miniInput }} bg-white">
    </div>

    <div>
        <label class="sm:hidden block text-[11px] font-semibold text-gray-500 mb-1">Price ({{ $currency }})</label>
        {{-- Only needed when this size costs more than the add-on. Blank, and a 0,
             both mean "same as the add-on": one field cannot also mean "free"
             without contradicting the price above it. Free is set on the add-on
             price. The note underneath says which rule is in force. --}}
        <input type="number" name="{{ $name }}[price]" step="0.01" min="0" max="999999.99"
               value="{{ $row['price'] ?? '' }}"
               placeholder="Same as add-on"
               data-variant-price
               title="Only for a size that costs more. Blank or 0 charges the add-on price."
               class="{{ $miniInput }} bg-white">

        <p class="mt-1 text-[11px] text-gray-500" data-variant-charge></p>
    </div>

    <div>
        <label class="sm:hidden block text-[11px] font-semibold text-gray-500 mb-1">Stock</label>
        <input type="number" name="{{ $name }}[stock]" min="{{ $taken }}" max="1000000"
               value="{{ $row['stock'] ?? '' }}"
               placeholder="Unlimited"
               class="{{ $miniInput }} bg-white">

        @if ($taken > 0)
            {{-- Stock already committed, so the figure above cannot go lower
                 and the row cannot be removed. --}}
            <p class="text-[11px] text-amber-700 mt-1">{{ $taken }} already ordered</p>
        @endif
    </div>

    <div class="sm:pt-1.5">
        <button type="button" data-variant-remove
                @if ($taken > 0) data-variant-locked="{{ $taken }}" @endif
                class="inline-flex items-center justify-center w-7 h-7 rounded-md text-gray-400 hover:bg-red-50 hover:text-red-600 transition"
                title="{{ $taken > 0 ? 'Already ordered, set stock to ' . $taken . ' to stop selling it' : 'Remove this option' }}"
                aria-label="Remove option">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>
</div>
