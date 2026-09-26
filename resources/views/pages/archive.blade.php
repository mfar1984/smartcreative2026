@extends('layouts.master')

@section('title', 'Results Archive')

@section('content')
    @php
        /*
         | A colour per category, matching the Hall of Fame so the same competition is
         | the same colour on both pages. Slate for anything nobody has styled, which
         | reads better than no badge at all.
         */
        $tone = function (?string $category): array {
            return match (strtolower((string) $category)) {
                'e-sport', 'esport' => ['bg-blue-100', 'text-blue-800'],
                'sport', 'sports' => ['bg-emerald-100', 'text-emerald-800'],
                'culture', 'arts' => ['bg-fuchsia-100', 'text-fuchsia-800'],
                default => ['bg-slate-100', 'text-slate-700'],
            };
        };

        /*
         | One line for however long the event ran. A single day says the day once; two
         | days in the same month collapse to "26-27 Sep 2026" rather than repeating
         | the month back at the reader.
         */
        $dateRange = function ($from, $to): string {
            if (! $from) {
                return '';
            }

            if (! $to || $from->isSameDay($to)) {
                return $from->format('d M Y');
            }

            if ($from->format('Y-m') === $to->format('Y-m')) {
                return $from->format('d') . '-' . $to->format('d M Y');
            }

            if ($from->format('Y') === $to->format('Y')) {
                return $from->format('d M') . ' - ' . $to->format('d M Y');
            }

            return $from->format('d M Y') . ' - ' . $to->format('d M Y');
        };
    @endphp

    {{--
        Cooler and quieter than the Hall of Fame on purpose. That page celebrates; this
        one is a record. The hero-section class stays because the main header measures
        its height to decide when to switch to its scrolled state.
    --}}
    <section class="hero-section relative bg-gradient-to-br from-slate-900 via-gray-900 to-indigo-950 text-white pt-28 pb-14 md:pt-32 md:pb-16 overflow-hidden">
        <div class="absolute inset-0 bg-cover bg-center opacity-10" style="background-image: url('{{ asset('images/home.png') }}');"></div>
        <div class="absolute -top-28 -right-20 w-[24rem] h-[24rem] rounded-full bg-indigo-500/20 blur-3xl"></div>

        <div class="container mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <span class="inline-flex items-center gap-2 rounded-full bg-indigo-500/15 border border-indigo-400/40 px-3.5 py-1.5 text-xs font-bold uppercase tracking-widest text-indigo-300">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10a2 2 0 002 2h12a2 2 0 002-2V7M4 7h16M4 7l1-3h14l1 3M9 12h6"/>
                </svg>
                Archive
            </span>

            <h1 class="text-3xl md:text-5xl font-bold leading-tight uppercase mt-4">Past Results</h1>

            <div class="w-24 h-1 bg-indigo-500 mt-5 rounded-full"></div>

            <p class="text-sm md:text-base text-gray-400 mt-5 max-w-2xl">
                Every tournament we have finished running. The figures are counted from the
                match records themselves, so what you read here is what was played.
            </p>

            @if ($totals['tournaments'] > 0)
                <div class="flex flex-wrap gap-x-10 gap-y-4 mt-8">
                    <div>
                        <span class="block text-2xl font-bold tabular-nums">{{ $totals['tournaments'] }}</span>
                        <span class="text-xs font-semibold uppercase tracking-widest text-gray-400">
                            {{ Str::plural('Tournament', $totals['tournaments']) }}
                        </span>
                    </div>
                    <div>
                        <span class="block text-2xl font-bold tabular-nums">{{ $totals['teams'] }}</span>
                        <span class="text-xs font-semibold uppercase tracking-widest text-gray-400">
                            {{ Str::plural('Competitor', $totals['teams']) }}
                        </span>
                    </div>
                    <div>
                        <span class="block text-2xl font-bold tabular-nums">{{ $totals['matches'] }}</span>
                        <span class="text-xs font-semibold uppercase tracking-widest text-gray-400">
                            {{ Str::plural('Match', $totals['matches']) }} played
                        </span>
                    </div>
                </div>
            @endif
        </div>
    </section>

    <section class="py-10 md:py-16 bg-gray-50">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">

            @forelse ($years as $year => $entries)
                <div class="flex items-center gap-4 mb-6 {{ $loop->first ? '' : 'mt-12' }}">
                    <h2 class="text-xl md:text-2xl font-bold text-gray-900">{{ $year }}</h2>
                    <span class="grow h-px bg-gray-200"></span>
                    <span class="text-xs font-semibold text-gray-400 shrink-0">
                        {{ count($entries) }} {{ Str::plural('tournament', count($entries)) }}
                    </span>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                    @foreach ($entries as $entry)
                        @php
                            $tournament = $entry['tournament'];
                            $event = $entry['event'];
                            $badge = $tone($event?->category);
                        @endphp

                        <article class="flex flex-col bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">

                            <div class="px-5 md:px-6 pt-5 pb-4">
                                <div class="flex flex-wrap items-center gap-2 mb-3">
                                    @if ($event?->category)
                                        <span class="inline-block rounded-md {{ $badge[0] }} {{ $badge[1] }} px-2.5 py-1 text-xs font-bold uppercase tracking-wide">
                                            {{ $event->category }}
                                        </span>
                                    @endif

                                    @if ($entry['is_announced'])
                                        <span class="inline-flex items-center gap-1 rounded-md bg-amber-100 px-2.5 py-1 text-xs font-bold uppercase tracking-wide text-amber-800">
                                            <span aria-hidden="true">&#128081;</span> Announced
                                        </span>
                                    @else
                                        <span class="inline-block rounded-md bg-gray-100 px-2.5 py-1 text-xs font-bold uppercase tracking-wide text-gray-500">
                                            Not announced
                                        </span>
                                    @endif
                                </div>

                                <h3 class="text-lg font-bold text-gray-900 leading-snug">
                                    {{ $event?->title ?? $tournament->name }}
                                </h3>

                                <p class="text-sm text-gray-500 mt-1">{{ $tournament->name }}</p>

                                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2.5 text-xs text-gray-500">
                                    @if ($event)
                                        <span class="inline-flex items-center gap-1.5">
                                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3M3 11h18M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                            </svg>
                                            {{ $dateRange($event->starts_at, $event->ends_at) }}
                                        </span>
                                    @endif

                                    @if ($event?->location)
                                        <span class="inline-flex items-center gap-1.5 min-w-0">
                                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                                            </svg>
                                            <span class="truncate">{{ $event->location }}</span>
                                        </span>
                                    @endif
                                </div>
                            </div>

                            {{-- The winner, read from the frozen champions and nowhere else.
                                 Where no podium was announced the card says so rather than
                                 promoting whoever happened to top the table. --}}
                            @if ($entry['champion'])
                                <div class="mx-5 md:mx-6 rounded-xl border border-amber-300 bg-gradient-to-r from-amber-50 to-white px-4 py-3.5">
                                    <p class="text-xs font-bold uppercase tracking-widest text-amber-700">Champion</p>

                                    <div class="flex items-center justify-between gap-3 mt-1.5">
                                        <div class="flex items-center gap-2.5 min-w-0">
                                            <x-team-crest :registration="$entry['champion']->entrant?->registration"
                                                          :name="$entry['champion']->display_name" />

                                            <p class="text-base font-bold text-gray-900 leading-snug truncate">
                                                {{ $entry['champion']->display_name }}
                                            </p>
                                        </div>

                                        @if ((float) $entry['champion']->total_points > 0)
                                            <p class="text-sm font-semibold text-gray-500 tabular-nums shrink-0">
                                                {{ $entry['champion']->total_points + 0 }} pts
                                            </p>
                                        @endif
                                    </div>
                                </div>
                            @else
                                <div class="mx-5 md:mx-6 rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-3.5">
                                    <p class="text-xs font-bold uppercase tracking-widest text-gray-400">Champion</p>
                                    <p class="text-sm text-gray-500 mt-1">
                                        No podium was announced for this tournament.
                                    </p>
                                </div>
                            @endif

                            {{-- Counted from the rows the tournament already has. The player
                                 tile is left out for a competition that never recorded
                                 individual figures, because a zero there would read as
                                 "nobody played" rather than "not tracked". --}}
                            <div @class([
                                'grid divide-x divide-gray-100 border-t border-gray-100 mt-5',
                                'grid-cols-3' => $entry['players'] > 0,
                                'grid-cols-2' => $entry['players'] === 0,
                            ])>
                                <div class="px-4 py-3.5 text-center">
                                    <span class="block text-lg font-bold text-gray-900 tabular-nums">{{ $entry['teams'] }}</span>
                                    <span class="block text-xs font-semibold uppercase tracking-wide text-gray-400">
                                        {{ Str::plural('Competitor', $entry['teams']) }}
                                    </span>
                                </div>

                                <div class="px-4 py-3.5 text-center">
                                    <span class="block text-lg font-bold text-gray-900 tabular-nums">{{ $entry['played'] }}</span>
                                    <span class="block text-xs font-semibold uppercase tracking-wide text-gray-400">
                                        {{ Str::plural('Match', $entry['played']) }}
                                    </span>
                                </div>

                                @if ($entry['players'] > 0)
                                    <div class="px-4 py-3.5 text-center">
                                        <span class="block text-lg font-bold text-gray-900 tabular-nums">{{ $entry['players'] }}</span>
                                        <span class="block text-xs font-semibold uppercase tracking-wide text-gray-400">
                                            {{ Str::plural('Player', $entry['players']) }}
                                        </span>
                                    </div>
                                @endif
                            </div>

                            <div class="mt-auto border-t border-gray-100 px-5 md:px-6 py-3.5">
                                @if ($entry['standings_url'])
                                    <a href="{{ $entry['standings_url'] }}"
                                       class="inline-flex items-center gap-1.5 text-sm font-semibold text-blue-600 hover:text-blue-700 transition">
                                        Full standings
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                        </svg>
                                    </a>
                                @else
                                    <span class="text-sm text-gray-400">Standings are not published for this tournament.</span>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @empty
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm px-6 py-20 text-center">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-indigo-50">
                        <svg class="w-8 h-8 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10a2 2 0 002 2h12a2 2 0 002-2V7M4 7h16M4 7l1-3h14l1 3M9 12h6"/>
                        </svg>
                    </div>

                    <p class="text-lg font-bold text-gray-900 mt-5">Nothing archived yet</p>
                    <p class="text-sm text-gray-500 mt-2 max-w-md mx-auto">
                        A tournament is listed here once it has finished. Anything being played
                        right now is under Results instead.
                    </p>

                    <a href="{{ route('registration') }}"
                       class="inline-flex items-center gap-2 mt-6 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition">
                        See what is open for registration
                    </a>
                </div>
            @endforelse

        </div>
    </section>
@endsection
