@extends('layouts.master')

@section('title', $registration->displayName() . ' — ' . $event->title)

@section('content')
    @php
        $name = $entrant->displayName();

        // Two letters off the name for the crest. Cheap, and it gives every team a
        // mark of its own without anybody having to upload one.
        $initials = collect(preg_split('/\s+/', trim($name)))
            ->filter()
            ->take(2)
            ->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)))
            ->implode('');

        $played = $lines->count();

        // Summed from the match lines on screen rather than read off the standing, so
        // the header and the table below it cannot disagree.
        $totals = [];
        $counts = [];

        foreach ($lines as $line) {
            foreach ($line->component_points ?? [] as $key => $value) {
                $totals[$key] = ($totals[$key] ?? 0) + (float) $value;
            }

            foreach ($line->component_counts ?? [] as $key => $value) {
                $counts[$key] = ($counts[$key] ?? 0) + (int) $value;
            }
        }

        $points = array_sum($totals);

        $ordinal = function (?int $n): string {
            if ($n === null) {
                return '—';
            }

            $suffix = match (true) {
                $n % 100 >= 11 && $n % 100 <= 13 => 'th',
                $n % 10 === 1 => 'st',
                $n % 10 === 2 => 'nd',
                $n % 10 === 3 => 'rd',
                default => 'th',
            };

            return $n . $suffix;
        };
    @endphp

    <div class="hero-section relative bg-gradient-to-br from-gray-900 via-slate-900 to-black text-white pt-28 pb-12 md:pt-32 md:pb-14 overflow-hidden">
        <div class="absolute inset-0 bg-cover bg-center opacity-15" style="background-image: url('{{ asset('images/home.png') }}');"></div>
        <div class="absolute -top-28 -right-20 w-96 h-96 rounded-full bg-blue-600/20 blur-3xl"></div>

        <div class="container mx-auto px-4 sm:px-6 lg:px-8 relative z-10">

            {{-- Back to the table this was opened from, which is where somebody reading
                 one team almost always wants to go next. --}}
            <a href="{{ route('events.ranking', $event->slug) }}"
               class="inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-widest text-gray-400 hover:text-white transition mb-6">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
                All standings
            </a>

            <div class="flex flex-wrap items-start gap-5">
                <div class="w-16 h-16 md:w-18 md:h-18 rounded-2xl bg-gradient-to-br from-slate-700 to-slate-900 ring-1 ring-white/10 flex items-center justify-center text-xl font-bold text-blue-300 shrink-0"
                     style="width:4.5rem;height:4.5rem">
                    {{ $initials !== '' ? $initials : '—' }}
                </div>

                <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-widest text-blue-300 mb-1.5">
                        {{ $registration->mode === \App\Models\Event::MODE_MANAGER ? 'Team' : 'Entrant' }}
                    </p>

                    <h1 class="text-2xl md:text-3xl font-bold leading-tight">{{ $name }}</h1>

                    <p class="text-sm text-gray-400 mt-2">
                        {{ $tournament->name }}
                        @if ($standing?->stage)
                            &middot; {{ $standing->stage->name }}
                        @endif
                        &middot; {{ $event->title }}
                    </p>

                    <div class="flex flex-wrap gap-x-8 gap-y-4 mt-6">
                        {{-- Withheld until they have played. Before that every team is
                             level on nil and the ranking puts them all first, which is
                             true and useless. --}}
                        <div>
                            <span @class([
                                'block text-xl font-bold tabular-nums',
                                'text-amber-400' => $played > 0 && $standing && (int) $standing->rank <= 3,
                                'text-gray-500' => $played === 0,
                            ])>{{ $played > 0 ? $ordinal($standing?->rank) : '–' }}</span>
                            <span class="text-xs font-semibold uppercase tracking-widest text-gray-400">Standing</span>
                        </div>

                        <div>
                            <span class="block text-xl font-bold tabular-nums">{{ $points + 0 }}</span>
                            <span class="text-xs font-semibold uppercase tracking-widest text-gray-400">Points</span>
                        </div>

                        <div>
                            <span class="block text-xl font-bold tabular-nums">{{ $played }}</span>
                            <span class="text-xs font-semibold uppercase tracking-widest text-gray-400">
                                {{ Str::plural('Match', $played) }}
                            </span>
                        </div>

                        @foreach ($columns as $column)
                            @if ($column['counted'] && ($counts[$column['key']] ?? 0) > 0)
                                <div>
                                    <span class="block text-xl font-bold tabular-nums">{{ $counts[$column['key']] }}</span>
                                    <span class="text-xs font-semibold uppercase tracking-widest text-gray-400">{{ $column['label'] }}</span>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <section class="py-10 md:py-14 bg-gray-50">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">

            {{-- ===== Match by match ===== --}}
            <article class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden mb-6">
                <div class="flex flex-wrap items-baseline justify-between gap-3 px-5 md:px-7 py-4 border-b border-gray-100">
                    <p class="text-xs font-bold uppercase tracking-widest text-gray-500">Match by match</p>
                    <p class="text-sm text-gray-400">Every fixture with a result</p>
                </div>

                @if ($lines->isEmpty())
                    <div class="px-5 md:px-7 py-14 text-center">
                        <p class="text-sm font-semibold text-gray-700">No matches played yet</p>
                        <p class="text-sm text-gray-500 mt-1">
                            Each fixture appears here as soon as its result is entered.
                        </p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-200">
                                    <th scope="col" class="px-5 md:px-7 py-3 text-left text-xs font-bold uppercase tracking-widest text-gray-500">Match</th>
                                    <th scope="col" class="px-3 py-3 text-left text-xs font-bold uppercase tracking-widest text-gray-500 hidden sm:table-cell">Map</th>

                                    @foreach ($columns as $column)
                                        <th scope="col" class="px-2 py-3 text-right text-xs font-bold uppercase tracking-widest text-gray-500 whitespace-nowrap hidden md:table-cell">
                                            {{ $column['label'] }}
                                        </th>
                                    @endforeach

                                    <th scope="col" class="px-5 md:px-7 py-3 text-right text-xs font-bold uppercase tracking-widest text-gray-500 w-20">Pts</th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-gray-100">
                                @foreach ($lines as $line)
                                    @php
                                        $won = $line->match?->winner_entrant_id === $entrant->id;
                                        $linePoints = array_sum(array_map('floatval', $line->component_points ?? []));
                                    @endphp

                                    <tr class="hover:bg-blue-50/40 transition">
                                        <td class="px-5 md:px-7 py-3.5">
                                            <span class="inline-flex items-center justify-center min-w-9 h-7 rounded-lg bg-gray-100 px-2 text-xs font-bold text-gray-600">
                                                {{ $line->match?->label() ?? '—' }}
                                            </span>

                                            @if ($won)
                                                <span class="ml-1.5 rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-semibold text-emerald-800 align-middle">Won</span>
                                            @endif

                                            @if ($line->is_disqualified)
                                                <span class="ml-1.5 rounded bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-800 align-middle">DQ</span>
                                            @endif

                                            {{-- The figures have nowhere to go on a phone, so they read
                                                 as one line under the fixture instead. --}}
                                            <span class="block md:hidden text-xs text-gray-500 mt-1.5 tabular-nums">
                                                @if ($line->match?->map)
                                                    {{ $line->match->map }} &middot;
                                                @endif
                                                @foreach ($columns as $column)
                                                    {{ $column['label'] }}
                                                    {{ $column['counted']
                                                        ? $line->componentCount($column['key'])
                                                        : $line->componentPoints($column['key']) + 0 }}@unless ($loop->last) &middot; @endunless
                                                @endforeach
                                            </span>
                                        </td>

                                        <td class="px-3 py-3.5 text-sm text-gray-500 hidden sm:table-cell">
                                            {{ $line->match?->map ?? '—' }}
                                        </td>

                                        @foreach ($columns as $column)
                                            <td class="px-2 py-3.5 text-right tabular-nums text-sm text-gray-700 hidden md:table-cell">
                                                {{ $column['counted']
                                                    ? $line->componentCount($column['key'])
                                                    : $line->componentPoints($column['key']) + 0 }}
                                            </td>
                                        @endforeach

                                        <td class="px-5 md:px-7 py-3.5 text-right">
                                            <span class="text-base font-bold tabular-nums text-gray-900">{{ $linePoints + 0 }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>

                            <tfoot>
                                <tr class="bg-gray-50 border-t-2 border-gray-200">
                                    <td class="px-5 md:px-7 py-3.5 text-xs font-bold uppercase tracking-widest text-gray-500">Total</td>
                                    <td class="hidden sm:table-cell"></td>

                                    @foreach ($columns as $column)
                                        <td class="px-2 py-3.5 text-right tabular-nums text-sm font-bold text-gray-700 hidden md:table-cell">
                                            {{ $column['counted']
                                                ? ($counts[$column['key']] ?? 0)
                                                : ($totals[$column['key']] ?? 0) + 0 }}
                                        </td>
                                    @endforeach

                                    <td class="px-5 md:px-7 py-3.5 text-right">
                                        <span class="text-lg font-bold tabular-nums text-gray-900">{{ $points + 0 }}</span>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </article>

            {{-- ===== Roster =====
                 By the name they play under. Nothing read off an identity card reaches
                 this page: no full legal name, no card number, no telephone, no email. --}}
            <article class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="flex flex-wrap items-baseline justify-between gap-3 px-5 md:px-7 py-4 border-b border-gray-100">
                    <p class="text-xs font-bold uppercase tracking-widest text-gray-500">Roster</p>
                    <p class="text-sm text-gray-400">
                        {{ $registration->participants->count() }}
                        {{ Str::plural('person', $registration->participants->count()) }}
                    </p>
                </div>

                <div class="px-5 md:px-7 py-5 flex flex-wrap gap-2">
                    @forelse ($registration->participants as $person)
                        @php
                            // One definition of what a competitor is called in public, shared
                            // with the player leaderboard so the two cannot drift apart.
                            $label = \App\Support\Tournament\PlayerStandingsCalculator::publicLabel($person);

                            $second = filled($person->ign_name) && filled($person->ign_player_id)
                                ? $person->ign_player_id
                                : null;
                        @endphp

                        <span class="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-gray-50 px-3.5 py-2 text-sm">
                            @if ($person->isManager())
                                <span class="rounded bg-indigo-100 px-1.5 py-0.5 text-xs font-bold text-indigo-800">
                                    {{ $person->also_plays ? 'Manager & Player' : 'Manager' }}
                                </span>
                            @endif

                            <span class="font-semibold text-gray-800">{{ $label }}</span>

                            @if ($second)
                                <span class="text-xs text-gray-400 tabular-nums">{{ $second }}</span>
                            @endif
                        </span>
                    @empty
                        <p class="text-sm text-gray-500">Nobody is listed on this entry.</p>
                    @endforelse
                </div>
            </article>

        </div>
    </section>
@endsection
