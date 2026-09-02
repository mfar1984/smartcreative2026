{{--
    Builder for the paid extras attached to an event, such as a shirt with
    sizes.

    Rows are named addons[i][...] with nested addons[i][variants][j][...], and
    new rows are cloned from the <template> blocks at the bottom with __INDEX__
    and __VINDEX__ swapped for real numbers. The index only has to be unique
    within one submission, so gaps left by removing a row are harmless.

    Existing rows carry a hidden id so an edit updates the row in place instead
    of dropping it and creating a new one, which would lose its stock counts.

    @param \App\Models\Event $event
    @param string            $input     shared input classes
    @param string            $currency
--}}
@php
    // old() wins so a failed submission comes back exactly as it was typed,
    // otherwise fall back to what is stored.
    $addonRows = old('addons');

    if ($addonRows === null) {
        $addonRows = $event->addons
            ->map(fn ($addon) => [
                'id' => $addon->id,
                'name' => $addon->name,
                'description' => $addon->description,
                'price' => $addon->price,
                'max_quantity' => $addon->max_quantity,
                'is_required' => $addon->is_required ? '1' : '0',
                'is_checked_by_default' => $addon->is_checked_by_default ? '1' : '0',
                'uncheck_reminder' => $addon->uncheck_reminder,
                'is_active' => $addon->is_active ? '1' : '0',
                'variants' => $addon->variants
                    ->map(fn ($variant) => [
                        'id' => $variant->id,
                        'label' => $variant->label,
                        'price' => $variant->price,
                        'stock' => $variant->stock,
                        'stock_taken' => $variant->stock_taken,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    $addonRows = is_array($addonRows) ? $addonRows : [];

    $miniInput = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
@endphp

<x-admin.panel title="Paid Add-ons" icon="credit-card">
    <div class="px-5 py-4">
        <p class="text-sm text-gray-600">
            Extras a registrant may buy on top of the event price. Give an add-on options when
            it comes in choices, for example a shirt in S, M and L. Each option can carry its
            own price and stock.
            <span class="block mt-1">
                An option's <strong>Price</strong> is the figure charged for that option.
                Leave it <strong>blank</strong> to use the add-on price above, so one price does
                not have to be repeated across four sizes. Set it to <strong>0</strong> and that
                option is free: no money is shown against it on the registration form at all.
                A size that costs more, such as a 5XL, carries its own figure. Each option
                prints what it will do underneath.
            </span>
        </p>

        {{-- The block above the list, so an error on a row the operator has
             scrolled past is still noticed. --}}
        @error('addons')
            <p class="text-xs text-red-600 mt-2">{{ $message }}</p>
        @enderror

        @php
            $addonErrors = collect($errors->keys())->filter(fn ($key) => str_starts_with($key, 'addons.'));
        @endphp

        @if ($addonErrors->isNotEmpty())
            <div class="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3 mt-3">
                <x-admin.icon name="lock" class="w-4 h-4 mt-0.5 shrink-0 text-red-600" />
                <div class="text-xs text-red-800 space-y-0.5">
                    @foreach ($addonErrors as $key)
                        <p>{{ $errors->first($key) }}</p>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <div class="px-5 py-4">
        <div id="addon-list" class="space-y-4">
            @foreach ($addonRows as $i => $row)
                @include('admin.event.partials.addon-row', [
                    'index' => $i,
                    'row' => $row,
                    'miniInput' => $miniInput,
                    'currency' => $currency,
                ])
            @endforeach
        </div>

        {{-- Shown only while the list is empty, so the panel never looks broken. --}}
        <div id="addon-empty" @class([
            'rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-8 text-center',
            'hidden' => count($addonRows) > 0,
        ])>
            <x-admin.icon name="credit-card" class="w-6 h-6 mx-auto text-gray-400" />
            <p class="text-sm font-semibold text-gray-600 mt-2">No add-ons yet</p>
            <p class="text-xs text-gray-500 mt-0.5">
                This event charges the event price only. Add one below if there is
                merchandise or an optional extra to sell.
            </p>
        </div>

        <button type="button" id="addon-add"
                class="mt-4 inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm font-semibold text-blue-700 hover:bg-blue-100 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Add an Add-on
        </button>
    </div>
</x-admin.panel>

{{-- ------------------------------------------------------------------
     Templates for cloning. Inert until JS copies them, so the __INDEX__
     placeholders are never submitted.
     ----------------------------------------------------------------- --}}
<template id="addon-template">
    @include('admin.event.partials.addon-row', [
        'index' => '__INDEX__',
        'row' => [],
        'miniInput' => $miniInput,
        'currency' => $currency,
    ])
</template>

<template id="variant-template">
    @include('admin.event.partials.addon-variant-row', [
        'index' => '__INDEX__',
        'vIndex' => '__VINDEX__',
        'row' => [],
        'miniInput' => $miniInput,
        'currency' => $currency,
    ])
</template>
