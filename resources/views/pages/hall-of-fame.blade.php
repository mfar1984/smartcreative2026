@extends('layouts.master')

@section('title', 'Hall of Fame')

@section('content')
    @php
        // Counted from what is on the page rather than queried again, because the
        // controller has already grouped it and a second count could disagree.
        $tournamentCount = collect($years)->sum(fn ($entries) => count($entries));
        $podiumCount = collect($years)->sum(
            fn ($entries) => collect($entries)->sum(fn (array $entry) => $entry['podium']->count()),
        );

        /*
         | A colour per game, so a visitor scanning a long page can tell one
         | competition from another before reading a word. Falls back to slate for a
         | category nobody has styled, which is better than no badge at all.
         */
        $gameTone = function (?string $category): array {
            return match (strtolower((string) $category)) {
                'e-sport', 'esport' => ['bg-blue-100', 'text-blue-800'],
                'sport', 'sports' => ['bg-emerald-100', 'text-emerald-800'],
                'culture', 'arts' => ['bg-fuchsia-100', 'text-fuchsia-800'],
                default => ['bg-slate-100', 'text-slate-700'],
            };
        };
    @endphp

    {{--
        Trophy hero. Warmer than the rest of the site on purpose: this is the one
        public page that exists to celebrate rather than to inform. The hero-section
        class is kept because the main header measures its height to decide when to
        switch to its scrolled state.
    --}}
    <section class="hero-section relative bg-gradient-to-br from-amber-950 via-gray-900 to-black text-white pt-28 pb-14 md:pt-32 md:pb-16 overflow-hidden">
        <div class="absolute inset-0 bg-cover bg-center opacity-15" style="background-image: url('{{ asset('images/home.png') }}');"></div>
        <div class="absolute -top-32 -left-20 w-[26rem] h-[26rem] rounded-full bg-amber-500/20 blur-3xl"></div>

        <div class="container mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <span class="inline-flex items-center gap-2 rounded-full bg-amber-500/15 border border-amber-500/40 px-3.5 py-1.5 text-xs font-bold uppercase tracking-widest text-amber-300">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 21h8m-4-4v4m-6-17h12v4a6 6 0 11-12 0V4zm12 1h2a2 2 0 010 4h-2M6 5H4a2 2 0 000 4h2"/>
                </svg>
                Hall of Fame
            </span>

            <h1 class="text-3xl md:text-5xl font-bold leading-tight uppercase mt-4">Champions</h1>

            <div class="w-24 h-1 bg-amber-500 mt-5 rounded-full"></div>

            <p class="text-sm md:text-base text-gray-400 mt-5 max-w-2xl">
                Every podium we have announced, newest first. These figures are frozen at the moment
                the result was published, so correcting a match afterwards never rewrites what was
                announced.
            </p>

            @if ($tournamentCount > 0)
                <div class="flex flex-wrap gap-x-10 gap-y-4 mt-8">
                    <div>
                        <span class="block text-2xl font-bold tabular-nums">{{ $tournamentCount }}</span>
                        <span class="text-xs font-semibold uppercase tracking-widest text-gray-400">
                            {{ Str::plural('Tournament', $tournamentCount) }}
                        </span>
                    </div>
                    <div>
                        <span class="block text-2xl font-bold tabular-nums">{{ $podiumCount }}</span>
                        <span class="text-xs font-semibold uppercase tracking-widest text-gray-400">Podium places</span>
                    </div>
                    <div>
                        <span class="block text-2xl font-bold tabular-nums">{{ $years->keys()->filter(fn ($y) => $y !== 'Undated')->min() ?? '—' }}</span>
                        <span class="text-xs font-semibold uppercase tracking-widest text-gray-400">Since</span>
                    </div>
                </div>
            @endif
        </div>
    </section>

    <section class="py-10 md:py-16 bg-gray-50">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">

            @forelse ($years as $year => $entries)
                <div class="flex items-center gap-4 mb-6 {{ $loop->first ? '' : 'mt-12' }}">
                    <h2 class="text-xl md:text-2xl font-bold text-gray-900">{{ $year }}</h2>
                    <span class="grow h-px bg-gray-200"></span>
                    <span class="text-xs font-semibold text-gray-400 shrink-0">
                        {{ count($entries) }} {{ Str::plural('tournament', count($entries)) }}
                    </span>
                </div>

                @foreach ($entries as $entry)
                    @php
                        $tournament = $entry['tournament'];
                        $podium = $entry['podium'];
                        $event = $tournament?->event;
                        $tone = $gameTone($event?->category);
                        $own = $awards->get($tournament?->id) ?? collect();
                    @endphp

                    <article class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden mb-6">

                        <div class="flex flex-wrap items-start justify-between gap-4 px-5 md:px-7 py-5 border-b border-gray-100">
                            <div class="min-w-0">
                                @if ($event?->category)
                                    <span class="inline-block rounded-md {{ $tone[0] }} {{ $tone[1] }} px-2.5 py-1 text-xs font-bold uppercase tracking-wide mb-2">
                                        {{ $event->category }}
                                    </span>
                                @endif

                                <h3 class="text-lg font-bold text-gray-900">{{ $tournament?->name ?? 'Tournament' }}</h3>

                                @if ($event)
                                    <p class="text-sm text-gray-500 mt-1">
                                        {{ $event->title }}
                                        @if ($event->starts_at)
                                            &middot; {{ $event->starts_at->format('d M Y') }}
                                        @endif
                                    </p>
                                @endif
                            </div>

                            <div class="text-right shrink-0">
                                <span class="block text-xs text-gray-400">Published</span>
                                <span class="block text-sm font-semibold text-gray-600">
                                    {{ $tournament?->published_at?->format('d M Y') ?? '—' }}
                                </span>
                            </div>
                        </div>

                        {{-- The podium. Three columns where there are three, and the winner
                             given the wider one, because a podium is not a list. --}}
                        <div class="grid grid-cols-1 sm:grid-cols-3">
                            @foreach ($podium as $champion)
                                @php
                                    $medal = match ((int) $champion->rank) {
                                        1 => ['from-amber-400 to-amber-600', 'Champion', 'bg-gradient-to-b from-amber-50 to-white'],
                                        2 => ['from-slate-300 to-slate-500', 'Runner Up', ''],
                                        default => ['from-orange-300 to-orange-600', 'Third', ''],
                                    };
                                @endphp

                                <div class="relative px-5 py-7 text-center border-b sm:border-b-0 sm:border-r border-gray-100 last:border-r-0 last:border-b-0 {{ $medal[2] }}">
                                    @if ((int) $champion->rank === 1)
                                        <span class="absolute top-3 left-1/2 -translate-x-1/2 text-base" aria-hidden="true">&#128081;</span>
                                    @endif

                                    <div class="w-13 h-13 mx-auto mb-3 rounded-full bg-gradient-to-br {{ $medal[0] }} flex items-center justify-center text-white text-lg font-bold tabular-nums shadow-md"
                                         style="width:3.25rem;height:3.25rem">
                                        {{ $champion->rank }}
                                    </div>

                                    <p class="text-xs font-bold uppercase tracking-widest text-gray-400">{{ $medal[1] }}</p>

                                    <p @class([
                                        'font-bold text-gray-900 mt-1.5',
                                        'text-xl' => (int) $champion->rank === 1,
                                        'text-base' => (int) $champion->rank !== 1,
                                    ])>{{ $champion->display_name }}</p>

                                    @if ((float) $champion->total_points > 0)
                                        <p class="text-sm text-gray-500 mt-1 tabular-nums">
                                            {{ $champion->total_points + 0 }} pts
                                        </p>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        {{-- Individual awards, shown by the name they play under. --}}
                        @if ($own->isNotEmpty())
                            <div class="border-t border-gray-200 px-5 md:px-7 py-5">
                                <p class="text-xs font-bold uppercase tracking-widest text-gray-500 mb-3">Individual awards</p>

                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                    @foreach ($own as $award)
                                        <div @class([
                                            'rounded-xl border px-4 py-3.5',
                                            'border-amber-300 bg-gradient-to-br from-amber-50 to-white' => $loop->first,
                                            'border-gray-200 bg-white' => ! $loop->first,
                                        ])>
                                            <p class="text-xs font-bold uppercase tracking-wide text-gray-400">
                                                @if ($loop->first)
                                                    <span aria-hidden="true">&#11088;</span>
                                                @endif
                                                {{ $award->award_label }}
                                            </p>

                                            {{-- display_name is already the public label: the in-game
                                                 name, falling back to the game account id. Nothing read
                                                 off an identity card reaches it. --}}
                                            <p class="text-base font-bold text-gray-900 mt-1.5">
                                                {{ $award->display_name }}
                                            </p>

                                            <p class="text-sm text-gray-500 mt-0.5">
                                                {{ $award->entrant_name }}
                                                @if ($award->ign && $award->ign !== $award->display_name)
                                                    &middot; <span class="tabular-nums">{{ $award->ign }}</span>
                                                @endif
                                                @if ((float) $award->total_points > 0)
                                                    &middot; <span class="tabular-nums">{{ $award->total_points + 0 }} pts</span>
                                                @endif
                                            </p>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if ($event)
                            <div class="border-t border-gray-100 px-5 md:px-7 py-3.5">
                                <a href="{{ route('events.ranking', $event->slug) }}"
                                   class="inline-flex items-center gap-1.5 text-sm font-semibold text-blue-600 hover:text-blue-700 transition">
                                    Full standings
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                    </svg>
                                </a>
                            </div>
                        @endif
                    </article>
                @endforeach
            @empty
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm px-6 py-20 text-center">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-amber-50">
                        <svg class="w-8 h-8 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 21h8m-4-4v4m-6-17h12v4a6 6 0 11-12 0V4zm12 1h2a2 2 0 010 4h-2M6 5H4a2 2 0 000 4h2"/>
                        </svg>
                    </div>

                    <p class="text-lg font-bold text-gray-900 mt-5">No champions yet</p>
                    <p class="text-sm text-gray-500 mt-2 max-w-md mx-auto">
                        A podium appears here once a tournament has finished and its result has been
                        announced. Nothing is listed before then.
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
