@extends('layouts.admin')

@section('title', 'Standings')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Tournament</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Standings</span>
@endsection

@section('content')
    @php
        $head = 'px-4 py-2.5 text-xs font-bold uppercase tracking-wide text-gray-500';
        $filterInput = 'rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
    @endphp

    {{-- Tabs are the stages of the chosen tournament, not a fixed list, so the shell
         draws no tab bar until there is something to name. --}}
    <x-admin.settings-shell
        title="Standings"
        description="Worked out from the matches. No figure on this screen is typed in by hand."
        :tabs="$tabs"
        :active-tab="$activeTab"
        route="admin.tournaments.standings"
        :route-params="$tournament ? ['tournament' => $tournament->id] : []">

        <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
            <x-admin.section-intro
                :title="$onPlayers ? 'Player Leaderboard' : ($stage?->name ?? 'Standings')"
                :description="$onPlayers
                    ? 'Personal totals, counted separately from every team table.'
                    : ($stage
                        ? $stage->typeLabel() . ($stage->advance_count > 0 ? ' · top ' . $stage->advance_count . ' advance from each group' : '')
                        : 'Placement, kills and penalties add up here. Correct a match and this follows.')"
                :icon="$onPlayers ? 'users' : 'activity'"
                accent="blue"
                class="mb-0" />

            @if ($tournament && $canExport)
                <a href="{{ route('admin.tournaments.standings.export', ['tournament' => $tournament, 'view' => $onPlayers ? 'players' : null]) }}"
                   class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition shrink-0">
                    <x-admin.icon name="archive" class="w-4 h-4" />
                    {{ $onPlayers ? 'Export Players CSV' : 'Export CSV' }}
                </a>
            @endif
        </div>

        @if ($tournaments->isEmpty())
            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <div class="px-5 py-12 text-center">
                    <x-admin.icon name="activity" class="w-10 h-10 mx-auto text-gray-300" />
                    <p class="text-sm font-semibold text-gray-700 mt-3">Nothing to rank yet</p>
                    <p class="text-sm text-gray-500 mt-1 max-w-lg mx-auto">
                        Standings fill in as match results are entered.
                    </p>
                </div>
            </div>
        @else
            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <x-admin.filter-bar :action="route('admin.tournaments.standings')">
                    <label for="tournament" class="sr-only">Tournament</label>
                    <select id="tournament" name="tournament" class="{{ $filterInput }} min-w-56">
                        @foreach ($tournaments as $option)
                            <option value="{{ $option->id }}" @selected($tournament?->id === $option->id)>
                                {{ $option->name }} — {{ $option->event?->title }}
                            </option>
                        @endforeach
                    </select>
                </x-admin.filter-bar>

                @if ($onPlayers)
                    {{-- ===== The player leaderboard =====
                         A separate table read from tournament_player_standings. None of
                         these numbers appear in the squad tables on the other tabs, and
                         none of those appear here. --}}
                    <div class="px-4 py-2.5 bg-blue-50 border-y border-blue-100">
                        <p class="text-xs text-blue-800">
                            Personal totals across the whole tournament. Counted on their own and
                            never added to a team's points.
                        </p>
                    </div>

                    @if ($playerRows->isEmpty())
                        <div class="px-5 py-12 text-center">
                            <x-admin.icon name="users" class="w-10 h-10 mx-auto text-gray-300" />
                            <p class="text-sm font-semibold text-gray-700 mt-3">No personal scores yet</p>
                            <p class="text-sm text-gray-500 mt-1 max-w-lg mx-auto">
                                Player scoring is optional. Expand a team on the score form to record
                                individual figures, or switch it off in
                                <a href="{{ route('admin.tournaments.rules', ['tab' => $tournament->pointRule?->kind]) }}"
                                   class="underline font-semibold text-blue-600">Point Rules</a>
                                under Personal Player Scoring.
                            </p>
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 text-left">
                                    <tr>
                                        <th scope="col" class="{{ $head }} w-12">#</th>
                                        <th scope="col" class="{{ $head }}">Player</th>
                                        <th scope="col" class="{{ $head }}">Team</th>
                                        <th scope="col" class="{{ $head }} text-right">Matches</th>
                                        @foreach ($playerColumns as $column)
                                            <th scope="col" class="{{ $head }} text-right">{{ $column['label'] }}</th>
                                        @endforeach
                                        <th scope="col" class="{{ $head }} text-right">Total</th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($playerRows as $row)
                                        <tr @class(['align-top', 'bg-red-50/40' => $row->entrant_is_disqualified])>
                                            <td class="px-4 py-2.5 tabular-nums font-semibold text-gray-900">{{ $row->rank }}</td>

                                            <td class="px-4 py-2.5">
                                                <span class="font-semibold text-gray-900">{{ $row->display_name }}</span>
                                                @if ($row->ign)
                                                    <span class="block text-xs text-gray-400">IGN {{ $row->ign }}</span>
                                                @endif
                                            </td>

                                            <td class="px-4 py-2.5 text-gray-700">
                                                {{ $row->entrant?->displayName() ?? 'Removed entry' }}
                                                @if ($row->entrant_is_disqualified)
                                                    <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-800">Team DQ</span>
                                                @endif
                                            </td>

                                            <td class="px-4 py-2.5 text-right tabular-nums text-gray-600">{{ $row->matches_played }}</td>

                                            @foreach ($playerColumns as $column)
                                                <td class="px-4 py-2.5 text-right tabular-nums text-gray-700">
                                                    @if ($column['counted'])
                                                        {{ $row->componentCount($column['key']) }}
                                                        @if ($row->componentTotal($column['key']) != $row->componentCount($column['key']))
                                                            <span class="block text-xs text-gray-400">
                                                                {{ $row->componentTotal($column['key']) + 0 }} pts
                                                            </span>
                                                        @endif
                                                    @else
                                                        {{ $row->componentTotal($column['key']) + 0 }}
                                                    @endif
                                                </td>
                                            @endforeach

                                            <td class="px-4 py-2.5 text-right tabular-nums font-bold text-gray-900">
                                                {{ $row->total_points + 0 }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @if (filled($tournament->pointRule?->player_tiebreak))
                            <div class="px-4 py-3 border-t border-gray-200 bg-gray-50">
                                <p class="text-xs text-gray-600">
                                    Player ties broken by
                                    @foreach ($tournament->pointRule->player_tiebreak as $index => $key)
                                        {{ $index + 1 }}. {{ Str::headline($key) }}@if (! $loop->last), @endif
                                    @endforeach.
                                </p>
                            </div>
                        @endif
                    @endif
                @else
                @forelse ($groups as $groupName => $rows)
                    @if ($groups->count() > 1)
                        <p class="px-4 py-2.5 bg-blue-50 border-y border-blue-100 text-xs font-bold uppercase tracking-wide text-blue-800">
                            {{ $groupName }}
                        </p>
                    @endif

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 text-left">
                                <tr>
                                    <th scope="col" class="{{ $head }} w-12">#</th>
                                    <th scope="col" class="{{ $head }}">Entrant</th>
                                    <th scope="col" class="{{ $head }} text-right">Played</th>
                                    @foreach ($columns as $column)
                                        <th scope="col" class="{{ $head }} text-right">{{ $column['label'] }}</th>
                                    @endforeach
                                    <th scope="col" class="{{ $head }} text-right">Total</th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-gray-100">
                                @foreach ($rows as $standing)
                                    @php
                                        // The cut line: drawn under the last entrant who
                                        // carries on, so the boundary is visible rather
                                        // than counted by the reader.
                                        $isLastAdvancing = $standing->advances
                                            && ! ($rows[$loop->index + 1]->advances ?? false);
                                    @endphp

                                    <tr @class([
                                        'align-top',
                                        'bg-green-50/40' => $standing->advances,
                                        'bg-red-50/40' => $standing->is_disqualified,
                                        'border-b-2 border-b-blue-300' => $isLastAdvancing,
                                    ])>
                                        <td class="px-4 py-2.5 tabular-nums font-semibold text-gray-900">
                                            {{ $standing->rank }}
                                        </td>

                                        <td class="px-4 py-2.5">
                                            <span class="font-semibold text-gray-900">
                                                {{ $standing->entrant?->displayName() ?? 'Removed entry' }}
                                            </span>

                                            {{-- Status in words, not colour alone. --}}
                                            @if ($standing->is_disqualified)
                                                <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-800">DQ</span>
                                            @elseif ($standing->advances)
                                                <span class="ml-1 rounded bg-green-100 px-1.5 py-0.5 text-xs font-semibold text-green-800">Advances</span>
                                            @endif

                                            @if ($standing->is_tied)
                                                <span class="block text-xs text-amber-700 mt-0.5">
                                                    Level after every tie-break. The organiser has to settle it.
                                                </span>
                                            @endif
                                        </td>

                                        <td class="px-4 py-2.5 text-right tabular-nums text-gray-600">{{ $standing->played }}</td>

                                        @foreach ($columns as $column)
                                            <td class="px-4 py-2.5 text-right tabular-nums text-gray-700">
                                                @if ($column['counted'])
                                                    {{ $standing->componentCount($column['key']) }}
                                                    @if ($standing->componentTotal($column['key']) != $standing->componentCount($column['key']))
                                                        <span class="block text-xs text-gray-400">
                                                            {{ $standing->componentTotal($column['key']) + 0 }} pts
                                                        </span>
                                                    @endif
                                                @else
                                                    {{ $standing->componentTotal($column['key']) + 0 }}
                                                @endif
                                            </td>
                                        @endforeach

                                        <td class="px-4 py-2.5 text-right tabular-nums font-bold text-gray-900">
                                            {{ $standing->total_points + 0 }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @empty
                    <div class="px-5 py-12 text-center">
                        <x-admin.icon name="activity" class="w-10 h-10 mx-auto text-gray-300" />
                        <p class="text-sm font-semibold text-gray-700 mt-3">Nothing to rank yet</p>
                        <p class="text-sm text-gray-500 mt-1 max-w-lg mx-auto">
                            Standings fill in as match results are entered.
                        </p>
                    </div>
                @endforelse

                @if ($tournament?->pointRule && filled($tournament->pointRule->tiebreak))
                    <div class="px-4 py-3 border-t border-gray-200 bg-gray-50">
                        <p class="text-xs text-gray-600">
                            Ties broken by
                            @foreach ($tournament->pointRule->tiebreak as $index => $key)
                                {{ $index + 1 }}. {{ Str::headline($key) }}@if (! $loop->last), @endif
                            @endforeach.
                        </p>
                    </div>
                @endif
                @endif
            </div>
        @endif
    </x-admin.settings-shell>
@endsection
