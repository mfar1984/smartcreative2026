{{--
    Grouping card inside a tab, for example "Site Identity".

    Rows placed in the slot are separated by a divider automatically, so a
    field-row does not need its own border.

    @param string      $title
    @param string|null $icon
    @param bool        $flush  true to drop the row padding wrapper (for tables)
--}}
@props([
    'title',
    'icon' => null,
    'flush' => false,
])

<div {{ $attributes->merge(['class' => 'bg-white rounded-lg border border-gray-200 mb-5 last:mb-0 overflow-hidden']) }}>

    <div class="flex items-center gap-2 px-5 py-3 border-b border-gray-200 bg-gray-50">
        @if ($icon)
            <x-admin.icon :name="$icon" class="w-4 h-4 text-blue-600 shrink-0" />
        @endif
        <h3 class="text-xs font-bold uppercase tracking-wide text-gray-700">{{ $title }}</h3>
    </div>

    <div @class(['divide-y divide-gray-100' => ! $flush])>
        {{ $slot }}
    </div>
</div>
