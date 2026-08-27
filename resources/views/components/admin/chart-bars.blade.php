{{--
    Vertical bars for a short series, built from divs rather than SVG.

    Divs suit bars better than SVG does: each bar sizes itself with a percentage
    height, so nothing is scaled non-uniformly and the corners stay round. Fourteen
    days is about the limit before the bars get too thin to read, which is why the
    area chart handles the longer windows.

    @param array  $points  [['label' => '3 Jan', 'value' => 4.0, 'note' => '...'], ...] oldest first
    @param string $tone    blue | green | amber | purple
    @param string $empty   what to say when every value is zero
--}}
@props([
    'points' => [],
    'tone' => 'blue',
    'empty' => 'Nothing recorded in this period yet.',
    'height' => 160,
])

@php
    // Written out in full so Tailwind finds the classes when it scans.
    $tones = [
        'blue' => ['bar' => 'bg-blue-500', 'hover' => 'group-hover:bg-blue-600'],
        'green' => ['bar' => 'bg-green-500', 'hover' => 'group-hover:bg-green-600'],
        'amber' => ['bar' => 'bg-amber-500', 'hover' => 'group-hover:bg-amber-600'],
        'purple' => ['bar' => 'bg-purple-500', 'hover' => 'group-hover:bg-purple-600'],
    ];

    $colour = $tones[$tone] ?? $tones['blue'];

    $values = array_map(fn (array $p) => (float) ($p['value'] ?? 0), $points);
    $count = count($values);
    $peak = $count > 0 ? max($values) : 0.0;
    $hasData = $peak > 0;
@endphp

<div {{ $attributes->merge(['class' => 'w-full']) }}>
    @if (! $hasData)
        <div class="flex flex-col items-center justify-center text-center px-4"
             style="height: {{ $height }}px">
            <x-admin.icon name="grid" class="w-8 h-8 text-gray-300" />
            <p class="text-sm text-gray-500 mt-2 max-w-xs">{{ $empty }}</p>
        </div>
    @else
        <div class="flex items-end gap-1" style="height: {{ $height }}px"
             role="img" aria-label="{{ $count }} day comparison, highest value {{ number_format($peak) }}">

            @foreach ($points as $point)
                @php
                    $value = (float) ($point['value'] ?? 0);
                    // A floor of 2% so a zero day is still a visible tick rather than
                    // a gap the eye reads as missing data.
                    $share = $peak > 0 ? max(2, ($value / $peak) * 100) : 2;
                @endphp

                <span class="flex-1 h-full flex items-end group" title="{{ $point['note'] ?? '' }}">
                    <span @class([
                            'w-full rounded-t transition',
                            $colour['bar'] => $value > 0,
                            $colour['hover'] => $value > 0,
                            'bg-gray-200' => $value <= 0,
                          ])
                          style="height: {{ round($share, 2) }}%"></span>
                </span>
            @endforeach
        </div>

        <div class="flex justify-between mt-2 px-0.5">
            <span class="text-xs text-gray-400">{{ $points[0]['label'] ?? '' }}</span>
            <span class="text-xs text-gray-400">{{ $points[$count - 1]['label'] ?? '' }}</span>
        </div>
    @endif
</div>
