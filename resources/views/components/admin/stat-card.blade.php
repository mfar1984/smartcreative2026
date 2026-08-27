{{--
    One headline figure, with an optional movement against the period before it.

    The icon sits inline with the label rather than in a badge on the right. That
    was the change that let five of these fit one even row: with the badge beside
    it, the value only had about 155px and "RM 3,601.00" was already truncating.
    Now the value has the full width of the card.

    The change is given in words as well as by colour and arrow, because a red
    triangle on its own tells a colourblind reader nothing.

    @param string      $label
    @param string      $value    already formatted, so money and counts both fit
    @param string|null $note     small line under the figure
    @param string      $accent   blue | green | amber | purple | red
    @param string|null $icon
    @param string|null $href     makes the whole card a link
    @param float|null  $change   percentage against the previous period, null to hide
    @param string|null $changeNote  what the comparison is against, shown on hover
--}}
@props([
    'label',
    'value',
    'note' => null,
    'accent' => 'blue',
    'icon' => null,
    'href' => null,
    'change' => null,
    'changeNote' => null,
])

@php
    // Written out in full so Tailwind finds the classes when it scans.
    $accents = [
        'blue' => ['text-blue-600', 'bg-blue-50', 'border-t-blue-500'],
        'green' => ['text-green-600', 'bg-green-50', 'border-t-green-500'],
        'amber' => ['text-amber-600', 'bg-amber-50', 'border-t-amber-500'],
        'purple' => ['text-purple-600', 'bg-purple-50', 'border-t-purple-500'],
        'red' => ['text-red-600', 'bg-red-50', 'border-t-red-500'],
    ];

    [$iconColour, $iconBg, $topBorder] = $accents[$accent] ?? $accents['blue'];

    $hasChange = $change !== null;
    $rising = $hasChange && $change > 0;
    $falling = $hasChange && $change < 0;

    $changeTone = match (true) {
        $rising => 'text-green-700',
        $falling => 'text-red-700',
        default => 'text-gray-500',
    };

    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif
    @if ($changeNote) title="{{ $changeNote }}" @endif
    class="flex flex-col bg-white rounded-xl border border-gray-200 border-t-2 {{ $topBorder }} shadow-sm px-4 py-3.5 @if ($href) hover:shadow-md transition @endif">

    <div class="flex items-center gap-2 mb-2">
        @if ($icon)
            <span class="{{ $iconBg }} p-1.5 rounded-md shrink-0" aria-hidden="true">
                <x-admin.icon :name="$icon" class="w-3.5 h-3.5 {{ $iconColour }}" />
            </span>
        @endif

        <span class="text-xs font-bold uppercase tracking-wide text-gray-500 truncate">{{ $label }}</span>
    </div>

    <span class="block text-2xl font-bold text-gray-900 tabular-nums leading-tight truncate">{{ $value }}</span>

    {{-- mt-auto keeps the bottom line flush across the row even when one card has a
         longer note than its neighbours. --}}
    <div class="mt-auto pt-1.5">
        @if ($note)
            <span class="block text-xs text-gray-500 truncate">{{ $note }}</span>
        @endif

        @if ($hasChange)
            <span class="inline-flex items-center gap-1 mt-1 text-xs font-semibold {{ $changeTone }}">
                <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    @if ($rising)
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 15l7-7 7 7"/>
                    @elseif ($falling)
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
                    @else
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 12h14"/>
                    @endif
                </svg>
                {{ $rising ? '+' : '' }}{{ number_format($change, 1) }}%
                <span class="font-normal text-gray-400">{{ $rising ? 'up' : ($falling ? 'down' : 'level') }}</span>
            </span>
        @endif
    </div>
</{{ $tag }}>
