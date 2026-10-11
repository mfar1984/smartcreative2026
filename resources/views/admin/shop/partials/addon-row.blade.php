{{--
    One add-on card in the product builder.

    @param int|string $index      row index, or the literal __INDEX__ in a template
    @param array      $row        current values, empty for a blank row
    @param string     $miniInput  input classes
    @param string     $currency
--}}
@php
    $name = "addons[{$index}]";

    $options = $row['variants'] ?? [];
    $options = is_array($options) ? $options : [];

    // A blank row defaults to offered: an operator adding an add-on means to sell
    // it. Compulsory is off, because most extras are optional.
    $isActive = array_key_exists('is_active', $row) ? (bool) $row['is_active'] : true;
    $isRequired = (bool) ($row['is_required'] ?? false);

    // Radio for a blank row, because that is what every add-on did before the
    // dropdown existed and it is the safer of the two: one answer, not several.
    $selectionType = (string) ($row['selection_type'] ?? \App\Models\ShopProductAddon::SELECTION_RADIO);

    $taken = (int) ($row['stock_taken'] ?? 0);
    $soldOptions = collect($options)->filter(fn ($option) => (int) ($option['stock_taken'] ?? 0) > 0);
    $sold = $taken + (int) $soldOptions->sum(fn ($option) => (int) $option['stock_taken']);
@endphp

<div class="rounded-lg border border-gray-200 bg-white shadow-sm" data-product-addon-row>
    {{-- Only present for a saved add-on, so an edit updates the row in place
         instead of dropping it and losing its stock count. --}}
    @if (! empty($row['id']))
        <input type="hidden" name="{{ $name }}[id]" value="{{ $row['id'] }}">
    @endif

    {{-- Card header --}}
    <div class="flex items-center gap-3 border-b border-gray-200 bg-gray-50 px-4 py-2.5">
        <p class="flex-1 min-w-0 truncate text-sm font-semibold text-gray-700">
            {{ filled($row['name'] ?? null) ? $row['name'] : 'New add-on' }}
        </p>

        @if ($sold > 0)
            <span class="hidden sm:inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800">
                {{ $sold }} sold
            </span>
        @endif

        <button type="button" data-product-addon-remove
                @if ($sold > 0) data-product-addon-locked="{{ $sold }}" @endif
                class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold text-red-600 hover:bg-red-50 transition shrink-0">
            <x-admin.icon name="trash" class="w-3.5 h-3.5" />
            Remove
        </button>
    </div>

    <div class="p-4 space-y-3">
        {{-- Name and price --}}
        <div class="grid grid-cols-1 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] gap-3">
            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">
                    Add-on Name <span class="text-red-600" aria-hidden="true">*</span>
                </label>
                <input type="text" name="{{ $name }}[name]" maxlength="180"
                       value="{{ $row['name'] ?? '' }}"
                       placeholder="e.g. Laser Engraving"
                       class="{{ $miniInput }}">
                @error("addons.{$index}.name")
                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">
                    Price ({{ $currency }}) <span class="text-red-600" aria-hidden="true">*</span>
                </label>
                <input type="number" name="{{ $name }}[price]" step="0.01" min="0" max="999999.99"
                       value="{{ $row['price'] ?? '' }}"
                       placeholder="0.00"
                       class="{{ $miniInput }} text-right tabular-nums">
                @error("addons.{$index}.price")
                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">Description</label>
            <input type="text" name="{{ $name }}[description]" maxlength="255"
                   value="{{ $row['description'] ?? '' }}"
                   placeholder="Shown under the add-on on the product page"
                   class="{{ $miniInput }}">
            @error("addons.{$index}.description")
                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Input Type</label>
                    <select name="{{ $name }}[selection_type]"
                            class="{{ $miniInput }} bg-white">
                        @foreach (\App\Models\ShopProductAddon::SELECTION_TYPES as $value => $label)
                            <option value="{{ $value }}" @selected($selectionType === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="text-[11px] text-gray-500 mt-1">
                        Only applies once the add-on has options. With none it is a single tick
                        box, because there is nothing to choose between.
                    </p>
                    @error("addons.{$index}.selection_type")
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Max Per Order</label>
                    <input type="number" name="{{ $name }}[max_quantity]" min="1" max="1000"
                           value="{{ $row['max_quantity'] ?? '' }}"
                           placeholder="No limit"
                           class="{{ $miniInput }} text-right tabular-nums">
                    <p class="text-[11px] text-gray-500 mt-1">
                        Blank means stock is the only limit.
                    </p>
                    @error("addons.{$index}.max_quantity")
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Stock</label>
                    {{-- Cannot go below what has already been ordered, otherwise the
                         count would claim fewer exist than have been sold. --}}
                    <input type="number" name="{{ $name }}[stock]" min="{{ $taken }}" max="1000000"
                           value="{{ $row['stock'] ?? '' }}"
                           placeholder="Unlimited"
                           class="{{ $miniInput }} text-right tabular-nums">
                    <p class="text-[11px] text-gray-500 mt-1">
                        Ignored once the add-on has options, because each option carries its own.
                    </p>
                    @if ($taken > 0)
                        <p class="text-[11px] text-amber-700 mt-1">{{ $taken }} already ordered</p>
                    @endif
                    @error("addons.{$index}.stock")
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="flex flex-col justify-center gap-2.5">
                {{-- An unticked box sends nothing, so a 0 is queued first and the
                     checkbox overrides it when ticked. --}}
                <input type="hidden" name="{{ $name }}[is_active]" value="0">
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="{{ $name }}[is_active]" value="1" @checked($isActive)
                           class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span class="text-xs font-semibold text-gray-700">Offer this add-on</span>
                </label>

                <input type="hidden" name="{{ $name }}[is_required]" value="0">
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="{{ $name }}[is_required]" value="1" @checked($isRequired)
                           class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span class="text-xs font-semibold text-gray-700">Compulsory &mdash; the buyer must choose it</span>
                </label>

                <p class="text-[11px] text-gray-500">
                    Compulsory with options means a choice has to be made before the basket
                    takes the line. With no options it is simply charged on every purchase.
                </p>
            </div>
        </div>

        {{-- ---------------- Options ---------------- --}}
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
            <div class="flex items-center justify-between gap-2 mb-2">
                <p class="text-xs font-bold uppercase tracking-wide text-gray-600">Options</p>
                <button type="button" data-product-addon-option-add
                        class="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-100 transition">
                    <x-admin.icon name="plus" class="w-3.5 h-3.5" />
                    Add Option
                </button>
            </div>

            <div class="space-y-2" data-product-addon-option-list>
                @foreach ($options as $j => $option)
                    @include('admin.shop.partials.addon-option-row', [
                        'index' => $index,
                        'oIndex' => $j,
                        'row' => $option,
                        'miniInput' => $miniInput,
                        'currency' => $currency,
                    ])
                @endforeach
            </div>

            <p @class(['text-xs text-gray-500 mt-1', 'hidden' => count($options) > 0]) data-product-addon-option-empty>
                No options. The add-on is sold as a single extra at the price above.
            </p>
        </div>
    </div>
</div>
