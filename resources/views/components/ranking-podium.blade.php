@props([
    'rows' => [],
    'heading' => null,
    'note' => null,
])

{{--
    A podium, laid out the way a broadcast lays one out.

    Second on the left, first raised in the middle, third on the right. On a phone there
    is no left and right, so the winner comes first and the rest follow in order: a
    reader scrolling down wants the result before the arrangement.

    Dark against the light page on purpose. This is the one part of a standings table
    that is looked at rather than read, and it is the block somebody photographs.

    Each row is already normalised by the caller into a name, an optional registration
    for the crest, a list of figures and a total, so the same component serves the team
    table and the player table without knowing which it is drawing.
--}}

@php
    $rows = collect($rows)->filter()->sortBy('rank')->values();
@endphp

@if ($rows->isNotEmpty())
    <div class="relative bg-gradient-to-b from-slate-900 via-gray-900 to-slate-900 px-4 sm:px-6 pt-6 pb-2 overflow-hidden">
        {{-- A pool of light behind the winner, so the middle block reads as raised
             before anybody looks at the numbers. --}}
        <div class="absolute top-0 left-1/2 -translate-x-1/2 w-72 h-40 rounded-full bg-amber-500/20 blur-3xl" aria-hidden="true"></div>

        @if ($heading)
            <p class="relative text-xs font-bold uppercase tracking-widest text-amber-400 mb-5">{{ $heading }}</p>
        @endif

        <div class="relative grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4 items-end">
            @foreach ($rows as $row)
                @php
                    $place = (int) ($row['rank'] ?? 0);

                    /*
                     | Gold, silver, bronze. The winner's block sits lower on the grid and
                     | carries a brighter plate, which is what makes it read as the middle
                     | of a podium rather than the middle of a list.
                     */
                    $tone = match ($place) {
                        1 => [
                            'order-1 sm:order-2',
                            'from-amber-500/20 to-amber-900/10 ring-amber-500/40',
                            'bg-amber-400 text-amber-950',
                            'text-amber-300',
                            'sm:pb-8',
                        ],
                        2 => [
                            'order-2 sm:order-1',
                            'from-slate-400/15 to-slate-800/10 ring-slate-400/30',
                            'bg-slate-300 text-slate-900',
                            'text-slate-300',
                            '',
                        ],
                        default => [
                            'order-3 sm:order-3',
                            'from-orange-500/15 to-orange-900/10 ring-orange-500/30',
                            'bg-orange-400 text-orange-950',
                            'text-orange-300',
                            '',
                        ],
                    };
                @endphp

                <div class="{{ $tone[0] }} {{ $tone[4] }}">
                    <div class="flex flex-col items-center text-center">
                        @if (($row['registration'] ?? null) || ($row['show_crest'] ?? false))
                            <x-team-crest :registration="$row['registration'] ?? null"
                                          :name="$row['name'] ?? ''"
                                          :size="$place === 1 ? 'lg' : 'md'"
                                          tone="dark"
                                          class="mb-2.5" />
                        @endif

                        <p @class([
                            'font-bold uppercase leading-tight text-white break-words px-1',
                            'text-base sm:text-lg' => $place === 1,
                            'text-sm sm:text-base' => $place !== 1,
                        ])>{{ $row['name'] ?? '—' }}</p>
                    </div>

                    {{-- The plinth. --}}
                    <div class="relative mt-3 rounded-t-xl bg-gradient-to-b {{ $tone[1] }} ring-1 px-3 pt-6 pb-4">
                        <span class="absolute -top-3 left-1/2 -translate-x-1/2 inline-flex items-center justify-center min-w-8 h-6 rounded px-2 text-xs font-bold tabular-nums {{ $tone[2] }} shadow">
                            #{{ $place }}
                        </span>

                        <dl class="flex items-end justify-center gap-x-4 gap-y-2 flex-wrap">
                            @foreach ($row['figures'] ?? [] as $label => $value)
                                <div class="text-center">
                                    <dt class="text-[10px] font-bold uppercase tracking-wide {{ $tone[3] }} leading-tight">{{ $label }}</dt>
                                    <dd @class([
                                        'font-bold tabular-nums text-white leading-tight mt-0.5',
                                        'text-lg sm:text-xl' => $place === 1,
                                        'text-base sm:text-lg' => $place !== 1,
                                    ])>{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($note)
            <p class="relative text-xs text-gray-500 mt-4">{{ $note }}</p>
        @endif
    </div>
@endif
