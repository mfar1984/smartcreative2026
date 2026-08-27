{{--
    A trend line over time, drawn as inline SVG.

    No charting library and no JavaScript. This project has no runtime JS
    dependencies at all and every icon is hand written SVG, so a chart built the
    same way needs no npm package, no build step, and works with scripts blocked.

    The line is smoothed with cubic beziers rather than drawn as straight segments.
    The control points are placed level with their own endpoint, which means the
    curve can never overshoot above or below the two values it joins. That matters
    on a money chart: a prettier curve that dips under zero between two positive
    days would be drawing something that did not happen.

    @param array  $points  [['label' => '3 Jan', 'value' => 120.0, 'note' => '...'], ...] oldest first
    @param string $tone    blue | green | amber | purple
    @param string $empty   what to say when every value is zero
    @param string|null $peakLabel  formatted highest value, shown on the axis
--}}
@props([
    'points' => [],
    'tone' => 'blue',
    'empty' => 'Nothing recorded in this period yet.',
    'height' => 180,
    'peakLabel' => null,
])

@php
    // Written out in full so Tailwind finds the classes when it scans.
    $tones = [
        'blue' => ['stroke' => 'stroke-blue-500', 'text' => 'text-blue-500', 'dot' => 'bg-blue-600'],
        'green' => ['stroke' => 'stroke-green-500', 'text' => 'text-green-500', 'dot' => 'bg-green-600'],
        'amber' => ['stroke' => 'stroke-amber-500', 'text' => 'text-amber-500', 'dot' => 'bg-amber-600'],
        'purple' => ['stroke' => 'stroke-purple-500', 'text' => 'text-purple-500', 'dot' => 'bg-purple-600'],
    ];

    $colour = $tones[$tone] ?? $tones['blue'];

    $values = array_map(fn (array $p) => (float) ($p['value'] ?? 0), $points);
    $count = count($values);
    $peak = $count > 0 ? max($values) : 0.0;
    $hasData = $peak > 0;

    // Unique, so two charts on one page do not share a gradient definition.
    $gradientId = 'chart-fill-' . substr(md5($tone . $count . $peak), 0, 8);

    /*
     | The grid is 100 by 100 in user units and the viewBox scales it. Working in a
     | fixed square keeps the maths readable: x is the point's position as a
     | percentage, y is its value inverted, because SVG counts downwards.
     */
    $step = $count > 1 ? 100 / ($count - 1) : 0;
    $coords = [];

    foreach ($values as $i => $value) {
        $coords[] = [
            'x' => round($i * $step, 3),
            // 6 units of headroom so the peak is not clipped by the border.
            'y' => round(100 - ($peak > 0 ? ($value / $peak) * 94 : 0), 3),
            'note' => $points[$i]['note'] ?? '',
        ];
    }

    // Smoothed path. Control points sit level with their own endpoint, so the
    // curve stays inside the range of each pair it joins.
    $path = '';

    if ($count > 0) {
        $path = 'M ' . $coords[0]['x'] . ',' . $coords[0]['y'];

        for ($i = 1; $i < $count; $i++) {
            $prev = $coords[$i - 1];
            $curr = $coords[$i];
            $midX = round($prev['x'] + (($curr['x'] - $prev['x']) / 2), 3);

            $path .= ' C ' . $midX . ',' . $prev['y']
                . ' ' . $midX . ',' . $curr['y']
                . ' ' . $curr['x'] . ',' . $curr['y'];
        }
    }

    // The same curve closed along the baseline, so the area beneath can be tinted.
    $areaPath = $path !== '' ? $path . ' L 100,100 L 0,100 Z' : '';

    $last = $count > 0 ? $coords[$count - 1] : null;
@endphp

<div {{ $attributes->merge(['class' => 'w-full']) }}>
    @if (! $hasData)
        <div class="flex flex-col items-center justify-center text-center px-4"
             style="height: {{ $height }}px">
            <x-admin.icon name="activity" class="w-8 h-8 text-gray-300" />
            <p class="text-sm text-gray-500 mt-2 max-w-xs">{{ $empty }}</p>
        </div>
    @else
        <div class="flex gap-3">
            {{-- A two value axis. Enough to judge height, without a column of
                 numbers competing with the line for attention. --}}
            <div class="flex flex-col justify-between shrink-0 text-right"
                 style="height: {{ $height }}px" aria-hidden="true">
                <span class="text-xs text-gray-400 tabular-nums leading-none">{{ $peakLabel ?? number_format($peak) }}</span>
                <span class="text-xs text-gray-300 tabular-nums leading-none">0</span>
            </div>

            <div class="relative flex-1 min-w-0" style="height: {{ $height }}px">
                <svg viewBox="0 0 100 100" preserveAspectRatio="none"
                     class="w-full h-full overflow-visible" role="img"
                     aria-label="{{ $count }} day trend, highest value {{ $peakLabel ?? number_format($peak, 2) }}">

                    <defs>
                        <linearGradient id="{{ $gradientId }}" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="currentColor" stop-opacity="0.28" />
                            <stop offset="100%" stop-color="currentColor" stop-opacity="0.02" />
                        </linearGradient>
                    </defs>

                    {{-- Three faint rules, so height can be judged without a full axis. --}}
                    @foreach ([25, 50, 75] as $rule)
                        <line x1="0" y1="{{ $rule }}" x2="100" y2="{{ $rule }}"
                              class="stroke-gray-100" stroke-width="1"
                              vector-effect="non-scaling-stroke" />
                    @endforeach

                    <path d="{{ $areaPath }}" fill="url(#{{ $gradientId }})" class="{{ $colour['text'] }}" />

                    {{-- vector-effect keeps the stroke an even thickness despite the
                         non-uniform scaling. Without it the line thins out
                         horizontally on a wide card. --}}
                    <path d="{{ $path }}" fill="none"
                          class="{{ $colour['stroke'] }}" stroke-width="2"
                          stroke-linecap="round" stroke-linejoin="round"
                          vector-effect="non-scaling-stroke" />
                </svg>

                {{-- The latest point is always marked, so the eye lands on where the
                     line ends rather than hunting for it. --}}
                @if ($last)
                    <span class="absolute w-2 h-2 rounded-full {{ $colour['dot'] }} ring-2 ring-white"
                          style="left: 100%; top: {{ $last['y'] }}%; transform: translate(-50%, -50%)"
                          aria-hidden="true"></span>
                @endif

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
        </div>

        {{-- First, middle and last only. Thirty date labels across a card this wide
             would be unreadable. --}}
        <div class="flex justify-between mt-2 pl-10">
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
