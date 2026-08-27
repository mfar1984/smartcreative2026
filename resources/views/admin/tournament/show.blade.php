@extends('layouts.admin')

@section('title', $tournament->name)

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.tournaments.index') }}" class="hover:text-gray-700 transition">Tournaments</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ Str::limit($tournament->name, 40) }}</span>
@endsection

@section('content')
    @php
        use App\Models\Tournament;
        use App\Models\TournamentEntrant;

        $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';

        $statusTones = [
            Tournament::STATUS_SETUP => 'amber',
            Tournament::STATUS_ONGOING => 'blue',
            Tournament::STATUS_COMPLETED => 'green',
            Tournament::STATUS_PUBLISHED => 'purple',
        ];

        $entrantTones = [
            TournamentEntrant::STATUS_ACTIVE => 'green',
            TournamentEntrant::STATUS_ELIMINATED => 'gray',
            TournamentEntrant::STATUS_DISQUALIFIED => 'red',
            TournamentEntrant::STATUS_WITHDRAWN => 'amber',
        ];

        $detailTabs = [
            'progress' => ['label' => 'Progress', 'icon' => 'activity'],
            'entrants' => ['label' => 'Entrants', 'icon' => 'users', 'count' => $entrants->count()],
            'stages' => ['label' => 'Stages', 'icon' => 'grid'],
            'settings' => ['label' => 'Settings', 'icon' => 'cog'],
        ];
    @endphp

    <x-admin.settings-shell
        :title="$tournament->name"
        :description="$tournament->event?->title . ' · ' . $tournament->formatLabel() . ' · ' . ($tournament->pointRule?->name ?? 'no scoring')"
        :tabs="$detailTabs"
        :active-tab="$activeTab"
        route="admin.tournaments.show"
        :route-params="['tournament' => $tournament->id]">

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
                :title="$detailTabs[$activeTab]['label']"
                :description="$activeTab === 'progress'
                    ? 'Where this tournament has got to, and what is holding up the next step.'
                    : ($activeTab === 'entrants'
                        ? 'Who is competing. Taken from the event\'s paid entries.'
                        : ($activeTab === 'stages'
                            ? 'The rounds, groups or lobbies this tournament is played in.'
                            : 'The rules this tournament started with, kept apart from the shared defaults.'))"
                :icon="$detailTabs[$activeTab]['icon']"
                accent="blue"
                class="mb-0" />

            <div class="flex flex-wrap items-center gap-3 shrink-0">
                <x-admin.badge :tone="$statusTones[$tournament->status] ?? 'gray'" dot>
                    {{ $tournament->statusLabel() }}
                </x-admin.badge>

                @if ($canUpdate && $tournament->isSetup())
                    <a href="{{ route('admin.tournaments.edit', $tournament) }}"
                       class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                        Edit
                    </a>
                @endif
            </div>
        </div>

        {{-- ==================== Progress ==================== --}}
        @if ($activeTab === 'progress')
            @if ($nextAction)
                <div role="note" class="flex items-start gap-3 bg-blue-50 border border-blue-200 rounded-lg p-4 mb-5">
                    <x-admin.icon name="activity" class="w-5 h-5 mt-0.5 shrink-0 text-blue-600" />
                    <div class="text-sm text-blue-800">
                        <p class="font-semibold mb-0.5">Next</p>
                        <p>{{ $nextAction }}</p>
                    </div>
                </div>
            @endif

            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <ol class="divide-y divide-gray-100">
                    @foreach ($steps as $index => $step)
                        <li @class(['flex items-start gap-4 px-5 py-4', 'bg-blue-50/40' => $step['current']])>
                            {{-- State is said in text as well as shown by colour, so it does
                                 not depend on being able to tell green from grey. --}}
                            <span @class([
                                'shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold',
                                'bg-green-100 text-green-700' => $step['done'],
                                'bg-blue-600 text-white' => $step['current'],
                                'bg-gray-100 text-gray-400' => ! $step['done'] && ! $step['current'],
                            ])>
                                {{ $step['done'] ? '✓' : $index + 1 }}
                            </span>

                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-gray-900">
                                    {{ $step['label'] }}
                                    @if ($step['done'])
                                        <span class="ml-1 text-xs font-semibold text-green-700">Done</span>
                                    @elseif ($step['current'])
                                        <span class="ml-1 text-xs font-semibold text-blue-700">You are here</span>
                                    @else
                                        <span class="ml-1 text-xs font-semibold text-gray-400">Waiting</span>
                                    @endif
                                </p>

                                <p class="text-xs text-gray-500 mt-0.5">{{ $step['detail'] }}</p>

                                @if ($step['current'] && $step['blocker'])
                                    <p class="text-xs text-amber-800 mt-1.5 font-semibold">{{ $step['blocker'] }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif

        {{-- ==================== Entrants ==================== --}}
        @if ($activeTab === 'entrants')
            @if ($canUpdate && $tournament->isEditable())
                <div class="bg-white rounded-lg border border-gray-200 p-5 mb-5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-gray-900">Import from the event</p>
                            <p class="text-sm text-gray-500 mt-0.5">
                                {{ $survey['eligible']->count() }} confirmed and paid
                                {{ Str::plural('entry', $survey['eligible']->count()) }} ready to import.
                                @if ($survey['excluded']->isNotEmpty())
                                    {{ $survey['excluded']->count() }} left out:
                                    @foreach ($survey['reasons'] as $reason => $count)
                                        {{ $count }} {{ strtolower($reason) }}@if (! $loop->last), @endif
                                    @endforeach.
                                @endif
                            </p>
                        </div>

                        @if ($survey['eligible']->isNotEmpty())
                            <form action="{{ route('admin.tournaments.entrants.import', $tournament) }}" method="POST" class="shrink-0">
                                @csrf
                                <button type="submit"
                                        class="inline-flex items-center gap-2 rounded-lg border border-blue-600 bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm">
                                    <x-admin.icon name="plus" class="w-4 h-4" />
                                    Import {{ $survey['eligible']->count() }}
                                </button>
                            </form>
                        @endif
                    </div>

                    @if ($survey['excluded']->isNotEmpty())
                        <div class="mt-4 pt-4 border-t border-gray-100">
                            <p class="text-xs font-semibold text-gray-600 mb-2">
                                Add one deliberately, even if it has not been paid for
                            </p>
                            <form action="{{ route('admin.tournaments.entrants.add', $tournament) }}" method="POST"
                                  class="flex flex-wrap items-end gap-3">
                                @csrf
                                <div class="flex-1 min-w-56">
                                    <label for="event_registration_id" class="sr-only">Entry to add</label>
                                    <select id="event_registration_id" name="event_registration_id" required
                                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                                        <option value="">Choose an entry</option>
                                        @foreach ($survey['excluded'] as $registration)
                                            <option value="{{ $registration->id }}">
                                                {{ $registration->team_name ?: $registration->reference }}
                                                — {{ \App\Models\EventRegistration::PAYMENT_STATUSES[$registration->payment_status] ?? $registration->payment_status }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <button type="submit"
                                        class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                                    Add By Hand
                                </button>
                            </form>
                        </div>
                    @endif
                </div>
            @endif

            @if ($canUpdate && $tournament->isEditable() && $entrants->where('status', TournamentEntrant::STATUS_ACTIVE)->count() >= 2)
                <form action="{{ route('admin.tournaments.seed', $tournament) }}" method="POST" id="seed-form">
                    @csrf
                    <div class="bg-white rounded-lg border border-gray-200 p-5 mb-5">
                        <div class="flex flex-wrap items-end justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-900">Seeding</p>
                                <p class="text-sm text-gray-500 mt-0.5">
                                    Seed 1 meets the lowest seed, so the strongest entrants do not meet
                                    early. Type the order below, or let the system decide.
                                </p>
                            </div>

                            <div class="flex flex-wrap items-center gap-3 shrink-0">
                                <label for="method" class="sr-only">Seeding method</label>
                                <select id="method" name="method"
                                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                                    @foreach (Tournament::SEEDING_METHODS as $value => $label)
                                        <option value="{{ $value }}" @selected($tournament->seeding_method === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <button type="submit"
                                        class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                                    Apply Seeding
                                </button>
                            </div>
                        </div>
                    </div>
                {{-- Closed here, before the table.

                     The seed boxes live inside the table below and reach this form
                     through their form="seed-form" attribute instead of being nested
                     in it. Wrapping the table would put the per row Remove forms
                     inside this one, and a form cannot contain a form: the browser
                     silently drops the inner tag, so pressing Remove submitted this
                     form to the seeding route carrying _method=DELETE. --}}
                </form>
            @endif

            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="{{ $head }} w-20">Seed</th>
                                <th scope="col" class="{{ $head }}">Entrant</th>
                                <th scope="col" class="{{ $head }} text-right">Players</th>
                                <th scope="col" class="{{ $head }}">Payment</th>
                                <th scope="col" class="{{ $head }}">Status</th>
                                @if ($canUpdate && $tournament->isEditable())
                                    <th scope="col" class="{{ $head }} text-center">Remove</th>
                                @endif
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            @forelse ($entrants as $entrant)
                                @php
                                    $registration = $entrant->registration;
                                    $players = $registration?->participants ?? collect();
                                    $missingIgn = $tournament->event?->requires_ign
                                        ? $players->filter(fn ($p) => blank($p->ign_player_id))->count()
                                        : 0;
                                @endphp

                                <tr class="hover:bg-blue-50/40 align-top">
                                    <td class="px-5 py-3">
                                        @if ($canUpdate && $tournament->isEditable() && $entrant->isActive())
                                            <label for="seed-{{ $entrant->id }}" class="sr-only">
                                                Seed for {{ $entrant->displayName() }}
                                            </label>
                                            <input type="number" min="1" form="seed-form"
                                                   id="seed-{{ $entrant->id }}"
                                                   name="seeds[{{ $entrant->id }}]"
                                                   value="{{ $entrant->seed }}"
                                                   class="w-16 rounded-lg border border-gray-300 px-2 py-1.5 text-sm text-center tabular-nums focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                                        @else
                                            <span class="tabular-nums text-gray-700">{{ $entrant->seed ?? '—' }}</span>
                                        @endif
                                    </td>

                                    <td class="px-5 py-3">
                                        <span class="font-semibold text-gray-900">{{ $entrant->displayName() }}</span>
                                        <span class="block text-xs text-gray-400">{{ $registration?->reference }}</span>

                                        @if ($entrant->added_by_hand)
                                            <span class="inline-block mt-0.5 rounded bg-gray-100 px-1.5 py-0.5 text-xs font-semibold text-gray-600">
                                                Added by hand
                                            </span>
                                        @endif

                                        @if ($missingIgn > 0)
                                            <span class="block text-xs text-amber-700 mt-0.5">
                                                {{ $missingIgn }} {{ Str::plural('player', $missingIgn) }} without an in-game name
                                            </span>
                                        @endif
                                    </td>

                                    <td class="px-5 py-3 text-right tabular-nums text-gray-700">{{ $players->count() }}</td>

                                    <td class="px-5 py-3 whitespace-nowrap text-xs text-gray-600">
                                        {{ \App\Models\EventRegistration::PAYMENT_STATUSES[$registration?->payment_status] ?? '—' }}
                                    </td>

                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <x-admin.badge :tone="$entrantTones[$entrant->status] ?? 'gray'">
                                            {{ $entrant->statusLabel() }}
                                        </x-admin.badge>
                                        @if (filled($entrant->reason))
                                            <span class="block text-xs text-gray-500 mt-0.5">{{ $entrant->reason }}</span>
                                        @endif
                                    </td>

                                    @if ($canUpdate && $tournament->isEditable())
                                        {{-- Icon button, matching the Roles and Point Rules tables. The
                                             competitor's name is carried in title and aria-label so a
                                             hover and a screen reader both say who would be removed. --}}
                                        <td class="px-5 py-3 whitespace-nowrap">
                                            <div class="flex items-center justify-center gap-1">
                                                <form action="{{ route('admin.tournaments.entrants.remove', [$tournament, $entrant]) }}"
                                                      method="POST"
                                                      onsubmit="return confirm('Remove {{ addslashes($entrant->displayName()) }} from this tournament?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit"
                                                            class="p-1.5 rounded-lg text-red-600 hover:bg-red-50 transition"
                                                            title="Remove {{ $entrant->displayName() }}"
                                                            aria-label="Remove {{ $entrant->displayName() }} from this tournament">
                                                        <x-admin.icon name="trash" class="w-4 h-4" />
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-12 text-center">
                                        <x-admin.icon name="users" class="w-10 h-10 mx-auto text-gray-300" />
                                        <p class="text-sm font-semibold text-gray-700 mt-3">Nobody entered yet</p>
                                        <p class="text-sm text-gray-500 mt-1 max-w-lg mx-auto">
                                            Import the event's confirmed and paid entries, or add one
                                            deliberately. A tournament needs at least two.
                                        </p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="px-5 py-3.5 border-t border-gray-200">
                    <p class="text-xs text-gray-500">
                        {{ $entrants->count() }} {{ Str::plural('entrant', $entrants->count()) }},
                        {{ $entrants->where('status', TournamentEntrant::STATUS_ACTIVE)->count() }} active
                    </p>
                </div>
            </div>
        @endif

        {{-- ==================== Stages ==================== --}}
        @if ($activeTab === 'stages')
            @if ($stages->isNotEmpty())
                <div class="space-y-4 mb-5">
                    @foreach ($stages as $stage)
                        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                            <div class="flex flex-wrap items-start justify-between gap-4 px-5 py-4 border-b border-gray-100">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-gray-900">
                                        {{ $stage->sequence }}. {{ $stage->name }}
                                        <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-xs font-semibold text-gray-600">
                                            {{ $stage->typeLabel() }}
                                        </span>
                                    </p>
                                    <p class="text-xs text-gray-500 mt-0.5">
                                        {{ $stage->matches_count }} {{ Str::plural('fixture', $stage->matches_count) }}
                                        @if ($stage->groups_count > 0)
                                            in {{ $stage->groups_count }} {{ $stage->type === 'lobby' ? Str::plural('lobby', $stage->groups_count) : Str::plural('group', $stage->groups_count) }}
                                        @endif
                                        @if ($stage->advance_count > 0)
                                            · top {{ $stage->advance_count }} advance
                                        @endif
                                        @if ($stage->hasDraw())
                                            · drawn {{ $stage->drawn_at->format('d M, g:i a') }}
                                        @endif
                                    </p>
                                </div>

                                <div class="flex flex-wrap items-center gap-2 shrink-0">
                                    @if ($stage->hasDraw())
                                        <a href="{{ route('admin.tournaments.matches', ['tournament' => $tournament->id, 'stage' => $stage->id]) }}"
                                           class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                                            View Fixtures
                                        </a>

                                        @if ($canGenerate)
                                            <form action="{{ route('admin.tournaments.stages.discard', [$tournament, $stage]) }}" method="POST"
                                                  onsubmit="return confirm('Discard the draw for {{ addslashes($stage->name) }}?\n\nEvery fixture in it is deleted. Refused if anything has been scored.');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 transition">
                                                    Discard Draw
                                                </button>
                                            </form>
                                        @endif
                                    @else
                                        @if ($canGenerate)
                                            <form action="{{ route('admin.tournaments.stages.generate', [$tournament, $stage]) }}" method="POST">
                                                @csrf
                                                <button type="submit"
                                                        class="inline-flex items-center gap-2 rounded-lg border border-blue-600 bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm">
                                                    <x-admin.icon name="grid" class="w-4 h-4" />
                                                    Generate Draw
                                                </button>
                                            </form>
                                        @endif

                                        @if ($canUpdate)
                                            <form action="{{ route('admin.tournaments.stages.destroy', [$tournament, $stage]) }}" method="POST"
                                                  onsubmit="return confirm('Remove stage {{ addslashes($stage->name) }}?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-sm font-semibold text-red-600 hover:underline px-2">Remove</button>
                                            </form>
                                        @endif
                                    @endif
                                </div>
                            </div>

                            @if (! $stage->hasDraw())
                                <p class="px-5 py-3 text-xs text-gray-500">
                                    {{ $stage->refusal ?? 'Ready to draw.' }}
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($canUpdate && ! $tournament->isPublished())
                <x-admin.panel title="Add A Stage" icon="plus">
                    <div class="px-5 py-4">
                        <p class="text-sm text-gray-600 mb-4">
                            A tournament can hold several stages of different kinds in sequence. That
                            is how a group stage into a knockout works, and how a knockout down to the
                            last four into a double elimination playoff works.
                        </p>

                        <form action="{{ route('admin.tournaments.stages.store', $tournament) }}" method="POST"
                              class="space-y-4">
                            @csrf

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="stage-name" class="block text-xs font-semibold text-gray-700 mb-1">Name</label>
                                    <input type="text" id="stage-name" name="name" required maxlength="120"
                                           placeholder="e.g. Qualifiers, Playoff, Grand Final"
                                           class="w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                                </div>

                                <div>
                                    <label for="stage-type" class="block text-xs font-semibold text-gray-700 mb-1">Kind</label>
                                    <select id="stage-type" name="type" required data-stage-type
                                            class="w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                                        @foreach (\App\Models\TournamentStage::TYPES as $value => $label)
                                            <option value="{{ $value }}" @selected($value === $suggestedStageType)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <p class="text-xs text-gray-500 mt-1" data-stage-note></p>
                                </div>

                                <div>
                                    <label for="stage-advance" class="block text-xs font-semibold text-gray-700 mb-1">
                                        How many advance from each group
                                    </label>
                                    <input type="number" id="stage-advance" name="advance_count" min="0" max="512" value="0"
                                           class="w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                                    <p class="text-xs text-gray-500 mt-1">Zero when this is the last stage.</p>
                                </div>

                                <div>
                                    <label for="stage-count" class="block text-xs font-semibold text-gray-700 mb-1">
                                        Matches per lobby, or number of groups
                                    </label>
                                    <input type="number" id="stage-count" name="match_count" min="1" max="32" value="3"
                                           class="w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                                    <p class="text-xs text-gray-500 mt-1">
                                        Lobbies and heats: how many matches each plays. Groups: how many groups.
                                    </p>
                                </div>
                            </div>

                            <fieldset>
                                <legend class="block text-xs font-semibold text-gray-700 mb-2">
                                    Best-of per round
                                </legend>
                                <div class="flex flex-wrap gap-3">
                                    @foreach (range(1, 5) as $round)
                                        <div>
                                            <label for="bo-{{ $round }}" class="block text-xs text-gray-500 mb-1 text-center">
                                                R{{ $round }}
                                            </label>
                                            <input type="number" id="bo-{{ $round }}" name="best_of[{{ $round }}]"
                                                   min="1" max="9" value="{{ [1, 1, 3, 3, 5][$round - 1] }}"
                                                   class="w-16 rounded-lg border border-gray-300 px-2 py-1.5 text-sm text-center tabular-nums focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                                        </div>
                                    @endforeach
                                </div>
                                <p class="text-xs text-gray-500 mt-2">
                                    Single games early to save time, longer series later. Ignored for
                                    lobbies and heats.
                                </p>
                            </fieldset>

                            <div class="pt-2">
                                <button type="submit"
                                        class="rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                                    Add Stage
                                </button>
                            </div>
                        </form>
                    </div>
                </x-admin.panel>
            @endif

            @if ($stages->isEmpty())
                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden mt-5">
                    <div class="px-5 py-10 text-center">
                        <x-admin.icon name="grid" class="w-10 h-10 mx-auto text-gray-300" />
                        <p class="text-sm font-semibold text-gray-700 mt-3">No stages yet</p>
                        <p class="text-sm text-gray-500 mt-1 max-w-lg mx-auto">
                            @unless ($drawState['allowed'])
                                {{ $drawState['reason'] }}
                            @else
                                Add a stage above, then generate its draw.
                            @endunless
                        </p>
                    </div>
                </div>
            @endif
        @endif

        {{-- ==================== Settings ==================== --}}
        @if ($activeTab === 'settings')
            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        @foreach ([
                            'Buffer between matches' => $tournament->setting('buffer_minutes') . ' minutes',
                            'Forfeit after' => $tournament->setting('lateness_minutes') . ' minutes late',
                            'Result screenshot' => $tournament->setting('require_proof') ? 'Required' : 'Optional',
                            'Public rankings' => $tournament->setting('public_rankings_live') ? 'Live while playing' : 'Only once published',
                            'Devices' => Str::headline((string) $tournament->setting('device_rule')),
                            'Seeding' => $tournament->seedingLabel(),
                        ] as $label => $value)
                            <tr>
                                <th scope="row" class="px-5 py-3 text-left font-normal text-gray-500 w-64">{{ $label }}</th>
                                <td class="px-5 py-3 text-gray-900">{{ $value }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="px-5 py-3.5 border-t border-gray-200">
                    <p class="text-xs text-gray-500">
                        Copied from the shared defaults when this tournament was created, so later
                        changes to those defaults leave it alone.
                    </p>
                </div>
            </div>
        @endif
    </x-admin.settings-shell>
@endsection
