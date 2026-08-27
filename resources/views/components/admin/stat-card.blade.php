{{--
    One headline figure, with an optional movement against the period before it.

    Extracted from the markup the dashboard used to hold inline, so the stat row and
    any future one stay identical instead of drifting apart.

    The change is shown in words as well as by colour and arrow, because a red
    triangle on its own tells a colourblind reader nothing.

    @param string      $label
    @param string      $value    already formatted, so money and counts both fit
    @param string|null $note     small line under the figure
    @param string      $accent   blue | green | amber | purple | red
    @param string|null $icon
    @param string|null $href     makes the whole card a link
    @param float|null  $change   percentage against the previous period, null to hide
    @param string|null $changeNote  what the comparison is against
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
        'blue' => ['bg-blue-500', 'border-blue-500'],
        'green' => ['bg-green-500', 'border-green-500'],
        'amber' => ['bg-amber-500', 'border-amber-500'],
        'purple' => ['bg-purple-500', 'border-purple-500'],
        'red' => ['bg-red-500', 'border-red-500'],
    ];

    [$iconBg, $topBorder] = $accents[$accent] ?? $accents['blue'];

    $hasChange = $change !== null;
    $rising = $hasChange && $change > 0;
    $falling = $hasChange && $change < 0;

    $changeTone = match (true) {
        $rising => 'text-green-700 bg-green-50',
        $falling => 'text-red-700 bg-red-50',
        default => 'text-gray-600 bg-gray-100',
    };

    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif
    class="block bg-white rounded-xl border border-gray-200 shadow-sm border-t-4 {{ $topBorder }} p-5 @if ($href) hover:shadow-md transition @endif">

    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <span class="block text-xs text-gray-500 uppercase tracking-wide mb-1">{{ $label }}</span>
            <span class="block text-2xl font-bold text-gray-900 tabular-nums truncate">{{ $value }}</span>

            @if ($note)
                <span class="block text-xs text-gray-500 mt-1">{{ $note }}</span>
            @endif

            @if ($hasChange)
                <span class="inline-flex items-center gap-1 mt-2 rounded px-1.5 py-0.5 text-xs font-semibold {{ $changeTone }}">
                    <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        @if ($rising)
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>
                        @elseif ($falling)
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        @else
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14"/>
                        @endif
                    </svg>
                    {{ $rising ? '+' : '' }}{{ number_format($change, 1) }}%
                    <span class="font-normal">{{ $rising ? 'up' : ($falling ? 'down' : 'level') }}</span>
                </span>

                @if ($changeNote)
                    <span class="block text-xs text-gray-400 mt-1">{{ $changeNote }}</span>
                @endif
            @elseif ($changeNote)
                {{-- No previous figure to compare against. Said plainly rather than
                     showing a made up percentage. --}}
                <span class="block text-xs text-gray-400 mt-2">{{ $changeNote }}</span>
            @endif
        </div>

        @if ($icon)
            <span class="{{ $iconBg }} p-2.5 rounded-lg shrink-0" aria-hidden="true">
                <x-admin.icon :name="$icon" class="w-5 h-5 text-white" />
            </span>
        @endif
    </div>
</{{ $tag }}>
