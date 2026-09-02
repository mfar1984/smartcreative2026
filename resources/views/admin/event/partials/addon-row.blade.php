{{--
    One add-on card in the builder.

    @param int|string $index      row index, or the literal __INDEX__ in a template
    @param array      $row        current values, empty for a blank row
    @param string     $miniInput  input classes
    @param string     $currency
--}}
@php
    $name = "addons[{$index}]";
    $variants = $row['variants'] ?? [];
    $variants = is_array($variants) ? $variants : [];

    // A blank row defaults to active: an operator adding an add-on means to
    // sell it. Required is off, because most extras are optional.
    $isActive = array_key_exists('is_active', $row) ? (bool) $row['is_active'] : true;
    $isRequired = (bool) ($row['is_required'] ?? false);
    $isTicked = (bool) ($row['is_checked_by_default'] ?? false);
    $reminder = (string) ($row['uncheck_reminder'] ?? '');

    // Rows that already have stock committed cannot be pulled out, so the
    // remove button explains itself rather than failing on save.
    $lockedVariants = collect($variants)->filter(fn ($v) => (int) ($v['stock_taken'] ?? 0) > 0);
@endphp

<div class="rounded-lg border border-gray-200 bg-white shadow-sm" data-addon-row>
    @if (! empty($row['id']))
        <input type="hidden" name="{{ $name }}[id]" value="{{ $row['id'] }}">
    @endif

    {{-- Card header --}}
    <div class="flex items-center gap-3 border-b border-gray-200 bg-gray-50 px-4 py-2.5">
        <span class="inline-flex items-center justify-center w-6 h-6 rounded-md bg-blue-600 text-white text-xs font-bold shrink-0"
              data-addon-number>#</span>

        <p class="flex-1 min-w-0 truncate text-sm font-semibold text-gray-700" data-addon-title>
            {{ filled($row['name'] ?? null) ? $row['name'] : 'New add-on' }}
        </p>

        @if ($lockedVariants->isNotEmpty())
            <span class="hidden sm:inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800">
                {{ $lockedVariants->sum(fn ($v) => (int) $v['stock_taken']) }} sold
            </span>
        @endif

        <button type="button" data-addon-remove
                class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold text-red-600 hover:bg-red-50 transition shrink-0">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
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
                       placeholder="e.g. Official Event Shirt"
                       data-addon-name
                       class="{{ $miniInput }}">
            </div>

            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">
                    Price ({{ $currency }}) <span class="text-red-600" aria-hidden="true">*</span>
                </label>
                <input type="number" name="{{ $name }}[price]" step="0.01" min="0" max="999999.99"
                       value="{{ $row['price'] ?? '' }}"
                       placeholder="0.00"
                       class="{{ $miniInput }}">
            </div>
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">Description</label>
            <input type="text" name="{{ $name }}[description]" maxlength="255"
                   value="{{ $row['description'] ?? '' }}"
                   placeholder="Shown under the add-on on the registration form"
                   class="{{ $miniInput }}">
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">Max Units Per Registration</label>
                <input type="number" name="{{ $name }}[max_quantity]" min="1" max="1000"
                       value="{{ $row['max_quantity'] ?? '' }}"
                       placeholder="No limit"
                       class="{{ $miniInput }}">
                <p class="text-[11px] text-gray-500 mt-1">
                    Counts every option together. Blank means stock is the only limit.
                </p>
            </div>

            <div class="flex flex-col justify-center gap-2.5 sm:pt-4">
                {{-- An unchecked box sends nothing, so a 0 is queued first and
                     the checkbox overrides it when ticked. --}}
                <input type="hidden" name="{{ $name }}[is_active]" value="0">
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="{{ $name }}[is_active]" value="1" @checked($isActive)
                           class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span class="text-xs font-semibold text-gray-700">Offer this add-on</span>
                </label>

                <input type="hidden" name="{{ $name }}[is_required]" value="0">
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="{{ $name }}[is_required]" value="1" @checked($isRequired)
                           data-addon-required
                           class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span class="text-xs font-semibold text-gray-700">Compulsory for every registration</span>
                </label>

                {{-- The third state: offered already ticked, but the buyer may
                     clear it. Disabled while the add-on is compulsory, because
                     there is nothing to opt out of. --}}
                <input type="hidden" name="{{ $name }}[is_checked_by_default]" value="0">
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="{{ $name }}[is_checked_by_default]" value="1"
                           @checked($isTicked) @disabled($isRequired)
                           data-addon-ticked
                           class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 disabled:opacity-40">
                    <span @class(['text-xs font-semibold', $isRequired ? 'text-gray-400' : 'text-gray-700'])
                          data-addon-ticked-label>Ticked by default, can be unticked</span>
                </label>
            </div>
        </div>

        {{-- Reminder, shown only while the add-on is offered ticked. This is what
             somebody sees when they clear the box on the registration form, so it
             is worth writing in terms of what they lose. --}}
        <div @class(['mt-3', 'hidden' => ! $isTicked || $isRequired]) data-addon-reminder-wrap>
            <label class="block text-xs font-semibold text-gray-600 mb-1">
                Reminder shown if they untick it
            </label>
            <textarea name="{{ $name }}[uncheck_reminder]" rows="2" maxlength="500"
                      placeholder="e.g. Without the Event Tee you will not have a team shirt on match day. Shirts cannot be ordered later."
                      class="{{ $miniInput }} bg-white resize-y">{{ $reminder }}</textarea>
            <p class="text-[11px] text-gray-500 mt-1">
                Leave blank to let them untick it without a message.
            </p>
        </div>

        {{-- ---------------- Options ---------------- --}}
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
            <div class="flex items-center justify-between gap-2 mb-2">
                <p class="text-xs font-bold uppercase tracking-wide text-gray-600">Options / Sizes</p>
                <button type="button" data-variant-add
                        class="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-100 transition">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Add Option
                </button>
            </div>

            {{-- Column headings, hidden with the list when there are none. --}}
            <div @class([
                'hidden sm:grid grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,1fr)_28px] gap-2 px-1 pb-1',
                'hidden' => count($variants) === 0,
            ]) data-variant-head>
                <span class="text-[11px] font-semibold text-gray-500">Label</span>
                <span class="text-[11px] font-semibold text-gray-500">Price override ({{ $currency }})</span>
                <span class="text-[11px] font-semibold text-gray-500">Stock</span>
                <span></span>
            </div>

            <div class="space-y-2" data-variant-list>
                @foreach ($variants as $j => $variant)
                    @include('admin.event.partials.addon-variant-row', [
                        'index' => $index,
                        'vIndex' => $j,
                        'row' => $variant,
                        'miniInput' => $miniInput,
                        'currency' => $currency,
                    ])
                @endforeach
            </div>

            <p @class(['text-xs text-gray-500 mt-1', 'hidden' => count($variants) > 0]) data-variant-empty>
                No options. The add-on is sold as a single item at the price above.
            </p>
        </div>
    </div>
</div>
