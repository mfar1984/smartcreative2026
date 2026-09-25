@extends('layouts.master')

@section('title', $event->title . ' — Live Standings')

@section('content')
    @php
        // Is anything still being played across the whole page, so the hero can say so
        // rather than leaving a visitor to work it out from a progress figure.
        $anyLive = collect($boards)->contains(fn (array $board) => ! $board['is_final']
            && $board['matches_done'] < $board['matches_total']);
    @endphp

    {{--
        Scoreboard hero.

        Deliberately not the shared page-header: this page is read on a phone at a
        venue by somebody looking for one number, so the event name is smaller than
        usual and the live state is the loudest thing on it. The hero-section class is
        kept because the main header measures its height to decide when to switch to
        its scrolled state.
    --}}
    <section class="hero-section relative bg-gradient-to-br from-gray-900 via-slate-900 to-black text-white pt-28 pb-12 md:pt-32 md:pb-14 overflow-hidden">
        <div class="absolute inset-0 bg-cover bg-center opacity-15" style="background-image: url('{{ asset('images/home.png') }}');"></div>

        {{-- A wash of colour off one corner, so the panel below has something to sit
             against rather than flat black. --}}
        <div class="absolute -top-24 -right-24 w-96 h-96 rounded-full bg-blue-600/20 blur-3xl"></div>

        <div class="container mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="flex flex-wrap items-center gap-3 mb-4">
                @if ($anyLive)
                    <span class="inline-flex items-center gap-2 rounded-full bg-red-500/15 border border-red-500/40 px-3 py-1">
                        <span class="relative flex w-2 h-2">
                            <span class="absolute inline-flex w-full h-full rounded-full bg-red-400 opacity-75 animate-ping"></span>
                            <span class="relative inline-flex w-2 h-2 rounded-full bg-red-500"></span>
                        </span>
                        <span class="text-xs font-bold uppercase tracking-widest text-red-300">Live</span>
                    </span>
                @elseif ($boards !== [])
                    <span class="inline-flex items-center gap-2 rounded-full bg-emerald-500/15 border border-emerald-500/40 px-3 py-1">
                        <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                        <span class="text-xs font-bold uppercase tracking-widest text-emerald-300">Final</span>
                    </span>
                @endif

                <span class="text-xs font-semibold uppercase tracking-widest text-gray-400">
                    {{ $event->starts_at?->format('d M Y') }}
                    @if ($event->location)
                        &middot; {{ $event->location }}
                    @endif
                </span>
            </div>

            <h1 class="text-2xl md:text-4xl font-bold leading-tight uppercase max-w-4xl">
                {{ $event->title }}
            </h1>

            <div class="w-24 h-1 bg-blue-500 mt-5 rounded-full"></div>

            <p class="text-sm md:text-base text-gray-400 mt-5 max-w-2xl">
                Standings are worked out from the results as they are entered. Nothing on this
                page is typed in by hand.
            </p>
        </div>
    </section>

    <section class="py-10 md:py-16 bg-gray-50">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">

            @forelse ($boards as $board)
                @php
                    $tournament = $board['tournament'];
                    $stage = $board['stage'];
                    $cut = $board['advance_count'];
                    $pct = $board['matches_total'] > 0
                        ? (int) round($board['matches_done'] / $board['matches_total'] * 100)
                        : 0;
                    $isLive = ! $board['is_final'] && $board['matches_done'] < $board['matches_total'];

                    /*
                     | Drawn, but nobody has played.
                     |
                     | Every team is on nil, so the ranking has them all in first place
                     | and all above any cut, which is arithmetically true and reads as
                     | nonsense: twenty champions, twenty teams through. The table is
                     | still worth showing, because it says who is competing. The
                     | placings are not, because there are none yet.
                     */
                    $notStarted = $board['matches_done'] === 0;
                @endphp

                <article class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden mb-8 last:mb-0">

                    {{-- ===== Board header: what this table is, and how far through ===== --}}
                    <header class="px-5 md:px-7 py-5 bg-gradient-to-r from-slate-900 to-slate-800 text-white">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 mb-1.5">
                                    @if ($isLive)
                                        <span class="relative flex w-2 h-2 shrink-0">
                                            <span class="absolute inline-flex w-full h-full rounded-full bg-red-400 opacity-75 animate-ping"></span>
                                            <span class="relative inline-flex w-2 h-2 rounded-full bg-red-500"></span>
                                        </span>
                                    @endif
                                    <p class="text-xs font-bold uppercase tracking-widest text-blue-300">
                                        {{ $stage->name }}
                                        <span class="text-gray-500 font-medium normal-case tracking-normal">
                                            &middot; {{ $stage->typeLabel() }}
                                        </span>
                                    </p>
                                </div>

                                <h2 class="text-lg md:text-xl font-bold">{{ $tournament->name }}</h2>
                            </div>

                            <div class="text-right shrink-0">
                                @if ($board['is_final'])
                                    <p class="text-xs font-bold uppercase tracking-widest text-emerald-400">Final result</p>
                                @else
                                    <p class="text-2xl font-bold tabular-nums leading-none">
                                        {{ $board['matches_done'] }}<span class="text-gray-500">/{{ $board['matches_total'] }}</span>
                                    </p>
                                    <p class="text-xs text-gray-400 mt-1">matches played</p>
                                @endif
                            </div>
                        </div>

                        @unless ($board['is_final'])
                            <div class="mt-4">
                                <div class="h-1.5 w-full rounded-full bg-white/10 overflow-hidden">
                                    <div class="h-full rounded-full bg-blue-500 transition-all" style="width: {{ $pct }}%"></div>
                                </div>

                                @if ($board['next_fixture'])
                                    <p class="text-xs text-gray-400 mt-2">
                                        Next: {{ $board['next_fixture']->label() }}
                                        @if ($board['next_fixture']->map)
                                            on {{ $board['next_fixture']->map }}
                                        @endif
                                        @if ($board['next_fixture']->scheduled_at)
                                            &middot; {{ $board['next_fixture']->scheduled_at->format('d M, g:i a') }}
                                        @endif
                                    </p>
                                @endif
                            </div>
                        @endunless
                    </header>

                    @forelse ($board['groups'] as $groupName => $rows)
                        @if ($board['groups']->count() > 1)
                            <p class="px-5 md:px-7 py-2.5 bg-gray-100 border-b border-gray-200 text-xs font-bold uppercase tracking-widest text-gray-600">
                                {{ $groupName }}
                            </p>
                        @endif

                        {{-- ===== Top three, on their own ===== --}}
                        @php $podium = $rows->take(3); @endphp

                        @if ($rows->count() >= 3 && ! $notStarted)
                            <div class="px-5 md:px-7 pt-6 pb-2 grid grid-cols-1 sm:grid-cols-3 gap-3">
                                @foreach ($podium as $standing)
                                    @php
                                        $tone = match ($standing->rank) {
                                            1 => ['ring-amber-300', 'bg-amber-50', 'text-amber-700', 'bg-amber-400'],
                                            2 => ['ring-slate-300', 'bg-slate-50', 'text-slate-600', 'bg-slate-400'],
                                            default => ['ring-orange-200', 'bg-orange-50', 'text-orange-700', 'bg-orange-300'],
                                        };
                                    @endphp

                                    <div class="relative rounded-xl ring-1 {{ $tone[0] }} {{ $tone[1] }} px-4 py-4 overflow-hidden">
                                        <div class="absolute top-0 left-0 w-1 h-full {{ $tone[3] }}"></div>

                                        <div class="flex items-baseline gap-2">
                                            <span class="text-2xl font-bold tabular-nums {{ $tone[2] }}">{{ $standing->rank }}</span>
                                            <span class="text-xs font-semibold uppercase tracking-wide {{ $tone[2] }}">
                                                {{ ['1' => 'First', '2' => 'Second', '3' => 'Third'][(string) $standing->rank] ?? '' }}
                                            </span>
                                        </div>

                                        <p class="text-sm font-bold text-gray-900 mt-1.5 truncate">
                                            {{ $standing->entrant?->displayName() ?? '—' }}
                                        </p>

                                        <p class="text-xs text-gray-500 mt-0.5 tabular-nums">
                                            {{ $standing->total_points + 0 }} pts
                                            &middot; {{ $standing->played }} {{ Str::plural('match', $standing->played) }}
                                        </p>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- ===== The full table ===== --}}
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="border-y border-gray-200 bg-gray-50">
                                        <th scope="col" class="px-3 md:px-5 py-3 text-left text-xs font-bold uppercase tracking-widest text-gray-500 w-14">#</th>
                                        <th scope="col" class="px-3 md:px-5 py-3 text-left text-xs font-bold uppercase tracking-widest text-gray-500">Team</th>
                                        <th scope="col" class="px-2 py-3 text-right text-xs font-bold uppercase tracking-widest text-gray-500 w-16 hidden sm:table-cell">Pld</th>

                                        @foreach ($board['columns'] as $column)
                                            <th scope="col" class="px-2 py-3 text-right text-xs font-bold uppercase tracking-widest text-gray-500 whitespace-nowrap hidden md:table-cell">
                                                {{ $column['label'] }}
                                            </th>
                                        @endforeach

                                        <th scope="col" class="px-3 md:px-5 py-3 text-right text-xs font-bold uppercase tracking-widest text-gray-500 w-20">Pts</th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($rows as $standing)
                                        @php
                                            // The qualifying line, drawn under the last team that would
                                            // go through as things stand. Withheld until something has
                                            // been played, or every team sits above it.
                                            $onCut = ! $notStarted && $cut > 0 && $standing->rank === $cut;
                                            $advancing = ! $notStarted && $cut > 0 && $standing->advances;
                                        @endphp

                                        <tr @class([
                                            'transition hover:bg-blue-50/50',
                                            'bg-emerald-50/40' => $advancing,
                                            'border-b-2 border-b-emerald-400' => $onCut,
                                        ])>
                                            <td class="px-3 md:px-5 py-3.5">
                                                <div class="flex items-center gap-2">
                                                    {{-- A dash rather than a 1 until something has been
                                                         played. Showing every team as first is worse
                                                         than showing no placing at all. --}}
                                                    <span @class([
                                                        'inline-flex items-center justify-center w-7 h-7 rounded-lg text-sm font-bold tabular-nums shrink-0',
                                                        'bg-gray-100 text-gray-400' => $notStarted,
                                                        'bg-amber-400 text-amber-900' => ! $notStarted && $standing->rank === 1,
                                                        'bg-slate-300 text-slate-800' => ! $notStarted && $standing->rank === 2,
                                                        'bg-orange-300 text-orange-900' => ! $notStarted && $standing->rank === 3,
                                                        'bg-gray-100 text-gray-600' => ! $notStarted && $standing->rank > 3,
                                                    ])>{{ $notStarted ? '–' : $standing->rank }}</span>
                                                </div>
                                            </td>

                                            <td class="px-3 md:px-5 py-3.5">
                                                {{-- A link when there is a registration behind the row,
                                                     which is what carries the match by match record. An
                                                     entrant added by hand has none, so it stays text
                                                     rather than becoming a link to nothing. --}}
                                                @if ($standing->entrant?->event_registration_id)
                                                    <a href="{{ route('events.team', [$event->slug, $standing->entrant->event_registration_id]) }}"
                                                       class="font-semibold text-gray-900 text-sm md:text-base hover:text-blue-600 hover:underline transition">
                                                        {{ $standing->entrant->displayName() }}
                                                    </a>
                                                @else
                                                    <span class="font-semibold text-gray-900 text-sm md:text-base">
                                                        {{ $standing->entrant?->displayName() ?? '—' }}
                                                    </span>
                                                @endif

                                                @if ($standing->is_disqualified)
                                                    <span class="ml-1.5 rounded bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-800 align-middle">
                                                        Disqualified
                                                    </span>
                                                @elseif ($advancing)
                                                    <span class="ml-1.5 rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-semibold text-emerald-800 align-middle">
                                                        Through
                                                    </span>
                                                @endif

                                                @if ($standing->is_tied && ! $notStarted)
                                                    <span class="block text-xs text-amber-700 mt-0.5">Level after every tie-break</span>
                                                @endif

                                                {{-- The per-component figures have nowhere to go on a phone,
                                                     so they read as one line under the name instead. --}}
                                                <span class="block md:hidden text-xs text-gray-500 mt-1 tabular-nums">
                                                    @foreach ($board['columns'] as $column)
                                                        {{ $column['label'] }}
                                                        {{ $column['counted']
                                                            ? $standing->componentCount($column['key'])
                                                            : $standing->componentTotal($column['key']) + 0 }}@unless ($loop->last) &middot; @endunless
                                                    @endforeach
                                                </span>
                                            </td>

                                            <td class="px-2 py-3.5 text-right tabular-nums text-sm text-gray-500 hidden sm:table-cell">
                                                {{ $standing->played }}
                                            </td>

                                            @foreach ($board['columns'] as $column)
                                                <td class="px-2 py-3.5 text-right tabular-nums text-sm text-gray-700 hidden md:table-cell">
                                                    {{ $column['counted']
                                                        ? $standing->componentCount($column['key'])
                                                        : $standing->componentTotal($column['key']) + 0 }}
                                                </td>
                                            @endforeach

                                            <td class="px-3 md:px-5 py-3.5 text-right">
                                                <span class="text-base md:text-lg font-bold tabular-nums text-gray-900">
                                                    {{ $standing->total_points + 0 }}
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @if ($cut > 0 && $notStarted)
                            <p class="px-5 md:px-7 py-3 bg-gray-50 border-t border-gray-200 text-xs text-gray-600">
                                <span class="font-semibold">{{ $rows->count() }} teams drawn.</span>
                                The top {{ $cut }} will go through. Placings open once the first match
                                has been scored.
                            </p>
                        @elseif ($cut > 0)
                            <p class="px-5 md:px-7 py-3 bg-emerald-50 border-t border-emerald-100 text-xs text-emerald-800">
                                <span class="font-semibold">Top {{ $cut }} go through.</span>
                                The line moves as results come in, so a place above it is not settled
                                until the stage is finished.
                            </p>
                        @elseif ($notStarted)
                            <p class="px-5 md:px-7 py-3 bg-gray-50 border-t border-gray-200 text-xs text-gray-600">
                                <span class="font-semibold">{{ $rows->count() }} teams drawn.</span>
                                Placings open once the first match has been scored.
                            </p>
                        @endif
                    @empty
                        {{-- A drawn stage with nobody in its table. Rare, and better said
                             than shown as a card with nothing inside it. --}}
                        <div class="px-5 md:px-7 py-12 text-center">
                            <p class="text-sm font-semibold text-gray-700">No table for this stage yet</p>
                            <p class="text-sm text-gray-500 mt-1">
                                It appears once the draw has been made.
                            </p>
                        </div>
                    @endforelse

                    {{-- ===== Individual players =====
                         A separate leaderboard, counted on its own. These points are not
                         part of any team total above, and no personal detail beyond the
                         name and in-game name reaches this page. --}}
                    @if ($board['players']->isNotEmpty())
                        <div class="px-5 md:px-7 py-4 bg-gray-50 border-t border-gray-200">
                            <p class="text-xs font-bold uppercase tracking-widest text-gray-600">Top Players</p>
                            <p class="text-sm text-gray-500 mt-0.5">
                                Individual scores, counted separately from the team table above.
                            </p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="border-y border-gray-200 bg-gray-50">
                                        <th scope="col" class="px-3 md:px-5 py-3 text-left text-xs font-bold uppercase tracking-widest text-gray-500 w-14">#</th>
                                        <th scope="col" class="px-3 md:px-5 py-3 text-left text-xs font-bold uppercase tracking-widest text-gray-500">Player</th>
                                        <th scope="col" class="px-3 py-3 text-left text-xs font-bold uppercase tracking-widest text-gray-500 hidden sm:table-cell">Team</th>

                                        @foreach ($board['player_columns'] as $column)
                                            <th scope="col" class="px-2 py-3 text-right text-xs font-bold uppercase tracking-widest text-gray-500 whitespace-nowrap hidden md:table-cell">
                                                {{ $column['label'] }}
                                            </th>
                                        @endforeach

                                        <th scope="col" class="px-3 md:px-5 py-3 text-right text-xs font-bold uppercase tracking-widest text-gray-500 w-20">Pts</th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($board['players'] as $player)
                                        <tr class="transition hover:bg-blue-50/50">
                                            <td class="px-3 md:px-5 py-3.5">
                                                <span @class([
                                                    'inline-flex items-center justify-center w-7 h-7 rounded-lg text-sm font-bold tabular-nums',
                                                    'bg-amber-400 text-amber-900' => $player->rank === 1,
                                                    'bg-slate-300 text-slate-800' => $player->rank === 2,
                                                    'bg-orange-300 text-orange-900' => $player->rank === 3,
                                                    'bg-gray-100 text-gray-600' => $player->rank > 3,
                                                ])>{{ $player->rank }}</span>
                                            </td>

                                            <td class="px-3 md:px-5 py-3.5">
                                                <span class="font-semibold text-gray-900 text-sm md:text-base">{{ $player->display_name }}</span>
                                                @if ($player->ign)
                                                    <span class="block text-xs text-gray-500">{{ $player->ign }}</span>
                                                @endif
                                                <span class="block sm:hidden text-xs text-gray-500 mt-0.5">
                                                    {{ $player->entrant?->displayName() ?? '—' }}
                                                </span>
                                            </td>

                                            <td class="px-3 py-3.5 text-sm text-gray-700 hidden sm:table-cell">
                                                {{ $player->entrant?->displayName() ?? '—' }}
                                                @if ($player->entrant_is_disqualified)
                                                    <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-800">
                                                        Team disqualified
                                                    </span>
                                                @endif
                                            </td>

                                            @foreach ($board['player_columns'] as $column)
                                                <td class="px-2 py-3.5 text-right tabular-nums text-sm text-gray-700 hidden md:table-cell">
                                                    {{ $column['counted']
                                                        ? $player->componentCount($column['key'])
                                                        : $player->componentTotal($column['key']) + 0 }}
                                                </td>
                                            @endforeach

                                            <td class="px-3 md:px-5 py-3.5 text-right">
                                                <span class="text-base md:text-lg font-bold tabular-nums text-gray-900">
                                                    {{ $player->total_points + 0 }}
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </article>
            @empty
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm px-6 py-20 text-center">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gray-100">
                        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                    </div>

                    <p class="text-lg font-bold text-gray-900 mt-5">Standings are not up yet</p>
                    <p class="text-sm text-gray-500 mt-2 max-w-md mx-auto">
                        They appear here once the draw has been made and the first result is in.
                        Nothing on this page is entered by hand, so it is empty until a match
                        has been played.
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
