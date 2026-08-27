{{--
    One money figure with its label, for the row of totals at the top of a
    payments screen.

    @param string      $label
    @param string      $value
    @param string|null $note    small line under the figure
    @param string      $tone    green | amber | red | gray
    @param string|null $icon
    @param string|null $href    makes the whole card a link
--}}
@props([
    'label',
    'value',
    'note' => null,
    'tone' => 'gray',
    'icon' => null,
    'href' => null,
])

@php
    // Written out in full so Tailwind finds them when scanning.
    $tones = [
        'green' => ['border-green-200', 'bg-green-50', 'text-green-700', 'text-green-900'],
        'amber' => ['border-amber-200', 'bg-amber-50', 'text-amber-700', 'text-amber-900'],
        'red' => ['border-red-200', 'bg-red-50', 'text-red-700', 'text-red-900'],
        'gray' => ['border-gray-200', 'bg-white', 'text-gray-500', 'text-gray-900'],
    ];

    [$border, $bg, $labelColour, $valueColour] = $tones[$tone] ?? $tones['gray'];

    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif
    class="block rounded-lg border {{ $border }} {{ $bg }} px-4 py-3.5 @if ($href) hover:shadow-sm transition @endif">

    <div class="flex items-center gap-1.5">
        @if ($icon)
            <x-admin.icon :name="$icon" class="w-3.5 h-3.5 {{ $labelColour }} shrink-0" />
        @endif
        <p class="text-xs font-bold uppercase tracking-wide {{ $labelColour }}">{{ $label }}</p>
    </div>

    <p class="mt-1.5 text-2xl font-bold {{ $valueColour }} tabular-nums">{{ $value }}</p>

    @if ($note)
        <p class="mt-0.5 text-xs {{ $labelColour }}">{{ $note }}</p>
    @endif
</{{ $tag }}>
