@extends('layouts.master')

@section('title', $event->title . ' — Ranking')

@section('content')
    @include('components.page-header', [
        'title' => $event->title,
        'subtitle' => 'Current standings.',
    ])

    <section class="py-14 md:py-20 bg-gray-50">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">

            @forelse ($boards as $board)
                @php $tournament = $board['tournament']; @endphp

                <article class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-8 last:mb-0">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-base font-bold text-gray-900">{{ $tournament->name }}</h2>

                        {{-- How far through, so a half-played table is not mistaken for a
                             final result. --}}
                        <p class="text-sm text-gray-500 mt-0.5">
                            @if ($board['is_final'])
                                Final result.
                            @else
                                Match {{ $board['matches_done'] }} of {{ $board['matches_total'] }}. Still being played.
                            @endif
                        </p>
                    </div>

                    @foreach ($board['groups'] as $groupName => $rows)
                        @if ($board['groups']->count() > 1)
                            <p class="px-6 py-2.5 bg-gray-50 border-y border-gray-100 text-xs font-bold uppercase tracking-wide text-gray-600">
                                {{ $groupName }}
                            </p>
                        @endif

                        <div class="overflow-x-auto">
                            <table class="w-full text-base">
                                <thead class="bg-gray-50 text-left">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 w-12">#</th>
                                        <th scope="col" class="px-6 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Team</th>
                                        @foreach ($board['columns'] as $column)
                                            <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wide text-gray-500">
                                                {{ $column['label'] }}
                                            </th>
                                        @endforeach
                                        <th scope="col" class="px-6 py-3 text-right text-xs font-bold uppercase tracking-wide text-gray-500">Points</th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($rows as $standing)
                                        <tr>
                                            <td class="px-6 py-3 tabular-nums font-bold text-gray-900">{{ $standing->rank }}</td>

                                            <td class="px-6 py-3">
                                                <span class="font-semibold text-gray-900">
                                                    {{ $standing->entrant?->displayName() ?? '—' }}
                                                </span>

                                                @if ($standing->is_disqualified)
                                                    <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-800">
                                                        Disqualified
                                                    </span>
                                                @endif
                                            </td>

                                            @foreach ($board['columns'] as $column)
                                                <td class="px-4 py-3 text-right tabular-nums text-gray-700">
                                                    {{ $column['counted']
                                                        ? $standing->componentCount($column['key'])
                                                        : $standing->componentTotal($column['key']) + 0 }}
                                                </td>
                                            @endforeach

                                            <td class="px-6 py-3 text-right tabular-nums font-bold text-gray-900">
                                                {{ $standing->total_points + 0 }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach

                    {{-- ===== Individual players =====
                         A separate leaderboard, counted on its own. These points are not
                         part of any team total above, and no personal detail beyond the
                         name and in-game name reaches this page. --}}
                    @if ($board['players']->isNotEmpty())
                        <div class="px-6 py-3 bg-gray-50 border-y border-gray-100">
                            <p class="text-xs font-bold uppercase tracking-wide text-gray-600">Top Players</p>
                            <p class="text-sm text-gray-500 mt-0.5">
                                Individual scores, counted separately from the team table above.
                            </p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-base">
                                <thead class="bg-gray-50 text-left">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 w-12">#</th>
                                        <th scope="col" class="px-6 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Player</th>
                                        <th scope="col" class="px-6 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Team</th>
                                        @foreach ($board['player_columns'] as $column)
                                            <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wide text-gray-500">
                                                {{ $column['label'] }}
                                            </th>
                                        @endforeach
                                        <th scope="col" class="px-6 py-3 text-right text-xs font-bold uppercase tracking-wide text-gray-500">Points</th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($board['players'] as $player)
                                        <tr>
                                            <td class="px-6 py-3 tabular-nums font-bold text-gray-900">{{ $player->rank }}</td>

                                            <td class="px-6 py-3">
                                                <span class="font-semibold text-gray-900">{{ $player->display_name }}</span>
                                                @if ($player->ign)
                                                    <span class="block text-sm text-gray-500">{{ $player->ign }}</span>
                                                @endif
                                            </td>

                                            <td class="px-6 py-3 text-gray-700">
                                                {{ $player->entrant?->displayName() ?? '—' }}
                                                @if ($player->entrant_is_disqualified)
                                                    <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-800">
                                                        Team disqualified
                                                    </span>
                                                @endif
                                            </td>

                                            @foreach ($board['player_columns'] as $column)
                                                <td class="px-4 py-3 text-right tabular-nums text-gray-700">
                                                    {{ $column['counted']
                                                        ? $player->componentCount($column['key'])
                                                        : $player->componentTotal($column['key']) + 0 }}
                                                </td>
                                            @endforeach

                                            <td class="px-6 py-3 text-right tabular-nums font-bold text-gray-900">
                                                {{ $player->total_points + 0 }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </article>
            @empty
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm px-6 py-16 text-center">
                    <svg class="w-12 h-12 mx-auto text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                    <p class="text-base font-bold text-gray-900 mt-4">No standings to show</p>
                    <p class="text-base text-gray-500 mt-1">
                        Rankings appear once results are entered.
                    </p>
                </div>
            @endforelse

        </div>
    </section>
@endsection
