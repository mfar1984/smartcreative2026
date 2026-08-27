@extends('layouts.admin')

@section('title', 'Matches')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Tournament</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $intro['label'] }}</span>
@endsection

@section('content')
    @php
        use App\Models\TournamentMatch;

        $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
        $filterInput = 'rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
    @endphp

    <x-admin.settings-shell
        title="Matches"
        description="Fixtures to be played, and where results are entered."
        :tabs="$tabs"
        :active-tab="$activeTab"
        :route="$route"
        :route-params="$tournament ? ['tournament' => $tournament->id] : []">

        @if (session('status'))
            <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-5">
                <p class="text-sm text-green-800">{{ session('status') }}</p>
            </div>
        @endif

        @if ($errors->any())
            <div role="alert" class="bg-red-50 border border-red-200 rounded-lg p-4 mb-5">
                <ul class="text-sm text-red-800 space-y-0.5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
            <x-admin.section-intro
                :title="$intro['title']"
                :description="$intro['description']"
                :icon="$intro['icon']"
                :accent="$intro['accent']"
                class="mb-0" />

            {{-- Which tournament is on screen, said at all times. Several run at once,
                 so a fixture list without a name on it could be any of them. --}}
            @if ($tournament)
                <div class="inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3.5 py-2.5 shrink-0">
                    <x-admin.icon name="trophy" class="w-4 h-4 text-blue-600" />
                    <span class="text-sm font-semibold text-blue-900">{{ $tournament->name }}</span>
                    <span class="text-xs text-blue-700">{{ $tournament->event?->title }}</span>
                </div>
            @endif
        </div>

        @if ($tournaments->isEmpty())
            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <div class="px-5 py-12 text-center">
                    <x-admin.icon name="clipboard" class="w-10 h-10 mx-auto text-gray-300" />
                    <p class="text-sm font-semibold text-gray-700 mt-3">No fixtures yet</p>
                    <p class="text-sm text-gray-500 mt-1 max-w-lg mx-auto">
                        Fixtures appear once a tournament's draw has been generated.
                        <a href="{{ route('admin.tournaments.index') }}" class="underline font-semibold">Open Tournaments</a>
                        to set one up.
                    </p>
                </div>
            </div>
        @else
            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <x-admin.filter-bar :action="route('admin.tournaments.matches')">
                    <input type="hidden" name="tab" value="{{ $activeTab }}">

                    <label for="tournament" class="sr-only">Tournament</label>
                    <select id="tournament" name="tournament" class="{{ $filterInput }} min-w-56">
                        @foreach ($tournaments as $option)
                            <option value="{{ $option->id }}" @selected($tournament?->id === $option->id)>
                                {{ $option->name }} — {{ $option->event?->title }}
                            </option>
                        @endforeach
                    </select>

                    @if ($stages->isNotEmpty())
                        <label for="stage" class="sr-only">Stage</label>
                        <select id="stage" name="stage" class="{{ $filterInput }}">
                            <option value="">All Stages</option>
                            @foreach ($stages as $stage)
                                <option value="{{ $stage->id }}" @selected($filters['stage'] === (string) $stage->id)>
                                    {{ $stage->name }}
                                </option>
                            @endforeach
                        </select>
                    @endif
                </x-admin.filter-bar>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="{{ $head }}">Match</th>
                                <th scope="col" class="{{ $head }}">Stage</th>
                                <th scope="col" class="{{ $head }}">Who</th>
                                <th scope="col" class="{{ $head }}">Map</th>
                                <th scope="col" class="{{ $head }}">When</th>
                                <th scope="col" class="{{ $head }} text-center">Action</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            @forelse ($matches ?? [] as $match)
                                <tr class="hover:bg-blue-50/40 align-top">
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <span class="font-semibold text-gray-900">{{ $match->label() }}</span>
                                        @if ($match->best_of > 1)
                                            <span class="block text-xs text-gray-500">Best of {{ $match->best_of }}</span>
                                        @endif
                                        @if ($match->resolution)
                                            <span class="block text-xs text-amber-700">{{ Str::headline($match->resolution) }}</span>
                                        @endif
                                    </td>

                                    <td class="px-5 py-3 text-gray-700">
                                        {{ $match->stage?->name }}
                                        @if ($match->group)
                                            <span class="block text-xs text-gray-500">{{ $match->group->name }}</span>
                                        @endif
                                    </td>

                                    <td class="px-5 py-3">
                                        @php $named = $match->entrants->filter(fn ($l) => $l->entrant !== null); @endphp

                                        @if ($named->count() > 4)
                                            <span class="text-gray-700">{{ $named->count() }} competitors</span>
                                        @elseif ($named->isEmpty())
                                            <span class="text-xs text-gray-400">Waiting on an earlier result</span>
                                        @else
                                            @foreach ($named as $line)
                                                <span @class([
                                                    'block text-xs',
                                                    'font-semibold text-green-800' => $match->winner_entrant_id === $line->tournament_entrant_id,
                                                    'text-gray-700' => $match->winner_entrant_id !== $line->tournament_entrant_id,
                                                ])>
                                                    {{ $line->entrant->displayName() }}
                                                    @if ($match->winner_entrant_id === $line->tournament_entrant_id)
                                                        (won)
                                                    @endif
                                                </span>
                                            @endforeach
                                        @endif
                                    </td>

                                    <td class="px-5 py-3 text-xs text-gray-600">{{ $match->map ?? '—' }}</td>

                                    <td class="px-5 py-3 whitespace-nowrap text-xs text-gray-500">
                                        {{ $match->scheduled_at?->format('d M, g:i a') ?? '—' }}
                                    </td>

                                    <td class="px-5 py-3 text-center whitespace-nowrap">
                                        @if ($canScore && $match->isReady())
                                            <a href="{{ route('admin.tournaments.matches.score', $match) }}"
                                               class="inline-flex items-center gap-1.5 rounded-lg border border-blue-300 bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-50 transition">
                                                {{ $match->isSettled() ? 'Correct' : 'Enter Score' }}
                                            </a>
                                        @elseif (! $match->isReady())
                                            <span class="text-xs text-gray-400">Not ready</span>
                                        @else
                                            <span class="text-xs text-gray-300">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-12 text-center">
                                        <x-admin.icon name="clipboard" class="w-10 h-10 mx-auto text-gray-300" />
                                        <p class="text-sm font-semibold text-gray-700 mt-3">
                                            Nothing {{ strtolower($intro['label']) }}
                                        </p>
                                        <p class="text-sm text-gray-500 mt-1">
                                            Fixtures appear once a tournament's draw has been generated.
                                        </p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="px-5 py-3.5 border-t border-gray-200">
                    @if ($matches && $matches->hasPages())
                        {{ $matches->links() }}
                    @else
                        <p class="text-xs text-gray-500">
                            {{ $matches?->total() ?? 0 }} {{ Str::plural('fixture', $matches?->total() ?? 0) }}
                        </p>
                    @endif
                </div>
            </div>
        @endif
    </x-admin.settings-shell>
@endsection
