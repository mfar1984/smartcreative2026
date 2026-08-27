{{--
    A trend line over time, drawn as inline SVG.

    No charting library and no JavaScript. This project has no runtime JS
    dependencies at all and every icon is hand written SVG, so a chart built the
    same way needs no npm package, no build step, and works with scripts blocked.

    The SVG uses a viewBox with preserveAspectRatio="none" so it stretches to
    whatever width the card gives it. That distorts the stroke slightly on very wide
    cards, which is the trade for not measuring the container in JavaScript.

    @param array  $points  [['label' => '3 Jan', 'value' => 120.0, 'note' => '...'], ...] oldest first
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
        'blue' => ['stroke' => 'stroke-blue-500', 'fill' => 'text-blue-500', 'dot' => 'fill-blue-600'],
        'green' => ['stroke' => 'stroke-green-500', 'fill' => 'text-green-500', 'dot' => 'fill-green-600'],
        'amber' => ['stroke' => 'stroke-amber-500', 'fill' => 'text-amber-500', 'dot' => 'fill-amber-600'],
        'purple' => ['stroke' => 'stroke-purple-500', 'fill' => 'text-purple-500', 'dot' => 'fill-purple-600'],
    ];

    $colour = $tones[$tone] ?? $tones['blue'];

    $values = array_map(fn (array $p) => (float) ($p['value'] ?? 0), $points);
    $count = count($values);
    $peak = $count > 0 ? max($values) : 0.0;
    $hasData = $peak > 0;

    /*
     | The grid is 100 wide by 100 tall in user units, and the viewBox scales it.
     | Working in a fixed square keeps the maths readable: an x is just the point's
     | position as a percentage, and a y is its value inverted because SVG counts
     | downwards from the top.
     */
    $step = $count > 1 ? 100 / ($count - 1) : 0;

    $coords = [];

    foreach ($values as $i => $value) {
        $coords[] = [
            'x' => round($i * $step, 3),
            // A hair of headroom at the top so the peak is not clipped by the border.
            'y' => round(100 - ($peak > 0 ? ($value / $peak) * 94 : 0), 3),
            'value' => $value,
            'label' => $points[$i]['label'] ?? '',
            'note' => $points[$i]['note'] ?? '',
        ];
    }

    $line = implode(' ', array_map(fn (array $c) => "{$c['x']},{$c['y']}", $coords));

    // The same path closed along the bottom, so the area beneath can be tinted.
    $area = $count > 0
        ? "0,100 {$line} 100,100"
        : '';
@endphp

<div {{ $attributes->merge(['class' => 'w-full']) }}>
    @if (! $hasData)
        <div class="flex flex-col items-center justify-center text-center px-4"
             style="height: {{ $height }}px">
            <x-admin.icon name="activity" class="w-8 h-8 text-gray-300" />
            <p class="text-sm text-gray-500 mt-2 max-w-xs">{{ $empty }}</p>
        </div>
    @else
        <div class="relative" style="height: {{ $height }}px">
            <svg viewBox="0 0 100 100" preserveAspectRatio="none"
                 class="w-full h-full overflow-visible" role="img"
                 aria-label="{{ $count }} day trend, highest value {{ number_format($peak, 2) }}">

                {{-- Three faint rules, so a reader can judge height without a y axis. --}}
                @foreach ([25, 50, 75] as $rule)
                    <line x1="0" y1="{{ $rule }}" x2="100" y2="{{ $rule }}"
                          class="stroke-gray-100" stroke-width="0.5" vector-effect="non-scaling-stroke" />
                @endforeach

                <polygon points="{{ $area }}" class="{{ $colour['fill'] }}" fill="currentColor" opacity="0.10" />

                {{-- vector-effect keeps the stroke an even thickness despite the
                     non-uniform scaling above. Without it the line thins out
                     horizontally on a wide card. --}}
                <polyline points="{{ $line }}" fill="none"
                          class="{{ $colour['stroke'] }}" stroke-width="2"
                          stroke-linecap="round" stroke-linejoin="round"
                          vector-effect="non-scaling-stroke" />
            </svg>

            {{-- Hover targets as positioned divs rather than SVG shapes, because a
                 title on a scaled SVG element lands in the wrong place. --}}
            <div class="absolute inset-0 flex">
                @foreach ($coords as $c)
                    <span class="flex-1 group relative" title="{{ $c['note'] }}">
                        <span class="absolute inset-y-0 left-1/2 w-px bg-gray-300 opacity-0 group-hover:opacity-100 transition"
                              aria-hidden="true"></span>
                        <span class="absolute w-1.5 h-1.5 rounded-full {{ $colour['dot'] }} opacity-0 group-hover:opacity-100 transition"
                              style="left: 50%; top: {{ $c['y'] }}%; transform: translate(-50%, -50%)"
                              aria-hidden="true"></span>
                    </span>
                @endforeach
            </div>
        </div>

        {{-- First, middle and last only. Thirty date labels across a card this wide
             would be unreadable. --}}
        <div class="flex justify-between mt-2 px-0.5">
            <span class="text-xs text-gray-400">{{ $points[0]['label'] ?? '' }}</span>
            @if ($count > 2)
                <span class="text-xs text-gray-400 hidden sm:inline">
                    {{ $points[intdiv($count, 2)]['label'] ?? '' }}
                </span>
            @endif
            <span class="text-xs text-gray-400">{{ $points[$count - 1]['label'] ?? '' }}</span>
        </div>
    @endif
</div>
