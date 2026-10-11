@extends('layouts.admin')

@section('title', 'Score ' . $match->label())

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.tournaments.matches', ['tournament' => $tournament->id]) }}" class="hover:text-gray-700 transition">Matches</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $match->label() }}</span>
@endsection

@section('content')
    @php
        $head = 'px-4 py-2.5 text-xs font-bold uppercase tracking-wide text-gray-500';
        $cell = 'w-20 rounded-lg border border-gray-300 px-2 py-1.5 text-sm text-center tabular-nums text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';

        /*
         | Two ways in, and never both at once.
         |
         | Slot mode names a few players out of the whole fixture and so has no per-squad
         | panels; roster mode lists everybody and so has no dialog. Showing both would
         | let the same player be entered twice for one match, with whichever was written
         | last silently winning.
         */
        $picksPlayers = $playerSlots !== null && $pickable !== [];
        $tracksPlayers = ! $picksPlayers && $playerInputs !== [] && $rosters !== [];

        /*
         | Star of the Match rides in the same dialog as the top players, so pressing
         | Save asks for everything about the players in one place. Either one alone is
         | enough reason to open it.
         */
        $hasAwards = $matchAwards !== [] && $pickable !== [];
        $opensDialog = $picksPlayers || $hasAwards;

        /*
         | Whether the game's Star of the Match screen can be read into those cards.
         | $readsAwards is the profile's answer; the rest is this screen's: no awards
         | on the dialog and nobody to give one to means there is nothing to fill in.
         */
        $readsAwardSheet = $canScore && $hasAwards && $readsAwards;

        /*
         | The three screenshot readers, and what each of them needs on this screen.
         |
         | The results table reader needs a table of positions and counts to fill in,
         | which is what $sheetFields answers.
         |
         | The per-player reader needs both halves: the same table, because a block's
         | rank is a placement and its eliminations added up are a kill count, and the
         | per-squad player panels, because that is where each player's own figure
         | goes. Slot mode has no panels, so $tracksPlayers is the right question.
         */
        $readsSheet = $canScore && $sheetFields !== null;
        $readsPlayerSheet = $readsSheet && $tracksPlayers;

        // One set of review-panel helpers is shipped for whichever readers are on.
        $readsAnything = $readsSheet || $readsAwardSheet || $readsPlayerSheet;

        /*
         | Marks only one competitor in the fixture may hold, and who currently holds
         | each of them.
         |
         | Read once here rather than per row, because a radio group needs to know the
         | answer for the whole column before it draws any of it, including the row that
         | says nobody.
         */
        $singleInputs = collect($inputs)
            ->filter(fn (array $definition) => ($definition['type'] ?? null) === 'toggle'
                && ! empty($definition['single_in_match']))
            ->keyBy('key');

        $singleHeldBy = $singleInputs
            ->mapWithKeys(fn (array $definition) => [
                $definition['key'] => $lines
                    ->first(fn ($line) => (int) $line->input($definition['key']) === 1)
                    ?->tournament_entrant_id ?? 0,
            ])
            ->all();

        /*
         | Which team field holds the head count, read from the profile rather than
         | assumed to be called players_present. It is the input the profile marks as
         | measured against squad_size. Used only to point the copy button at it.
         */
        $squadField = collect($inputs)
            ->firstWhere(fn ($definition) => ($definition['max_from'] ?? null) === 'squad_size')['key'] ?? null;
    @endphp

    <x-admin.page-card
        :title="'Score ' . $match->label()"
        :description="$tournament->name . ' · ' . ($match->stage?->name ?? '') . ($match->group ? ' · ' . $match->group->name : '') . ($match->map ? ' · ' . $match->map : '')"
        :back="route('admin.tournaments.matches', ['tournament' => $tournament->id])">

        @if ($errors->any())
            <div role="alert" class="bg-red-50 border border-red-200 rounded-lg p-4 mb-5">
                <p class="text-sm font-bold text-red-900 mb-1">Nothing was saved</p>
                <ul class="text-sm text-red-800 space-y-0.5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($match->isSettled())
            <div role="note" class="flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-lg p-4 mb-5">
                <x-admin.icon name="lock" class="w-5 h-5 mt-0.5 shrink-0 text-amber-600" />
                <p class="text-sm text-amber-800">
                    This fixture already has a result, entered
                    {{ \App\Support\LocalTime::format($match->scored_at, \App\Support\LocalTime::chosenDateTimeFormat(), '') }}
                    @if ($match->scorer) by {{ $match->scorer->name }} @endif.
                    Saving again corrects it and works the standings out afresh.
                </p>
            </div>
        @endif

        @if ($rule === null)
            <div role="alert" class="bg-red-50 border border-red-200 rounded-lg p-4">
                <p class="text-sm text-red-800">
                    This tournament has no point rule, so there is nothing to score against.
                </p>
            </div>
        @else
            {{-- ===== Read it off a screenshot =====
                 Seventeen rows of position and kills typed by hand at a desk with a
                 results screen in the other hand is where the mistakes come from. The
                 table is read off an image and offered for checking; it is never saved
                 from here.

                 Above the form and outside it, both deliberately: outside, so no file
                 picked here is posted with the result, and above, so the rows it fills
                 in are the ones directly below. Only drawn where the profile has a
                 table of positions to fill, which is what $sheetFields answers. --}}
            @if ($readsSheet)
                <x-admin.score-review
                    prefix="sheet"
                    id="sheet-dialog"
                    :url="route('admin.tournaments.matches.score.read', $match)"
                    picker="sheet-images"
                    pick="Results table screenshots"
                    :about="$match->label() . ' · check every row against the image, then Apply. Untick anything you would rather type yourself.'"
                    :note="'Apply only types these figures into the form. Nothing is saved until you press ' . ($match->isSettled() ? 'Correct Result' : 'Save Result') . '.'">

                    <div data-sheet-rows class="space-y-2"></div>
                    <div data-sheet-clashes class="hidden mt-5"></div>
                    <div data-sheet-unmatched class="hidden mt-5"></div>
                    <div data-sheet-uncovered class="hidden mt-5"></div>
                </x-admin.score-review>
            @endif

            {{-- ===== Read the per-player result screen =====
                 The game's own result list: a block per squad with a rank number and
                 its players, each with an elimination count. One image gives the
                 placement, the kill count, every player's own figure and the chicken
                 dinner, which is the whole of this form for three squads at a time.

                 Above the form and outside it, for the same two reasons as the reader
                 above: outside, so no file picked here is posted with the result, and
                 above, so the rows it fills in are the ones directly below. --}}
            @if ($readsPlayerSheet)
                <x-admin.score-review
                    prefix="player-sheet"
                    id="player-sheet-dialog"
                    :url="route('admin.tournaments.matches.score.read-players', $match)"
                    picker="player-sheet-images"
                    pick="Per-player result screenshots"
                    :about="$match->label() . ' · check every squad and every player against the image, then Apply. Untick anything you would rather type yourself.'"
                    :note="'Apply only types these figures into the form, and never changes who played. Nothing is saved until you press ' . ($match->isSettled() ? 'Correct Result' : 'Save Result') . '.'">

                    <div data-player-sheet-rows class="space-y-2"></div>
                    <div data-player-sheet-clashes class="hidden mt-5"></div>
                    <div data-player-sheet-unmatched class="hidden mt-5"></div>
                    <div data-player-sheet-uncovered class="hidden mt-5"></div>
                </x-admin.score-review>
            @endif

            {{-- The form is built from the profile's declared inputs. There is no branch
                 on the sport anywhere here: PUBG asks for placement, kills and players
                 because its profile says so, and badminton asks for sets because its
                 profile says so. --}}
            {{-- data-skip-unopened: a squad's player panel that was never opened is not
                 sent. Twenty squads of five with eight stats each is close to a thousand
                 fields, which is where PHP stops reading a form and silently drops the
                 rest; the last squad on the list then arrived with no result at all. --}}
            <form action="{{ route('admin.tournaments.matches.score.save', $match) }}" method="POST"
                  enctype="multipart/form-data" id="score-form"
                  @if (! $rule->requiresPlayers()) data-skip-unopened @endif>
                @csrf
                @method('PUT')

                {{-- First and last field of the form. If the first arrives without the
                     last, the server only received part of it and refuses to save. --}}
                <input type="hidden" name="_form_start" value="1">

                <x-admin.panel :title="'Result — ' . $rule->name" icon="clipboard" :flush="true">
                    {{-- In the panel's own header because that is where the table it
                         fills in starts. A plain button, never a submit: it opens the
                         picker outside this form and the result is still saved by the
                         button at the bottom. --}}
                    @if ($readsSheet)
                        <x-slot:actions>
                            <span data-sheet-status role="status" aria-live="polite"
                                  class="hidden text-xs mr-2"></span>

                            <button type="button" data-sheet-pick
                                    title="Read the placement and kills off a photograph of the results table"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-blue-600 bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700 transition shadow-sm disabled:opacity-50 disabled:cursor-not-allowed">
                                <x-admin.icon name="clipboard" class="w-3.5 h-3.5" />
                                Detect Image AI
                            </button>

                            {{-- The second reader, beside the first because it fills in
                                 the same table — and more of it. Named for the screen it
                                 reads rather than for what it does, because the operator
                                 is choosing between two screenshots in front of them. --}}
                            @if ($readsPlayerSheet)
                                <span data-player-sheet-status role="status" aria-live="polite"
                                      class="hidden text-xs mx-2"></span>

                                <button type="button" data-player-sheet-pick
                                        title="Read the game's per-player result screen: each squad's rank and every player's eliminations"
                                        class="ml-1.5 inline-flex items-center gap-1.5 rounded-lg border border-blue-600 bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-50 transition shadow-sm disabled:opacity-50 disabled:cursor-not-allowed">
                                    <x-admin.icon name="users" class="w-3.5 h-3.5" />
                                    Detect Image AI (Per Player)
                                </button>
                            @endif
                        </x-slot:actions>
                    @endif

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 text-left">
                                <tr>
                                    <th scope="col" class="{{ $head }}">Competitor</th>
                                    @foreach ($inputs as $definition)
                                        <th scope="col" class="{{ $head }} text-center">
                                            {{ $definition['label'] ?? $definition['key'] }}
                                            @if ($definition['required'] ?? false)
                                                <span class="text-red-500" aria-hidden="true">*</span>
                                                <span class="sr-only">required</span>
                                            @endif
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-gray-100">
                                @foreach ($lines as $line)
                                    @php $entrant = $line->entrant; @endphp

                                    @if ($entrant === null)
                                        <tr>
                                            <td colspan="{{ count($inputs) + 1 }}" class="px-4 py-3 text-xs text-gray-400">
                                                Slot {{ $line->slot }} is waiting on an earlier result.
                                            </td>
                                        </tr>
                                        @continue
                                    @endif

                                    @php
                                        $entrantId = $line->tournament_entrant_id;
                                        $roster = $rosters[$entrantId] ?? collect();
                                        $onFile = $recorded[$entrantId] ?? [];
                                        $showPlayers = $tracksPlayers && $roster->isNotEmpty();
                                    @endphp

                                    <tr class="hover:bg-blue-50/30 align-top">
                                        <th scope="row" class="px-4 py-3 text-left font-normal">
                                            <div class="flex items-start gap-2">
                                                @if ($showPlayers)
                                                    <button type="button"
                                                            class="mt-0.5 shrink-0 text-gray-400 hover:text-blue-600 transition"
                                                            data-player-toggle="players-{{ $entrantId }}"
                                                            aria-expanded="false"
                                                            aria-controls="players-{{ $entrantId }}">
                                                        <span class="sr-only">Show personal scores for {{ $entrant->displayName() }}</span>
                                                        <svg data-chevron class="w-4 h-4 transition-transform" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                                        </svg>
                                                    </button>
                                                @endif

                                                <span>
                                                    <span class="text-sm font-semibold text-gray-900">{{ $entrant->displayName() }}</span>
                                                    @if ($entrant->seed)
                                                        <span class="block text-xs text-gray-400">Seed {{ $entrant->seed }}</span>
                                                    @endif
                                                </span>
                                            </div>
                                        </th>

                                        @foreach ($inputs as $definition)
                                            @php
                                                $key = $definition['key'];
                                                $name = "lines[{$line->tournament_entrant_id}][{$key}]";
                                                $id = "line-{$line->tournament_entrant_id}-{$key}";
                                                $current = old("lines.{$line->tournament_entrant_id}.{$key}", $line->input($key));
                                                $max = ($definition['max_from'] ?? null) === 'squad_size'
                                                    ? $rule->squad_size
                                                    : ($definition['max'] ?? null);
                                            @endphp

                                            <td class="px-4 py-3 text-center">
                                                @if ($definition['type'] === 'toggle' && ! empty($definition['single_in_match']))
                                                    {{-- One competitor out of the fixture, so one radio
                                                         group across every row. They share a name, which
                                                         is what makes the browser enforce the single
                                                         choice: the rule is in the control rather than in
                                                         a script that unticks things afterwards.

                                                         The value is the competitor, not a yes or no. It
                                                         is turned back into a per-competitor 0 or 1 when
                                                         the result is read, so nothing downstream knows
                                                         this changed. --}}
                                                    <input type="radio"
                                                           id="{{ $id }}"
                                                           name="single[{{ $key }}]"
                                                           value="{{ $entrantId }}"
                                                           @checked((int) old('single.' . $key, $singleHeldBy[$key] ?? 0) === (int) $entrantId)
                                                           class="w-5 h-5 border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40">

                                                    <label for="{{ $id }}" class="sr-only">
                                                        Award {{ $definition['label'] ?? $key }} to {{ $entrant->displayName() }}
                                                    </label>
                                                @elseif ($definition['type'] === 'toggle')
                                                    {{-- A mark any number of competitors may hold. The
                                                         hidden 0 in front means an unticked box sends a
                                                         decision instead of sending nothing. --}}
                                                    <input type="hidden" name="{{ $name }}" value="0">

                                                    <input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="1"
                                                           @checked((int) $current === 1)
                                                           class="w-5 h-5 rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40">

                                                    <label for="{{ $id }}" class="sr-only">
                                                        {{ $definition['label'] ?? $key }} for {{ $entrant->displayName() }}
                                                    </label>
                                                @elseif ($definition['type'] === 'marks')
                                                    <div class="flex flex-wrap justify-center gap-1.5">
                                                        @for ($judge = 0; $judge < ($definition['count'] ?? 5); $judge++)
                                                            <div>
                                                                <label for="{{ $id }}-{{ $judge }}" class="sr-only">
                                                                    Judge {{ $judge + 1 }} for {{ $entrant->displayName() }}
                                                                </label>
                                                                <input type="number"
                                                                       id="{{ $id }}-{{ $judge }}"
                                                                       name="{{ $name }}[{{ $judge }}]"
                                                                       step="{{ $definition['step'] ?? 0.5 }}"
                                                                       min="{{ $definition['min'] ?? 0 }}"
                                                                       max="{{ $definition['max'] ?? 10 }}"
                                                                       value="{{ old("lines.{$line->tournament_entrant_id}.{$key}.{$judge}", data_get($line->inputs, $key . '.' . $judge)) }}"
                                                                       class="w-14 rounded-lg border border-gray-300 px-1.5 py-1.5 text-sm text-center tabular-nums focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition"
                                                                       data-score-input>
                                                            </div>
                                                        @endfor
                                                    </div>
                                                @elseif ($definition['type'] === 'duration')
                                                    <label for="{{ $id }}" class="sr-only">
                                                        {{ $definition['label'] ?? $key }} for {{ $entrant->displayName() }}
                                                    </label>
                                                    <input type="text" id="{{ $id }}" name="{{ $name }}"
                                                           value="{{ $current }}"
                                                           placeholder="{{ $definition['placeholder'] ?? 'hh:mm:ss' }}"
                                                           class="w-28 rounded-lg border border-gray-300 px-2 py-1.5 text-sm text-center tabular-nums focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition"
                                                           data-score-input>
                                                @else
                                                    <label for="{{ $id }}" class="sr-only">
                                                        {{ $definition['label'] ?? $key }} for {{ $entrant->displayName() }}
                                                    </label>
                                                    <input type="number" id="{{ $id }}" name="{{ $name }}"
                                                           min="{{ $definition['min'] ?? 0 }}"
                                                           @if ($max !== null) max="{{ $max }}" @endif
                                                           value="{{ $current }}"
                                                           class="{{ $cell }}"
                                                           data-score-input>
                                                @endif

                                                @error("lines.{$line->tournament_entrant_id}.{$key}")
                                                    <span class="block text-xs text-red-600 mt-1">{{ $message }}</span>
                                                @enderror
                                            </td>
                                        @endforeach
                                    </tr>

                                    {{-- ===== The player ledger =====
                                         A second, separate set of figures. Nothing typed
                                         below is added to the row above. Hidden until
                                         asked for, because sixteen teams of four would
                                         otherwise be sixty-four rows on one screen. --}}
                                    @if ($showPlayers)
                                        {{-- Came back from a rejected save with figures in it:
                                             sent again, so what was typed is not lost. --}}
                                        <tr id="players-{{ $entrantId }}" hidden data-player-block="{{ $entrantId }}"
                                            @if (old('players.' . $entrantId) !== null) data-opened="1" @endif>
                                            <td colspan="{{ count($inputs) + 1 }}" class="px-4 pb-4 pt-0 bg-gray-50/70">
                                                <div class="rounded-lg border border-gray-200 bg-white overflow-hidden">
                                                    <div class="flex items-center gap-2 px-3 py-2 bg-blue-50/60 border-b border-gray-200">
                                                        <x-admin.icon name="users" class="w-3.5 h-3.5 text-blue-600" />
                                                        <span class="text-xs font-bold uppercase tracking-wide text-gray-600">Personal Score</span>
                                                        <span class="text-xs text-gray-500">optional, counted on its own</span>
                                                    </div>

                                                    <table class="w-full text-sm">
                                                        <thead>
                                                            <tr class="border-b border-gray-100">
                                                                <th scope="col" class="px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-gray-500">Played</th>
                                                                <th scope="col" class="px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-gray-500">Player</th>
                                                                @foreach ($playerInputs as $definition)
                                                                    <th scope="col" class="px-3 py-2 text-center text-xs font-bold uppercase tracking-wide text-gray-500">
                                                                        {{ $definition['label'] ?? $definition['key'] }}
                                                                    </th>
                                                                @endforeach
                                                            </tr>
                                                        </thead>

                                                        <tbody class="divide-y divide-gray-50">
                                                            @foreach ($roster as $person)
                                                                @php
                                                                    $existing = $onFile[$person->id] ?? null;
                                                                    $base = "players.{$entrantId}.{$person->id}";
                                                                    $tookPart = (bool) old($base . '.took_part', $existing?->took_part ?? true);
                                                                @endphp

                                                                <tr class="hover:bg-blue-50/20">
                                                                    <td class="px-3 py-2">
                                                                        <input type="hidden" name="players[{{ $entrantId }}][{{ $person->id }}][took_part]" value="0">
                                                                        <input type="checkbox"
                                                                               id="took-{{ $entrantId }}-{{ $person->id }}"
                                                                               name="players[{{ $entrantId }}][{{ $person->id }}][took_part]"
                                                                               value="1"
                                                                               @checked($tookPart)
                                                                               data-took-part="{{ $entrantId }}"
                                                                               data-ledger="{{ $person->id }}"
                                                                               data-ledger-key="took_part"
                                                                               class="rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                                                                        <label for="took-{{ $entrantId }}-{{ $person->id }}" class="sr-only">
                                                                            {{ $person->full_name }} took part in this match
                                                                        </label>
                                                                    </td>

                                                                    <td class="px-3 py-2">
                                                                        <span class="text-sm text-gray-900">{{ $person->full_name }}</span>

                                                                        {{-- The handle leads, the account id follows in brackets, which is
                                                                             the shape the pick-a-player dropdown already uses. The id is on
                                                                             no game screen: an operator checking what the reader matched, or
                                                                             typing a figure off a result list, has only the nickname to go
                                                                             by. An entry with no handle on it falls back to the id alone. --}}
                                                                        @php $handle = trim((string) $person->ign_name); @endphp

                                                                        @if ($handle !== '')
                                                                            <span class="block text-xs text-gray-400">
                                                                                {{ $handle }}@if ($person->ign_player_id) ({{ $person->ign_player_id }})@endif
                                                                            </span>
                                                                        @elseif ($person->ign_player_id)
                                                                            <span class="block text-xs text-gray-400">IGN {{ $person->ign_player_id }}</span>
                                                                        @endif
                                                                    </td>

                                                                    @foreach ($playerInputs as $definition)
                                                                        @php
                                                                            $pkey = $definition['key'];
                                                                            $pid = "player-{$entrantId}-{$person->id}-{$pkey}";
                                                                        @endphp

                                                                        <td class="px-3 py-2 text-center">
                                                                            <label for="{{ $pid }}" class="sr-only">
                                                                                {{ $definition['label'] ?? $pkey }} for {{ $person->full_name }}
                                                                            </label>
                                                                            <input type="number"
                                                                                   id="{{ $pid }}"
                                                                                   name="players[{{ $entrantId }}][{{ $person->id }}][{{ $pkey }}]"
                                                                                   min="{{ $definition['min'] ?? 0 }}"
                                                                                   value="{{ old($base . '.' . $pkey, $existing?->input($pkey)) }}"
                                                                                   class="w-20 rounded-lg border border-gray-300 px-2 py-1.5 text-sm text-center tabular-nums focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition"
                                                                                   data-score-input
                                                                                   data-player-sum="{{ $entrantId }}-{{ $pkey }}"
                                                                                   data-ledger="{{ $person->id }}"
                                                                                   data-ledger-key="{{ $pkey }}">
                                                                            @error($base . '.' . $pkey)
                                                                                <span class="block text-xs text-red-600 mt-1">{{ $message }}</span>
                                                                            @enderror
                                                                        </td>
                                                                    @endforeach
                                                                </tr>

                                                                @error($base)
                                                                    <tr>
                                                                        <td colspan="{{ count($playerInputs) + 2 }}" class="px-3 pb-2">
                                                                            <span class="text-xs text-red-600">{{ $message }}</span>
                                                                        </td>
                                                                    </tr>
                                                                @enderror
                                                            @endforeach
                                                        </tbody>
                                                    </table>

                                                    {{-- Sums shown beside the team's own figures as information only.
                                                         No warning when they disagree: the two ledgers are independent,
                                                         so a difference is not an error. It is here so the operator can
                                                         spot their own typo. --}}
                                                    <div class="px-3 py-2.5 border-t border-gray-200 bg-gray-50 space-y-1">
                                                        <p class="text-xs text-gray-600">
                                                            <span data-took-count="{{ $entrantId }}">0</span> marked as having played.
                                                            @if ($squadField)
                                                                <button type="button"
                                                                        data-copy-count="{{ $entrantId }}"
                                                                        data-copy-target="line-{{ $entrantId }}-{{ $squadField }}"
                                                                        class="ml-1 underline font-semibold text-blue-600 hover:text-blue-700">
                                                                    Use this for {{ collect($inputs)->firstWhere('key', $squadField)['label'] ?? $squadField }}
                                                                </button>
                                                            @endif
                                                        </p>

                                                        <p class="text-xs text-gray-500">
                                                            @foreach ($playerInputs as $definition)
                                                                @php $pkey = $definition['key']; @endphp
                                                                <span class="mr-3">
                                                                    {{ $definition['label'] ?? $pkey }} total
                                                                    <span class="font-semibold tabular-nums text-gray-700" data-sum-output="{{ $entrantId }}-{{ $pkey }}">0</span>
                                                                    @if (collect($inputs)->firstWhere('key', $pkey))
                                                                        · team {{ $definition['label'] ?? $pkey }}
                                                                        <span class="font-semibold tabular-nums text-gray-700">{{ $line->input($pkey) ?? '—' }}</span>
                                                                    @endif
                                                                </span>
                                                            @endforeach
                                                        </p>

                                                        <p class="text-xs text-gray-400">
                                                            Personal points are counted on their own leaderboard and never added
                                                            to the team's total.
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>

                            {{-- The way out of a radio group.
                                 A radio cannot be cleared once set, and a fixture that was
                                 abandoned or settled on penalty has nobody to award the mark
                                 to. This row is that answer, sitting directly under the
                                 column it belongs to, and it is the one selected when nothing
                                 is on file so the state is never merely absent. --}}
                            @if ($singleInputs->isNotEmpty())
                                <tfoot>
                                    <tr class="border-t-2 border-gray-200 bg-gray-50">
                                        <th scope="row" class="px-4 py-3 text-left text-xs font-semibold text-gray-500">
                                            Nobody
                                        </th>

                                        @foreach ($inputs as $definition)
                                            @php $fkey = $definition['key']; @endphp

                                            <td class="px-4 py-3 text-center">
                                                @if ($singleInputs->has($fkey))
                                                    <input type="radio"
                                                           id="single-none-{{ $fkey }}"
                                                           name="single[{{ $fkey }}]"
                                                           value=""
                                                           @checked((int) old('single.' . $fkey, $singleHeldBy[$fkey] ?? 0) === 0)
                                                           class="w-5 h-5 border-gray-300 text-gray-500 focus:ring-2 focus:ring-blue-500/40">

                                                    <label for="single-none-{{ $fkey }}" class="sr-only">
                                                        Award {{ $definition['label'] ?? $fkey }} to nobody
                                                    </label>
                                                @endif

                                                @error('single.' . $fkey)
                                                    <span class="block text-xs text-red-600 mt-1">{{ $message }}</span>
                                                @enderror
                                            </td>
                                        @endforeach
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>

                    <div class="px-4 py-3 border-t border-gray-200 bg-gray-50">
                        <p class="text-xs text-gray-600">
                            Points are worked out from these numbers and cannot be typed. Tab moves
                            down the list in reading order.
                        </p>

                        @if ($singleInputs->isNotEmpty())
                            <p class="text-xs text-gray-600 mt-1">
                                {{ $singleInputs->pluck('label')->implode(', ') }}
                                {{ $singleInputs->count() === 1 ? 'goes' : 'go' }} to one competitor at
                                most. Pick Nobody where there is none to award.
                            </p>
                        @endif
                    </div>
                </x-admin.panel>

                <x-admin.panel title="Proof" icon="shield">
                    <div class="px-5 py-4">
                        <p class="text-sm text-gray-600 mb-3">
                            A screenshot of the result screen.
                            {{ $requiresProof
                                ? 'Required for this tournament before the fixture can be closed.'
                                : 'Optional, but it is the only evidence if a score is disputed later.' }}
                        </p>

                        <label for="proof" class="sr-only">Result screenshot</label>
                        <input type="file" id="proof" name="proof" accept="image/*"
                               class="text-sm text-gray-700 file:mr-3 file:rounded-lg file:border file:border-gray-300 file:bg-white file:px-4 file:py-2 file:text-sm file:font-semibold file:text-gray-700 hover:file:bg-gray-50">
                        @error('proof')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror

                        @if ($match->proofs->isNotEmpty())
                            <ul class="mt-3 space-y-1">
                                @foreach ($match->proofs as $proof)
                                    <li class="text-xs text-gray-600">
                                        <a href="{{ $proof->url() }}" target="_blank" rel="noopener"
                                           class="underline font-semibold text-blue-600">
                                            {{ $proof->original_name ?: 'Screenshot' }}
                                        </a>
                                        uploaded {{ \App\Support\LocalTime::format($proof->created_at, 'd M, g:i a') }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </x-admin.panel>

                <div class="flex flex-wrap items-center justify-between gap-4 bg-white rounded-lg border border-gray-200 px-5 py-4 mt-5">
                    <p class="text-xs text-gray-500 max-w-md">
                        Saving works the standings out again straight away. Nothing waits on a
                        background worker.
                        @if ($picksPlayers && $hasAwards)
                            You will be asked for the top {{ $playerSlots }} players and Star of the Match first.
                        @elseif ($picksPlayers)
                            You will be asked for the top {{ $playerSlots }} players first.
                        @elseif ($hasAwards)
                            You will be asked for Star of the Match first.
                        @endif
                    </p>

                    {{-- A real submit button, always. In slot mode the script below
                         intercepts it to show the dialog first, so a browser that never
                         runs the script still saves the team result rather than leaving
                         the operator with a button that does nothing. --}}
                    <button type="submit" @disabled(! $canScore)
                            @if ($opensDialog) data-open-slots @endif
                            class="rounded-lg border border-blue-600 bg-blue-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm disabled:opacity-50 disabled:cursor-not-allowed shrink-0">
                        {{ $match->isSettled() ? 'Correct Result' : 'Save Result' }}
                    </button>
                </div>

                {{-- ===== Name the top players =====
                     Inside the form on purpose: these inputs are part of the same save,
                     so there is one request and one transaction. A separate form would
                     mean a team result that is saved and personal figures that might not
                     be. --}}
                @if ($opensDialog)
                    @php
                        // A row that came back from a rejected save wins over what is on
                        // file, so nothing anybody typed is lost to a validation error.
                        $existingSlots = $picksPlayers
                            ? old('slots', collect($slotRows)
                                ->map(fn (array $row) => ['participant' => $row['participant']] + $row['inputs'])
                                ->all())
                            : [];
                    @endphp

                    <div id="slot-dialog"
                         class="fixed inset-0 z-50 hidden items-center justify-center bg-gray-900/60 p-4"
                         role="dialog" aria-modal="true" aria-labelledby="slot-dialog-title">

                        <div class="w-full max-w-3xl rounded-xl bg-white shadow-xl overflow-hidden max-h-full flex flex-col">
                            <div class="flex items-start justify-between gap-4 px-5 py-4 border-b border-gray-200">
                                <div>
                                    <h2 id="slot-dialog-title" class="text-base font-bold text-gray-900">
                                        {{ $picksPlayers ? 'Top ' . $playerSlots . ' players' : 'Star of the Match' }}
                                    </h2>
                                    <p class="text-sm text-gray-500 mt-0.5">
                                        @if ($picksPlayers)
                                            {{ $match->label() }} &middot; name only the players worth recording.
                                            Leave a row blank to skip it.
                                        @else
                                            {{ $match->label() }} &middot; one player per award, as the game showed it.
                                        @endif
                                    </p>
                                </div>

                                <button type="button" data-close-slots
                                        class="shrink-0 rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition"
                                        aria-label="Close">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            @error('slots')
                                <p class="px-5 py-2.5 bg-red-50 border-b border-red-200 text-sm text-red-800">{{ $message }}</p>
                            @enderror

                            <div class="overflow-y-auto">
                                @if ($picksPlayers)
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-50 sticky top-0">
                                        <tr>
                                            <th scope="col" class="{{ $head }} w-10">#</th>
                                            <th scope="col" class="{{ $head }}">Player</th>
                                            @foreach ($playerInputs as $definition)
                                                <th scope="col" class="{{ $head }} text-center">
                                                    {{ $definition['label'] ?? $definition['key'] }}
                                                </th>
                                            @endforeach
                                        </tr>
                                    </thead>

                                    <tbody class="divide-y divide-gray-100">
                                        @for ($slot = 0; $slot < $playerSlots; $slot++)
                                            @php $row = $existingSlots[$slot] ?? []; @endphp

                                            <tr class="hover:bg-blue-50/30">
                                                <td class="px-4 py-2.5 text-xs font-bold text-gray-400 tabular-nums">
                                                    {{ $slot + 1 }}
                                                </td>

                                                <td class="px-4 py-2.5">
                                                    <label for="slot-{{ $slot }}" class="sr-only">Player for row {{ $slot + 1 }}</label>
                                                    <select id="slot-{{ $slot }}" name="slots[{{ $slot }}][participant]"
                                                            class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition"
                                                            data-slot-player>
                                                        <option value="">Nobody</option>
                                                        @foreach ($pickable as $group)
                                                            <optgroup label="{{ $group['name'] }}">
                                                                @foreach ($group['players'] as $personId => $label)
                                                                    <option value="{{ $personId }}"
                                                                        @selected((int) ($row['participant'] ?? 0) === (int) $personId)>
                                                                        {{ $label }}
                                                                    </option>
                                                                @endforeach
                                                            </optgroup>
                                                        @endforeach
                                                    </select>

                                                    @error("slots.{$slot}.participant")
                                                        <span class="block text-xs text-red-600 mt-1">{{ $message }}</span>
                                                    @enderror
                                                </td>

                                                @foreach ($playerInputs as $definition)
                                                    @php $pkey = $definition['key']; @endphp

                                                    <td class="px-3 py-2.5 text-center">
                                                        <label for="slot-{{ $slot }}-{{ $pkey }}" class="sr-only">
                                                            {{ $definition['label'] ?? $pkey }} for row {{ $slot + 1 }}
                                                        </label>
                                                        <input type="number"
                                                               id="slot-{{ $slot }}-{{ $pkey }}"
                                                               name="slots[{{ $slot }}][{{ $pkey }}]"
                                                               min="{{ $definition['min'] ?? 0 }}"
                                                               step="{{ $definition['step'] ?? 1 }}"
                                                               value="{{ $row[$pkey] ?? '' }}"
                                                               class="w-24 rounded-lg border border-gray-300 px-2 py-1.5 text-sm text-center tabular-nums focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">

                                                        @error("slots.{$slot}.{$pkey}")
                                                            <span class="block text-xs text-red-600 mt-1">{{ $message }}</span>
                                                        @enderror
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @endfor
                                    </tbody>
                                </table>
                                @endif

                                {{-- ===== Star of the Match =====
                                     One block per award the profile hands out. The player is
                                     picked from everybody in this fixture, and only that
                                     award's own figures are asked for, because those are the
                                     figures its card on the website carries. --}}
                                @if ($hasAwards)
                                    <div @class(['px-5 py-4', 'border-t border-gray-200' => $picksPlayers])>
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <p class="text-xs font-bold uppercase tracking-wide text-gray-500">Star of the Match</p>
                                                <p class="text-xs text-gray-500 mt-0.5">
                                                    Copied from the game's result screen. Picking a player fills in what is already
                                                    recorded for them in this match, and what is saved here counts on the player
                                                    leaderboard too. Leave an award to nobody if it was not given.
                                                </p>
                                            </div>

                                            {{-- Beside the cards it fills in, and a plain button: it opens the
                                                 picker outside this form, and the awards are still saved by the
                                                 Save button below. --}}
                                            @if ($readsAwardSheet)
                                                <div class="flex shrink-0 items-center">
                                                    <span data-award-sheet-status role="status" aria-live="polite"
                                                          class="hidden text-xs mr-2"></span>

                                                    <button type="button" data-award-sheet-pick
                                                            title="Read the award cards off the game's Star of the Match screen"
                                                            class="inline-flex items-center gap-1.5 rounded-lg border border-blue-600 bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700 transition shadow-sm disabled:opacity-50 disabled:cursor-not-allowed">
                                                        <x-admin.icon name="clipboard" class="w-3.5 h-3.5" />
                                                        Detect Image AI
                                                    </button>
                                                </div>
                                            @endif
                                        </div>

                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                                            @foreach ($matchAwards as $award)
                                                @php
                                                    $akey = $award['key'];
                                                    $saved = $awardRows->get($akey);
                                                    $chosen = (int) old("awards.{$akey}.participant", $saved?->event_participant_id ?? 0);
                                                    $headlineLabel = collect($award['fields'])->firstWhere('key', $award['headline'])['label'] ?? $award['headline'];
                                                @endphp

                                                <div class="rounded-lg border border-gray-200 bg-gray-50/60 p-3.5">
                                                    <div class="flex items-center justify-between gap-2">
                                                        <p class="text-sm font-bold text-gray-900">{{ $award['label'] }}</p>
                                                        <span class="text-xs text-gray-400">Headline: {{ $headlineLabel }}</span>
                                                    </div>

                                                    <label for="award-{{ $akey }}" class="sr-only">Player who took {{ $award['label'] }}</label>
                                                    <select id="award-{{ $akey }}" name="awards[{{ $akey }}][participant]"
                                                            data-award-player="{{ $akey }}"
                                                            class="mt-2 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                                                        <option value="">Not awarded</option>
                                                        @foreach ($pickable as $groupEntrant => $group)
                                                            <optgroup label="{{ $group['name'] }}">
                                                                @foreach ($group['players'] as $personId => $label)
                                                                    <option value="{{ $personId }}" data-entrant="{{ $groupEntrant }}" @selected($chosen === (int) $personId)>{{ $label }}</option>
                                                                @endforeach
                                                            </optgroup>
                                                        @endforeach
                                                    </select>

                                                    @error("awards.{$akey}.participant")
                                                        <span class="block text-xs text-red-600 mt-1">{{ $message }}</span>
                                                    @enderror

                                                    <div class="grid grid-cols-2 gap-2 mt-2.5">
                                                        @foreach ($award['fields'] as $field)
                                                            @php
                                                                $fkey = $field['key'];
                                                                $isHeadline = $fkey === $award['headline'];
                                                            @endphp

                                                            <div>
                                                                <label for="award-{{ $akey }}-{{ $fkey }}"
                                                                       @class(['block text-xs font-semibold mb-1', 'text-blue-700' => $isHeadline, 'text-gray-600' => ! $isHeadline])>
                                                                    {{ $field['label'] }}
                                                                    @if ($isHeadline)
                                                                        <span class="text-red-500" aria-hidden="true">*</span>
                                                                    @endif
                                                                </label>
                                                                {{-- A saved figure belongs to whoever holds the award now,
                                                                     so it is marked as filled in: picking somebody else
                                                                     clears it rather than handing them the old numbers. --}}
                                                                <input type="number"
                                                                       id="award-{{ $akey }}-{{ $fkey }}"
                                                                       name="awards[{{ $akey }}][{{ $fkey }}]"
                                                                       min="0"
                                                                       step="{{ ($field['decimal'] ?? false) ? '0.01' : '1' }}"
                                                                       value="{{ old("awards.{$akey}.{$fkey}", data_get($saved?->figures, $fkey)) }}"
                                                                       data-award-field="{{ $akey }}"
                                                                       data-personal-key="{{ $awardFieldMap[$akey][$fkey] ?? '' }}"
                                                                       @if (! session()->hasOldInput('awards') && data_get($saved?->figures, $fkey) !== null) data-autofilled="1" @endif
                                                                       class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm text-center tabular-nums bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">

                                                                @error("awards.{$akey}.{$fkey}")
                                                                    <span class="block text-xs text-red-600 mt-1">{{ $message }}</span>
                                                                @enderror
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>

                            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-t border-gray-200 bg-gray-50">
                                <p class="text-xs text-gray-500 max-w-sm">
                                    Personal figures are counted on their own and never added to a team's
                                    total.
                                </p>

                                <div class="flex flex-wrap gap-2.5">
                                    <button type="button" data-close-slots
                                            class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                                        Back
                                    </button>
                                    <button type="submit"
                                            class="rounded-lg border border-blue-600 bg-blue-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm">
                                        {{ $match->isSettled() ? 'Correct Result' : 'Save Result' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- ===== The player ledger, as one field =====
                     Seventeen squads of five with nine figures each is over a
                     thousand inputs, and a thousand is where PHP stops reading a
                     request and DROPS THE REST WITHOUT SAYING SO. The script below
                     folds the whole ledger into this one field at the moment of
                     sending and stops the individual inputs from posting, so a full
                     lobby costs one input variable instead of hundreds.

                     Empty until then, and that is the point: a browser that never
                     runs the script posts the individual fields and the server reads
                     them exactly as it always has. --}}
                @if ($tracksPlayers)
                    <input type="hidden" name="players_json" id="players-json" value="">
                @endif

                <input type="hidden" name="_form_complete" value="1">
            </form>

            {{-- ===== Read the Star of the Match screen =====
                 Three cards of four figures each, copied off a screen in the other
                 window. Same promise as the results table reader above: it fills the
                 award cards in and saves nothing.

                 BELOW THE FORM, and that position is doing two jobs. Outside it, so a
                 file chosen here is never posted with the result — the same reason the
                 other picker sits outside. And after it in the document, so this
                 review panel paints over the award dialog it is filling in: both are
                 fixed at z-50, and the later of two positioned elements at the same
                 level wins. Moving this block above the form would put the panel
                 behind the dialog. --}}
            @if ($readsAwardSheet)
                <x-admin.score-review
                    prefix="award-sheet"
                    id="award-sheet-dialog"
                    :url="route('admin.tournaments.matches.score.read-awards', $match)"
                    picker="award-sheet-images"
                    pick="Star of the Match screenshots"
                    :about="$match->label() . ' · check every card against the image, then Apply. Untick anything you would rather type yourself.'"
                    :note="'Apply only types these figures into the award cards. Nothing is saved until you press ' . ($match->isSettled() ? 'Correct Result' : 'Save Result') . '.'">

                    <div data-award-sheet-rows class="space-y-2"></div>
                    <div data-award-sheet-unknown class="hidden mt-5"></div>
                    <div data-award-sheet-uncovered class="hidden mt-5"></div>
                </x-admin.score-review>
            @endif

            {{-- ============ A squad out of a lobby ============
                 A lobby has no other side to hand a walkover to, so this is about one
                 squad. It changes that squad only; the fixture and everybody else's
                 result stand, and a later Correct does not undo it. --}}
            @if ($canScore && $match->round === null)
                @php
                    $outSquads = $lines->filter(fn ($line) => in_array($line->entrant?->status, [
                        \App\Models\TournamentEntrant::STATUS_WITHDRAWN,
                        \App\Models\TournamentEntrant::STATUS_DISQUALIFIED,
                    ], true));
                @endphp

                <div class="mt-5">
                    <x-admin.panel title="Withdrawn or Disqualified" icon="lock">
                        <div class="px-5 py-4">
                            <p class="text-sm text-gray-600 mb-4">
                                For a squad that pulled out of the tournament or broke the rules. It takes that
                                squad out of the rest of the tournament and off the qualifying places; every other
                                squad's result in this match stands. A squad that only missed this match is entered
                                in the result above with <span class="font-semibold">Players 0</span>, which scores
                                them nothing for this match and keeps them in the tournament.
                            </p>

                            @if ($outSquads->isNotEmpty())
                                <ul class="mb-5 divide-y divide-gray-100 rounded-lg border border-gray-200">
                                    @foreach ($outSquads as $line)
                                        <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5">
                                            <span class="text-sm">
                                                <span class="font-semibold text-gray-900">{{ $line->entrant->displayName() }}</span>
                                                <span class="ml-1.5 rounded bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-800">
                                                    {{ \App\Models\TournamentEntrant::STATUSES[$line->entrant->status] ?? $line->entrant->status }}
                                                </span>
                                                @if ($line->entrant->reason)
                                                    <span class="block text-xs text-gray-500 mt-0.5">{{ $line->entrant->reason }}</span>
                                                @endif
                                            </span>

                                            <form action="{{ route('admin.tournaments.matches.resolve', $match) }}" method="POST"
                                                  onsubmit="return confirm('Put {{ addslashes($line->entrant->displayName()) }} back in the tournament?');">
                                                @csrf
                                                <input type="hidden" name="squad_action" value="reinstate">
                                                <input type="hidden" name="entrant_id" value="{{ $line->tournament_entrant_id }}">
                                                <button type="submit"
                                                        class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 transition">
                                                    Reinstate
                                                </button>
                                            </form>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            <form action="{{ route('admin.tournaments.matches.resolve', $match) }}" method="POST"
                                  onsubmit="return confirm('Take this squad out of the tournament and rebuild the standings?');"
                                  class="space-y-4">
                                @csrf

                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div>
                                        <label for="entrant_id" class="block text-xs font-semibold text-gray-700 mb-1">
                                            Squad <span class="text-red-500" aria-hidden="true">*</span>
                                        </label>
                                        <select id="entrant_id" name="entrant_id" required
                                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                                            <option value="">Pick a squad</option>
                                            @foreach ($lines as $line)
                                                @if ($line->entrant && ! $outSquads->contains('id', $line->id))
                                                    <option value="{{ $line->tournament_entrant_id }}" @selected((int) old('entrant_id') === (int) $line->tournament_entrant_id)>
                                                        {{ $line->entrant->displayName() }}
                                                    </option>
                                                @endif
                                            @endforeach
                                        </select>
                                        @error('entrant_id')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                                    </div>

                                    <div>
                                        <label for="squad_action" class="block text-xs font-semibold text-gray-700 mb-1">What happened</label>
                                        <select id="squad_action" name="squad_action" required
                                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                                            <option value="withdrawal" @selected(old('squad_action') === 'withdrawal')>Withdrawal — pulled out of the tournament</option>
                                            <option value="disqualification" @selected(old('squad_action') === 'disqualification')>Disqualification — broke the rules</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label for="squad_reason" class="block text-xs font-semibold text-gray-700 mb-1">
                                            Reason <span class="text-red-500" aria-hidden="true">*</span>
                                        </label>
                                        <input type="text" id="squad_reason" name="reason" required maxlength="255"
                                               value="{{ old('reason') }}"
                                               placeholder="e.g. Did not turn up for M1 to M3, no reason given"
                                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                                        @error('reason')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                                    </div>
                                </div>

                                <button type="submit"
                                        class="rounded-lg border border-amber-300 bg-white px-5 py-2.5 text-sm font-semibold text-amber-700 hover:bg-amber-50 transition">
                                    Record It
                                </button>
                            </form>
                        </div>
                    </x-admin.panel>
                </div>
            @endif

            {{-- ============ Walkover, forfeit, DQ ============ --}}
            @if ($canScore && $match->round !== null)
                <div class="mt-5">
                    <x-admin.panel title="Nobody Played" icon="lock">
                        <div class="px-5 py-4">
                            <p class="text-sm text-gray-600 mb-4">
                                For a team that did not turn up, turned up too late, or broke the rules.
                                A walkover settles this fixture only; a disqualification or a withdrawal
                                also takes the competitor out of the rest of the tournament.
                            </p>

                            <form action="{{ route('admin.tournaments.matches.resolve', $match) }}" method="POST"
                                  onsubmit="return confirm('Record this and rebuild the standings?');"
                                  class="space-y-4">
                                @csrf

                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div>
                                        <label for="resolution" class="block text-xs font-semibold text-gray-700 mb-1">What happened</label>
                                        <select id="resolution" name="resolution" required
                                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                                            <option value="walkover">Walkover — the other side did not appear</option>
                                            <option value="forfeit">Forfeit — too late, past the allowance</option>
                                            <option value="disqualification">Disqualification — broke the rules</option>
                                            <option value="withdrawal">Withdrawal — pulled out</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label for="winner_entrant_id" class="block text-xs font-semibold text-gray-700 mb-1">Who goes through</label>
                                        <select id="winner_entrant_id" name="winner_entrant_id"
                                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                                            <option value="">Nobody</option>
                                            @foreach ($lines as $line)
                                                @if ($line->entrant)
                                                    <option value="{{ $line->tournament_entrant_id }}">{{ $line->entrant->displayName() }}</option>
                                                @endif
                                            @endforeach
                                        </select>
                                    </div>

                                    <div>
                                        <label for="reason" class="block text-xs font-semibold text-gray-700 mb-1">
                                            Reason <span class="text-red-500" aria-hidden="true">*</span>
                                        </label>
                                        <input type="text" id="reason" name="reason" required maxlength="255"
                                               placeholder="e.g. 15 minutes late, past the 10 minute allowance"
                                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                                    </div>
                                </div>

                                <button type="submit"
                                        class="rounded-lg border border-amber-300 bg-white px-5 py-2.5 text-sm font-semibold text-amber-700 hover:bg-amber-50 transition">
                                    Record It
                                </button>
                            </form>
                        </div>
                    </x-admin.panel>
                </div>
            @endif
        @endif
    </x-admin.page-card>
@endsection

@push('scripts')
<script>
    (function () {
        /*
         | Enter moves to the next field rather than submitting.
         |
         | This form is used at speed at a desk with a results screen in the other hand,
         | and submitting halfway through a lobby of sixteen would be worse than useless.
         */
        const inputs = Array.from(document.querySelectorAll('[data-score-input]'));

        inputs.forEach(function (input, index) {
            input.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter') {
                    return;
                }

                event.preventDefault();
                (inputs[index + 1] || inputs[0]).focus();
            });
        });

        /*
         | The player ledger: show and hide, keep the running sums, and copy the head
         | count on request.
         |
         | Nothing here writes to a team field on its own. The copy button types a
         | number into one field when pressed, and the operator can change it after,
         | which is the whole reason it is a button and not an automatic total.
         */
        document.querySelectorAll('[data-player-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                const block = document.getElementById(button.dataset.playerToggle);

                if (!block) {
                    return;
                }

                const open = block.hasAttribute('hidden');

                open ? block.removeAttribute('hidden') : block.setAttribute('hidden', '');

                // Opened once is enough to be sent: closing it again after typing
                // must not throw the figures away.
                if (open) {
                    block.dataset.opened = '1';
                }
                button.setAttribute('aria-expanded', open ? 'true' : 'false');

                const chevron = button.querySelector('[data-chevron]');

                if (chevron) {
                    chevron.style.transform = open ? 'rotate(90deg)' : '';
                }
            });
        });

        function refresh(entrantId) {
            const block = document.querySelector('[data-player-block="' + entrantId + '"]');

            if (!block) {
                return;
            }

            const rows = Array.from(block.querySelectorAll('[data-took-part="' + entrantId + '"]'));
            const played = rows.filter(function (box) { return box.checked; }).length;
            const counter = document.querySelector('[data-took-count="' + entrantId + '"]');

            if (counter) {
                counter.textContent = String(played);
            }

            const sums = {};

            block.querySelectorAll('[data-player-sum]').forEach(function (field) {
                const key = field.dataset.playerSum;
                const row = field.closest('tr');
                const box = row ? row.querySelector('[data-took-part]') : null;

                // Only players marked as having played count towards the sum, so
                // leftover figures on an unticked row do not inflate it.
                if (box && !box.checked) {
                    return;
                }

                sums[key] = (sums[key] || 0) + (parseInt(field.value, 10) || 0);
            });

            block.querySelectorAll('[data-sum-output]').forEach(function (output) {
                output.textContent = String(sums[output.dataset.sumOutput] || 0);
            });
        }

        document.querySelectorAll('[data-took-part]').forEach(function (box) {
            box.addEventListener('change', function () { refresh(box.dataset.tookPart); });
        });

        document.querySelectorAll('[data-player-sum]').forEach(function (field) {
            field.addEventListener('input', function () {
                refresh(field.dataset.playerSum.split('-')[0]);
            });
        });

        document.querySelectorAll('[data-copy-count]').forEach(function (button) {
            button.addEventListener('click', function () {
                const block = document.querySelector('[data-player-block="' + button.dataset.copyCount + '"]');
                const target = document.getElementById(button.dataset.copyTarget);

                if (!block || !target) {
                    return;
                }

                const played = Array.from(block.querySelectorAll('[data-took-part]'))
                    .filter(function (box) { return box.checked; }).length;

                target.value = String(played);
                target.focus();
            });
        });

        document.querySelectorAll('[data-player-block]').forEach(function (block) {
            refresh(block.dataset.playerBlock);
        });

        /*
         | The player ledger, folded into one field at the moment of sending.
         |
         | THE CEILING THIS IS ABOUT. Seventeen squads of five with nine figures each,
         | plus a played box per person, is well over a thousand inputs. A thousand is
         | PHP's default max_input_vars, and past it the request is TRUNCATED WITHOUT
         | A WORD: the last squads simply never arrived, and the result saved looked
         | complete. Closing the panels nobody touched used to keep the form under it,
         | which worked while panels were opened one at a time — and stopped working
         | the day the screenshot reader began filling every one of them in.
         |
         | So the whole ledger goes as a single JSON field and the individual inputs
         | are stopped from posting. A full lobby now costs one input variable. It is
         | not a server setting, because the server is not ours to set.
         |
         | THREE THINGS THIS KEEPS. The values stay in the panels, visible and
         | editable: only the wire format changes. A panel nobody opened is still left
         | out, exactly as before, so the server keeps what is on file for a player it
         | was not told about. And the field is serialised BEFORE anything is disabled,
         | so if this ever threw half way the individual fields would still post and be
         | read the old way rather than nothing arriving at all.
         */
        const scoreForm = document.getElementById('score-form');
        const ledger = document.getElementById('players-json');

        if (scoreForm && ledger) {
            const skipUnopened = scoreForm.hasAttribute('data-skip-unopened');

            scoreForm.addEventListener('submit', function () {
                const payload = {};

                scoreForm.querySelectorAll('[data-player-block]').forEach(function (block) {
                    if (skipUnopened && block.dataset.opened !== '1') {
                        return;
                    }

                    const entrant = block.dataset.playerBlock;

                    block.querySelectorAll('[data-ledger]').forEach(function (field) {
                        const person = field.dataset.ledger;

                        payload[entrant] = payload[entrant] || {};
                        payload[entrant][person] = payload[entrant][person] || {};

                        // The played box is a decision, so it travels as one either
                        // way. The hidden 0 that pairs with it on the form exists to
                        // make an unticked box send something; here there is nothing
                        // to pair, because the box's own state is what is written.
                        payload[entrant][person][field.dataset.ledgerKey] = field.type === 'checkbox'
                            ? (field.checked ? 1 : 0)
                            : field.value;
                    });
                });

                ledger.value = JSON.stringify(payload);

                // And now the hundreds of fields it replaces, including the hidden 0
                // in front of every played box.
                scoreForm.querySelectorAll('[data-player-block] [name^="players["]').forEach(function (field) {
                    field.disabled = true;
                });
            });
        }

        /*
         | Star of the Match: picking a player fills the award in.
         |
         | The figures are read from what is already recorded for that player in this
         | match, either on their squad's roster panel or in the top players rows, so
         | an operator who entered them there does not type them again. A figure the
         | operator typed on the award is never overwritten; one that was filled in is
         | cleared again when a different player is picked.
         */
        function recordedFigure(entrant, person, key) {
            const roster = document.querySelector('[name="players[' + entrant + '][' + person + '][' + key + ']"]');

            if (roster && roster.value !== '') {
                return roster.value;
            }

            const slots = document.querySelectorAll('[data-slot-player]');

            for (let i = 0; i < slots.length; i++) {
                if (slots[i].value !== person) {
                    continue;
                }

                const input = document.querySelector('[name="' + slots[i].name.replace('[participant]', '[' + key + ']') + '"]');

                if (input && input.value !== '') {
                    return input.value;
                }
            }

            return null;
        }

        document.querySelectorAll('[data-award-player]').forEach(function (select) {
            const fields = Array.from(document.querySelectorAll('[data-award-field="' + select.dataset.awardPlayer + '"]'));

            fields.forEach(function (field) {
                field.addEventListener('input', function () {
                    delete field.dataset.autofilled;
                });
            });

            select.addEventListener('change', function () {
                const option = select.options[select.selectedIndex];
                const person = select.value;
                const entrant = option ? (option.dataset.entrant || '') : '';

                fields.forEach(function (field) {
                    if (field.dataset.autofilled) {
                        field.value = '';
                        delete field.dataset.autofilled;
                    }

                    if (person === '' || !field.dataset.personalKey || field.value !== '') {
                        return;
                    }

                    const value = recordedFigure(entrant, person, field.dataset.personalKey);

                    if (value !== null) {
                        field.value = value;
                        field.dataset.autofilled = '1';
                    }
                });
            });
        });

        /*
         | Nothing here for the chicken dinner.
         |
         | It is a radio group sharing one name, so the browser enforces the single
         | choice and a Nobody option is the way to withhold it. There was a script that
         | unticked sibling checkboxes; it is gone, because the rule now lives in the
         | control rather than in code that has to run for the form to behave.
         */

        /*
         | Naming the top players.
         |
         | The dialog stands between pressing Save and the form going, so the team result
         | is filled in first and the personal figures second, in one request. The button
         | is a real submit button: if this script never runs, pressing it saves the team
         | result and nothing is broken.
         */
        const slotDialog = document.getElementById('slot-dialog');

        if (slotDialog) {
            const form = document.getElementById('score-form');
            let confirmed = false;

            function openSlots() {
                slotDialog.classList.remove('hidden');
                slotDialog.classList.add('flex');
                slotDialog.querySelector('select')?.focus();
            }

            function closeSlots() {
                slotDialog.classList.add('hidden');
                slotDialog.classList.remove('flex');
            }

            document.querySelectorAll('[data-open-slots]').forEach(function (button) {
                button.addEventListener('click', function (event) {
                    // Let the browser check the team fields first. Opening the dialog
                    // over a form that will be rejected anyway wastes the operator's
                    // time filling it in.
                    if (form && !form.checkValidity()) {
                        return;
                    }

                    if (confirmed) {
                        return;
                    }

                    event.preventDefault();
                    openSlots();
                });
            });

            // Pressing Save inside the dialog is the real submission.
            slotDialog.querySelector('[type="submit"]')?.addEventListener('click', function () {
                confirmed = true;
            });

            document.querySelectorAll('[data-close-slots]').forEach(function (button) {
                button.addEventListener('click', closeSlots);
            });

            slotDialog.addEventListener('click', function (event) {
                if (event.target === slotDialog) {
                    closeSlots();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !slotDialog.classList.contains('hidden')) {
                    closeSlots();
                }
            });

            /*
             | Stop the same player being chosen in two rows.
             |
             | The server refuses it too, and says which row, but finding out after a save
             | is a poor way to learn it. Anybody already chosen elsewhere is disabled in
             | the other dropdowns rather than removed, so the list does not reshuffle
             | while somebody is reading it.
             */
            const pickers = Array.from(slotDialog.querySelectorAll('[data-slot-player]'));

            function syncPickers() {
                const taken = pickers.map(function (select) { return select.value; })
                    .filter(function (value) { return value !== ''; });

                pickers.forEach(function (select) {
                    Array.from(select.options).forEach(function (option) {
                        if (option.value === '' || option.value === select.value) {
                            option.disabled = false;

                            return;
                        }

                        option.disabled = taken.indexOf(option.value) !== -1;
                    });
                });
            }

            pickers.forEach(function (select) {
                select.addEventListener('change', syncPickers);
            });

            syncPickers();

            // A rejected save comes back with the rows filled in, so the dialog is
            // reopened rather than hiding what the operator has to correct.
            @if ($errors->has('slots') || collect($errors->keys())->contains(fn ($key) => str_starts_with($key, 'slots.') || str_starts_with($key, 'awards.')))
                openSlots();
            @endif
        }
    })();
</script>
@endpush

{{-- ===== What the three readers share =====
     Shipped once where any of them is on. Three screens, three questions, one
     promise and one set of furniture: a status line, a panel that shows what was
     read against what is on the form, a way out of it, and the upload itself.

     Written here rather than in the bundle on purpose: these helpers are about this
     screen and nothing else, and a page whose dialogs depend on a built asset is a
     page that breaks differently. Kept to what all three genuinely do the same way —
     what each one renders and what Apply means is its own business. --}}
@if ($readsAnything)
@push('scripts')
<script>
    window.scoreReview = (function () {
        /*
         | Every line of every one of these panels is built with textContent.
         |
         | Those names and figures came out of a model reading somebody's photograph.
         | They are data, and they are not ours to hand to a browser as markup.
         */
        function el(tag, className, text) {
            const node = document.createElement(tag);

            node.className = className || '';

            if (text !== undefined && text !== null) {
                node.textContent = String(text);
            }

            return node;
        }

        // "Kills 4 -> 11": what is on the form now, and what Apply would put there.
        function change(label, from, to) {
            const line = el('span', 'block text-xs text-gray-600 mt-0.5');

            line.appendChild(el('span', 'font-semibold text-gray-500', label + ' '));
            line.appendChild(el('span', 'text-gray-500', from));
            line.appendChild(el('span', 'mx-1 text-gray-400', '\u2192'));
            line.appendChild(el('span', 'font-bold text-gray-900 tabular-nums', to));

            return line;
        }

        // A list of things the reader could not place, or hid nothing from. An empty
        // one hides itself: a heading with nothing under it reads as a fault.
        function block(boxNode, title, tone, lines) {
            boxNode.textContent = '';

            if (lines.length === 0) {
                boxNode.className = 'hidden mt-5';

                return;
            }

            boxNode.className = 'mt-5 rounded-lg border p-3 '
                + (tone === 'amber' ? 'border-amber-200 bg-amber-50' : 'border-gray-200 bg-gray-50');

            boxNode.appendChild(el(
                'p',
                'text-xs font-bold uppercase tracking-wide mb-1.5 '
                    + (tone === 'amber' ? 'text-amber-800' : 'text-gray-600'),
                title,
            ));

            const list = el('ul', 'space-y-1');

            lines.forEach(function (text) {
                list.appendChild(el(
                    'li',
                    'text-xs ' + (tone === 'amber' ? 'text-amber-800' : 'text-gray-600'),
                    text,
                ));
            });

            boxNode.appendChild(list);
        }

        // The status line beside a Detect Image AI button.
        function status(node) {
            return function say(message, tone) {
                if (!node) {
                    return;
                }

                if (!message) {
                    node.className = 'hidden text-xs mr-2';
                    node.textContent = '';

                    return;
                }

                node.textContent = message;
                node.className = 'text-xs mr-2 font-semibold '
                    + (tone === 'bad' ? 'text-red-600' : 'text-gray-500');
            };
        }

        /**
         * One review panel: open it, close it, and every way out of it.
         *
         * Escape is handled on the CAPTURE phase and stopped there, because a review
         * panel can be sitting over the dialog it is filling in — the award cards are
         * exactly that — and closing the panel must not also close what is underneath.
         * With the panel shut the handler does nothing and Escape belongs to whatever
         * is under it again.
         */
        function panel(options) {
            const dialog = options.dialog;
            const prefix = options.prefix;

            function open() {
                dialog.classList.remove('hidden');
                dialog.classList.add('flex');
                dialog.querySelector('[data-' + prefix + '-apply]')?.focus();
            }

            function close() {
                dialog.classList.add('hidden');
                dialog.classList.remove('flex');
                options.trigger?.focus();
            }

            dialog.querySelectorAll('[data-' + prefix + '-close]').forEach(function (button) {
                button.addEventListener('click', close);
            });

            dialog.addEventListener('click', function (event) {
                if (event.target === dialog) {
                    close();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !dialog.classList.contains('hidden')) {
                    event.stopImmediatePropagation();
                    close();
                }
            }, true);

            return { open: open, close: close };
        }

        /**
         * The button, the file picker and the POST.
         *
         * The endpoint it calls writes nothing. It answers with what it read, and what
         * happens to that answer is the caller's business.
         */
        function reader(options) {
            const trigger = options.trigger;
            const picker = options.picker;
            const say = options.say;
            let busy = false;

            trigger.addEventListener('click', function () {
                if (!busy) {
                    picker.click();
                }
            });

            picker.addEventListener('change', function () {
                if (busy || picker.files.length === 0) {
                    return;
                }

                const body = new FormData();

                body.append('_token', options.token || '');

                Array.prototype.forEach.call(picker.files, function (file) {
                    body.append('images[]', file);
                });

                busy = true;
                trigger.disabled = true;
                say(picker.files.length === 1
                    ? 'Reading the image...'
                    : 'Reading ' + picker.files.length + ' images...');

                fetch(options.url, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    body: body,
                })
                    .then(function (response) {
                        return response.json()
                            .then(function (data) { return { ok: response.ok, data: data }; })
                            .catch(function () { return { ok: false, data: {} }; });
                    })
                    .then(function (result) {
                        if (result.ok && result.data.ok) {
                            say('');
                            options.onRead(result.data);

                            return;
                        }

                        // A refusal from the reader carries a message; a refusal from
                        // validation carries errors instead.
                        const errors = result.data.errors
                            ? Object.values(result.data.errors).flat().join(' ')
                            : null;

                        say(result.data.message || errors || 'The screenshot could not be read.', 'bad');
                    })
                    .catch(function () {
                        say('The screenshot could not be read. Type the figures in instead.', 'bad');
                    })
                    .finally(function () {
                        busy = false;
                        trigger.disabled = false;

                        // Cleared so picking the same file again still fires a change.
                        picker.value = '';
                    });
            });
        }

        function token() {
            return document.querySelector('#score-form input[name="_token"]')?.value || '';
        }

        return {
            el: el,
            change: change,
            block: block,
            status: status,
            panel: panel,
            reader: reader,
            token: token,
        };
    })();
</script>
@endpush
@endif

{{-- Only shipped where the control exists, which is the same gate the button
     carries: a battle royale lobby, and an operator who may score it. --}}
@if ($readsSheet)
@push('scripts')
<script>
    (function () {
        /*
         | Detect Image AI: read the results table off a photograph.
         |
         | Three rules this script exists to keep.
         |
         | It never saves. The endpoint it calls writes nothing, and Apply types into
         | the inputs above and stops there. The result still goes through the same
         | Save button, the same validation and the same transaction as a figure
         | typed by hand, because a score that arrived on its own is a score nobody
         | checked.
         |
         | It never chooses. A row the server was unsure of, and a row two images
         | disagreed on, arrive unticked and marked. Rows that matched nothing and
         | competitors no row covered are listed in full, so what the reader missed
         | is on the screen rather than merely absent from it.
         |
         | And it builds every line of the dialog with textContent, which is what the
         | shared el() does. Those names came out of a model reading somebody's
         | photograph; they are data, and they are not ours to hand over as markup.
         |
         | The player panels are left alone on purpose. This read is about the squad
         | rows; the per-player screen is a different image and a different reader.
         */
        const review = window.scoreReview;
        const sheet = document.querySelector('[data-sheet]');
        const dialog = document.getElementById('sheet-dialog');
        const trigger = document.querySelector('[data-sheet-pick]');

        if (!review || !sheet || !dialog || !trigger) {
            return;
        }

        const rowsBox = dialog.querySelector('[data-sheet-rows]');
        const clashBox = dialog.querySelector('[data-sheet-clashes]');
        const unmatchedBox = dialog.querySelector('[data-sheet-unmatched]');
        const uncoveredBox = dialog.querySelector('[data-sheet-uncovered]');

        const el = review.el;
        const change = review.change;
        const block = review.block;
        const say = review.status(document.querySelector('[data-sheet-status]'));
        const dialogue = review.panel({ prefix: 'sheet', dialog: dialog, trigger: trigger });

        let reading = null;

        function field(entrantId, key) {
            return document.querySelector('[name="lines[' + entrantId + '][' + key + ']"]');
        }

        function winnerRadio(entrantId, key) {
            return document.querySelector('input[name="single[' + key + ']"][value="' + entrantId + '"]');
        }

        /*
         | What the form says now, read off the form rather than off the answer.
         |
         | The server sends what is recorded, but the operator may have typed since
         | the page loaded, and the figure Apply would overwrite is the one in the
         | box in front of them.
         */
        function onForm(entrantId, key, recorded) {
            const input = field(entrantId, key);

            if (input && input.value !== '') {
                return input.value;
            }

            return (recorded === null || recorded === undefined || recorded === '')
                ? 'blank'
                : String(recorded);
        }

        function render(data) {
            const placement = data.fields.placement;
            const kills = data.fields.kills;
            const winner = data.fields.winner;

            rowsBox.textContent = '';

            if (data.rows.length === 0) {
                // Nothing matched and nothing read either: the reader was told to
                // return no rows rather than invent them, so this is the wrong
                // screenshot rather than a hard one.
                rowsBox.appendChild(el(
                    'p',
                    'rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800',
                    data.unmatched.length === 0
                        ? 'This does not look like a PUBG results table. Nothing was read from it.'
                        : 'Nothing in the image could be matched to a competitor in this fixture.',
                ));
            }

            data.rows.forEach(function (row, index) {
                // Unsure of the name, or read two different ways in two images.
                // Either way the operator decides, so it starts unticked.
                const flagged = row.low_confidence || row.conflict;

                const line = el('label', 'flex items-start gap-3 rounded-lg border p-3 cursor-pointer '
                    + (flagged ? 'border-amber-300 bg-amber-50' : 'border-gray-200 bg-white hover:bg-blue-50/30'));

                const box = document.createElement('input');

                box.type = 'checkbox';
                box.className = 'mt-0.5 w-5 h-5 shrink-0 rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40';
                box.checked = !flagged;
                box.setAttribute('data-sheet-row', String(index));
                line.appendChild(box);

                const body = el('span', 'min-w-0 flex-1');
                const head = el('span', 'flex flex-wrap items-center gap-2');

                head.appendChild(el('span', 'text-sm font-semibold text-gray-900', row.name));

                if (winner && row.winner) {
                    head.appendChild(el(
                        'span',
                        'rounded bg-amber-100 px-1.5 py-0.5 text-xs font-bold text-amber-800',
                        winner.label,
                    ));
                }

                if (flagged) {
                    head.appendChild(el(
                        'span',
                        'rounded bg-amber-200 px-1.5 py-0.5 text-xs font-bold text-amber-900',
                        row.conflict ? 'Images disagree' : 'Check the name',
                    ));
                }

                body.appendChild(head);

                if (row.read_as && row.read_as !== row.name) {
                    body.appendChild(el('span', 'block text-xs text-gray-400 mt-0.5',
                        'Printed as "' + row.read_as + '"'));
                }

                body.appendChild(change(
                    placement.label,
                    onForm(row.entrant_id, placement.key, row.current.placement),
                    row.placement,
                ));

                body.appendChild(change(
                    kills.label,
                    onForm(row.entrant_id, kills.key, row.current.kills),
                    row.kills,
                ));

                line.appendChild(body);
                rowsBox.appendChild(line);
            });

            block(clashBox, 'Read two different ways', 'amber', data.clashes.map(function (clash) {
                return clash.name + ': kept ' + placement.label.toLowerCase() + ' ' + clash.kept.placement
                    + ' with ' + clash.kept.kills + ' ' + kills.label.toLowerCase()
                    + ', another image gave ' + clash.also_read.placement + ' with '
                    + clash.also_read.kills + '. Check which is right.';
            }));

            block(unmatchedBox, 'Rows nobody could be found for', 'amber', data.unmatched.map(function (row) {
                const figures = row.placement === null
                    ? ''
                    : ' (' + placement.label.toLowerCase() + ' ' + row.placement + ', '
                        + row.kills + ' ' + kills.label.toLowerCase() + ')';

                return '"' + row.read_as + '"' + figures + ' — ' + row.reason + '.';
            }));

            block(uncoveredBox, 'Competitors no row covered', 'gray', data.uncovered.map(function (entrant) {
                return entrant.name;
            }));
        }

        function apply() {
            if (reading === null) {
                return;
            }

            const placementKey = reading.fields.placement.key;
            const killsKey = reading.fields.kills.key;
            const winnerKey = reading.fields.winner ? reading.fields.winner.key : null;

            let filled = 0;

            rowsBox.querySelectorAll('[data-sheet-row]').forEach(function (box) {
                if (!box.checked) {
                    return;
                }

                const row = reading.rows[Number(box.getAttribute('data-sheet-row'))];

                if (!row) {
                    return;
                }

                const placementInput = field(row.entrant_id, placementKey);
                const killsInput = field(row.entrant_id, killsKey);

                if (placementInput) {
                    placementInput.value = String(row.placement);
                }

                if (killsInput) {
                    killsInput.value = String(row.kills);
                }

                // A radio group, so ticking the winner unticks whoever held it. Never
                // untick on its own: a row that is not the winner says nothing about
                // who is, and Nobody stays available at the foot of the table.
                if (winnerKey && row.winner) {
                    const radio = winnerRadio(row.entrant_id, winnerKey);

                    if (radio) {
                        radio.checked = true;
                    }
                }

                filled++;
            });

            dialogue.close();

            say(filled === 0
                ? 'Nothing was filled in.'
                : filled + (filled === 1 ? ' row' : ' rows') + ' filled in. Nothing is saved until you press Save.');
        }

        review.reader({
            trigger: trigger,
            picker: document.getElementById('sheet-images'),
            url: sheet.dataset.sheetUrl,
            token: review.token(),
            say: say,
            onRead: function (data) {
                reading = data;
                render(data);
                dialogue.open();
            },
        });

        dialog.querySelector('[data-sheet-apply]')?.addEventListener('click', apply);
    })();
</script>
@endpush
@endif

{{-- Only shipped where that control exists: a battle royale lobby whose profile
     hands out awards, with players to give them to, and an operator who may score. --}}
@if ($readsAwardSheet)
@push('scripts')
<script>
    (function () {
        /*
         | Detect Image AI, on the Star of the Match screen.
         |
         | The same three rules as the results table reader above it. It never saves:
         | Apply types into the award cards and stops, and the awards still go through
         | the dialog's own Save button. It never chooses: a card whose nickname did
         | not plainly match somebody arrives unticked and marked, and a card that
         | matched no award in the profile is listed rather than forced onto one. And
         | every line of the dialog is built with textContent, because those nicknames
         | came out of a model reading somebody's screenshot.
         */
        const review = window.scoreReview;
        const sheet = document.querySelector('[data-award-sheet]');
        const dialog = document.getElementById('award-sheet-dialog');
        const trigger = document.querySelector('[data-award-sheet-pick]');

        if (!review || !sheet || !dialog || !trigger) {
            return;
        }

        const rowsBox = dialog.querySelector('[data-award-sheet-rows]');
        const unknownBox = dialog.querySelector('[data-award-sheet-unknown]');
        const uncoveredBox = dialog.querySelector('[data-award-sheet-uncovered]');

        const el = review.el;
        const change = review.change;
        const block = review.block;
        const say = review.status(document.querySelector('[data-award-sheet-status]'));
        const dialogue = review.panel({ prefix: 'award-sheet', dialog: dialog, trigger: trigger });

        let reading = null;

        function awardField(awardKey, fieldKey) {
            return document.querySelector('[name="awards[' + awardKey + '][' + fieldKey + ']"]');
        }

        function render(data) {
            rowsBox.textContent = '';

            if (data.awards.length === 0) {
                rowsBox.appendChild(el(
                    'p',
                    'rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800',
                    data.unknown.length === 0
                        ? 'This does not look like the Star of the Match screen. Nothing was read from it.'
                        : 'Nothing on the screen matched an award this tournament hands out.',
                ));
            }

            data.awards.forEach(function (award, index) {
                // No player, or a nickname the server was not sure of. Either way the
                // operator decides, so it starts unticked.
                const flagged = !award.player || award.low_confidence;

                const line = el('label', 'flex items-start gap-3 rounded-lg border p-3 cursor-pointer '
                    + (flagged ? 'border-amber-300 bg-amber-50' : 'border-gray-200 bg-white hover:bg-blue-50/30'));

                const box = document.createElement('input');

                box.type = 'checkbox';
                box.className = 'mt-0.5 w-5 h-5 shrink-0 rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40';
                box.checked = !flagged;
                box.setAttribute('data-award-sheet-row', String(index));
                line.appendChild(box);

                const body = el('span', 'min-w-0 flex-1');
                const head = el('span', 'flex flex-wrap items-center gap-2');

                head.appendChild(el('span', 'text-sm font-semibold text-gray-900', award.label));

                if (flagged) {
                    head.appendChild(el(
                        'span',
                        'rounded bg-amber-200 px-1.5 py-0.5 text-xs font-bold text-amber-900',
                        award.player ? 'Check the player' : 'Pick the player yourself',
                    ));
                }

                body.appendChild(head);

                if (award.player) {
                    body.appendChild(el('span', 'block text-xs text-gray-600 mt-0.5',
                        award.player.name + (award.player.ign ? ' \u00b7 ' + award.player.ign : '')));

                    // The card was printed differently from the entry list. Worth
                    // seeing: this is the character or two OCR of that font moves.
                    if (award.read_as && award.player.ign !== award.read_as) {
                        body.appendChild(el('span', 'block text-xs text-gray-400 mt-0.5',
                            'Printed as "' + award.read_as + '"'));
                    }
                } else {
                    body.appendChild(el('span', 'block text-xs font-semibold text-amber-800 mt-0.5',
                        'Printed as "' + award.read_as + '" \u2014 ' + award.reason
                            + '. The figures below go in; pick the player yourself.'));
                }

                award.fields.forEach(function (field) {
                    if (!Object.prototype.hasOwnProperty.call(award.figures, field.key)) {
                        return;
                    }

                    const input = awardField(award.key, field.key);

                    // What is on the form now, read off the form: the operator may
                    // have typed since the page loaded, and the figure Apply would
                    // overwrite is the one in the box in front of them.
                    body.appendChild(change(
                        field.label,
                        input && input.value !== '' ? input.value : 'blank',
                        award.figures[field.key],
                    ));
                });

                if (award.unreadable.length > 0) {
                    const names = award.unreadable.map(function (key) {
                        const field = award.fields.filter(function (one) { return one.key === key; })[0];

                        return field ? field.label : key;
                    });

                    body.appendChild(el('span', 'block text-xs text-amber-800 mt-1',
                        'Could not be read: ' + names.join(', ') + '. Type those in.'));
                }

                line.appendChild(body);
                rowsBox.appendChild(line);
            });

            block(unknownBox, 'Cards that were left out', 'amber', data.unknown.map(function (card) {
                return '"' + card.label + '"' + (card.player ? ' (' + card.player + ')' : '')
                    + ' \u2014 ' + card.reason + '.';
            }));

            block(uncoveredBox, 'Awards the screenshot did not cover', 'gray', data.uncovered.map(function (award) {
                return award.label;
            }));
        }

        function apply() {
            if (reading === null) {
                return;
            }

            let filled = 0;

            rowsBox.querySelectorAll('[data-award-sheet-row]').forEach(function (box) {
                if (!box.checked) {
                    return;
                }

                const award = reading.awards[Number(box.getAttribute('data-award-sheet-row'))];

                if (!award) {
                    return;
                }

                /*
                 | ORDER MATTERS HERE, and this is the whole of why.
                 |
                 | The award dialog fills a card in from the player ledger when a
                 | player is picked: its change handler clears what it filled last
                 | time and writes whatever is already recorded for the new player in
                 | this match. That handler is useful and it stays — but it must not
                 | be the thing that decides what ends up on the card.
                 |
                 | So the player goes first, with the change event that handler
                 | listens for. dispatchEvent is synchronous: it runs to completion
                 | before the next line of this function does, which is what makes
                 | the order a guarantee rather than a race.
                 */
                if (award.player) {
                    const select = document.querySelector('[data-award-player="' + award.key + '"]');

                    if (select) {
                        select.value = String(award.player.id);
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }

                // Then the screenshot's figures last, over the top of whatever the
                // ledger just filled in. The screenshot is the evidence.
                award.fields.forEach(function (field) {
                    if (!Object.prototype.hasOwnProperty.call(award.figures, field.key)) {
                        return;
                    }

                    const input = awardField(award.key, field.key);

                    if (!input) {
                        return;
                    }

                    input.value = String(award.figures[field.key]);

                    // Not an auto-fill any more. A figure read off the image is
                    // evidence, and picking a different player must not wipe it the
                    // way it wipes a figure the ledger supplied.
                    delete input.dataset.autofilled;

                    filled++;
                });
            });

            dialogue.close();

            say(filled === 0
                ? 'Nothing was filled in.'
                : filled + (filled === 1 ? ' figure' : ' figures') + ' filled in. Nothing is saved until you press Save.');
        }

        /*
         | Escape closes this panel and nothing else, which the shared panel() does by
         | listening on the capture phase and stopping the event there. It matters most
         | here: this review panel sits OVER the award dialog it is filling in, and
         | closing the panel must not also close what is underneath.
         */
        review.reader({
            trigger: trigger,
            picker: document.getElementById('award-sheet-images'),
            url: sheet.dataset.awardSheetUrl,
            token: review.token(),
            say: say,
            onRead: function (data) {
                reading = data;
                render(data);
                dialogue.open();
            },
        });

        dialog.querySelector('[data-award-sheet-apply]')?.addEventListener('click', apply);
    })();
</script>
@endpush
@endif

{{-- Only shipped where that control exists: a battle royale lobby whose profile
     keeps personal figures, with line-ups on file, and an operator who may score. --}}
@if ($readsPlayerSheet)
@push('scripts')
<script>
    (function () {
        /*
         | Detect Image AI, on the game's per-player result screen.
         |
         | THE ONE THAT FILLS IN THE MOST, and so the one with the most to get wrong.
         | A block's rank is a squad's placement, its players' eliminations added up
         | are that squad's kills, each line is one player's own figure, and rank 1
         | takes the chicken dinner. Four answers, one image.
         |
         | The same three rules as the two readers above. It never saves: Apply types
         | into the form and stops. It never chooses: a squad identified through a
         | handle nobody was sure of, a block two images disagreed on, and a player
         | line matched to nobody all arrive unticked and marked, and a block that
         | resolved to no squad at all is listed with what was printed in it rather
         | than given to the nearest. And every line is built with textContent.
         |
         | AND IT NEVER TOUCHES WHO PLAYED. A squad with five on its entry and four on
         | the screen means somebody did not play — or means the screen was cropped. A
         | player who turned up and got nothing is not the player who did not turn up,
         | so the people no block mentioned are listed under their squad and their
         | played box is left exactly as the operator left it.
         */
        const review = window.scoreReview;
        const sheet = document.querySelector('[data-player-sheet]');
        const dialog = document.getElementById('player-sheet-dialog');
        const trigger = document.querySelector('[data-player-sheet-pick]');

        if (!review || !sheet || !dialog || !trigger) {
            return;
        }

        const rowsBox = dialog.querySelector('[data-player-sheet-rows]');
        const clashBox = dialog.querySelector('[data-player-sheet-clashes]');
        const unmatchedBox = dialog.querySelector('[data-player-sheet-unmatched]');
        const uncoveredBox = dialog.querySelector('[data-player-sheet-uncovered]');

        const el = review.el;
        const change = review.change;
        const block = review.block;
        const say = review.status(document.querySelector('[data-player-sheet-status]'));
        const dialogue = review.panel({ prefix: 'player-sheet', dialog: dialog, trigger: trigger });

        let reading = null;

        function field(entrantId, key) {
            return document.querySelector('[name="lines[' + entrantId + '][' + key + ']"]');
        }

        function winnerRadio(entrantId, key) {
            return document.querySelector('input[name="single[' + key + ']"][value="' + entrantId + '"]');
        }

        function playerField(entrantId, personId, key) {
            return document.querySelector('[name="players[' + entrantId + '][' + personId + '][' + key + ']"]');
        }

        /*
         | What the form says now, read off the form rather than off the answer.
         |
         | The server sends what is recorded, but the operator may have typed since the
         | page loaded, and the figure Apply would overwrite is the one in the box in
         | front of them.
         */
        function shown(input, recorded) {
            if (input && input.value !== '') {
                return input.value;
            }

            return (recorded === null || recorded === undefined || recorded === '')
                ? 'blank'
                : String(recorded);
        }

        function flag(node, text) {
            node.appendChild(el(
                'span',
                'rounded bg-amber-200 px-1.5 py-0.5 text-xs font-bold text-amber-900',
                text,
            ));
        }

        function tick(attribute, value, checked) {
            const box = document.createElement('input');

            box.type = 'checkbox';
            box.className = 'mt-0.5 w-5 h-5 shrink-0 rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40';
            box.checked = checked;
            box.setAttribute(attribute, value);

            return box;
        }

        function playerRow(team, teamIndex, player, playerIndex, label) {
            // Nobody was found for the handle, or found but not plainly. Either way the
            // operator decides, so it starts unticked.
            const flagged = player.id === null || player.low_confidence;

            const line = el('label', 'flex items-start gap-2 rounded-lg border px-2.5 py-2 cursor-pointer '
                + (flagged ? 'border-amber-300 bg-amber-50' : 'border-gray-200 bg-white hover:bg-blue-50/30'));

            // A line matched to nobody has nowhere to go, so there is nothing to tick.
            if (player.id === null) {
                line.appendChild(el('span', 'mt-0.5 w-5 shrink-0 text-center text-xs font-bold text-amber-700', '!'));
            } else {
                line.appendChild(tick('data-player-sheet-player', teamIndex + '-' + playerIndex, !flagged));
            }

            const body = el('span', 'min-w-0 flex-1');
            const head = el('span', 'flex flex-wrap items-center gap-2');

            head.appendChild(el(
                'span',
                'text-sm ' + (player.id === null ? 'font-semibold text-amber-900' : 'text-gray-900'),
                player.id === null ? player.read_as : player.name,
            ));

            if (player.id !== null && player.ign) {
                head.appendChild(el('span', 'text-xs text-gray-400', player.ign));
            }

            if (player.id === null) {
                flag(head, 'Type this one in');
            } else if (player.low_confidence) {
                flag(head, 'Check the name');
            }

            body.appendChild(head);

            if (player.id === null) {
                body.appendChild(el('span', 'block text-xs text-amber-800 mt-0.5',
                    player.reason + '. Its ' + label.toLowerCase() + ' of ' + player.value
                        + ' is counted in the squad total above.'));
            } else {
                // The handle on the screen is spelled differently from the entry list.
                // Worth seeing: this is the character or two OCR of that font moves.
                if (player.read_as && player.ign !== player.read_as) {
                    body.appendChild(el('span', 'block text-xs text-gray-400 mt-0.5',
                        'Printed as "' + player.read_as + '"'));
                }

                body.appendChild(change(
                    label,
                    shown(playerField(team.entrant_id, player.id, reading.player_field.key), player.current),
                    player.value,
                ));
            }

            line.appendChild(body);

            return line;
        }

        function render(data) {
            const placement = data.fields.placement;
            const kills = data.fields.kills;
            const winner = data.fields.winner;
            const label = data.player_field.label;

            rowsBox.textContent = '';

            if (data.teams.length === 0) {
                // Nothing matched and nothing read either: the reader was told to
                // return no blocks rather than invent them, so this is the wrong
                // screenshot rather than a hard one.
                rowsBox.appendChild(el(
                    'p',
                    'rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800',
                    data.unmatched.length === 0
                        ? 'This does not look like the game\'s per-player result screen. Nothing was read from it.'
                        : 'Nothing in the image could be matched to a squad in this fixture.',
                ));
            }

            data.teams.forEach(function (team, index) {
                const flagged = team.low_confidence || team.conflict;

                const box = el('div', 'rounded-lg border p-3 '
                    + (flagged ? 'border-amber-300 bg-amber-50' : 'border-gray-200 bg-white'));

                const line = el('label', 'flex items-start gap-3 cursor-pointer');

                line.appendChild(tick('data-player-sheet-team', String(index), !flagged));

                const body = el('span', 'min-w-0 flex-1');
                const head = el('span', 'flex flex-wrap items-center gap-2');

                head.appendChild(el('span', 'text-sm font-semibold text-gray-900', team.name));

                if (winner && team.winner) {
                    head.appendChild(el(
                        'span',
                        'rounded bg-amber-100 px-1.5 py-0.5 text-xs font-bold text-amber-800',
                        winner.label,
                    ));
                }

                if (team.conflict) {
                    flag(head, 'Images disagree');
                } else if (flagged) {
                    flag(head, 'Check the players');
                }

                body.appendChild(head);

                // No team name is printed on that screen at all: this squad was found
                // through its players, and the handles are how anybody checks that.
                body.appendChild(el('span', 'block text-xs text-gray-400 mt-0.5',
                    'Read as ' + team.read_as));

                body.appendChild(change(
                    placement.label,
                    shown(field(team.entrant_id, placement.key), team.current.placement),
                    team.placement,
                ));

                body.appendChild(change(
                    kills.label + ' (' + label.toLowerCase() + ' added up)',
                    shown(field(team.entrant_id, kills.key), team.current.kills),
                    team.kills,
                ));

                line.appendChild(body);
                box.appendChild(line);

                // Indented under the squad header it belongs to, with a margin the
                // built stylesheet already carries: a class nobody has used before
                // would need a fresh asset build to exist at all.
                const players = el('div', 'mt-2.5 ml-6 space-y-1.5');

                team.players.forEach(function (player, playerIndex) {
                    players.appendChild(playerRow(team, index, player, playerIndex, label));
                });

                box.appendChild(players);

                /*
                 | Registered and not on the screen. Named, never decided: whether
                 | somebody played is not a question an image answers, and a player who
                 | turned up and got no kills is not the player who did not turn up.
                 */
                if (team.absent.length > 0) {
                    box.appendChild(el('p', 'mt-2 ml-6 text-xs text-gray-500',
                        'Not on the screen: '
                            + team.absent.map(function (person) {
                                return person.ign || person.name;
                            }).join(', ')
                            + '. Their Played boxes are left exactly as they are.'));
                }

                rowsBox.appendChild(box);
            });

            block(clashBox, 'Read two different ways', 'amber', data.clashes.map(function (clash) {
                return clash.name + ': kept ' + placement.label.toLowerCase() + ' ' + clash.kept.placement
                    + ' with ' + clash.kept.kills + ' ' + kills.label.toLowerCase()
                    + ', another image gave ' + clash.also_read.placement + ' with '
                    + clash.also_read.kills + '. Check which is right.';
            }));

            block(unmatchedBox, 'Blocks no squad could be found for', 'amber', data.unmatched.map(function (row) {
                return row.read_as + ' — ' + row.reason + '.';
            }));

            block(uncoveredBox, 'Squads no block covered', 'gray', data.uncovered.map(function (entrant) {
                return entrant.name;
            }));
        }

        /**
         * Open a squad's player panel, because a closed one is not sent.
         *
         * Through the toggle button rather than by hand, so the chevron, the
         * aria-expanded state and the flag that marks the panel as sent all happen the
         * way they do when an operator opens it.
         */
        function openPanel(entrantId) {
            const panelBlock = document.querySelector('[data-player-block="' + entrantId + '"]');

            if (!panelBlock || !panelBlock.hasAttribute('hidden')) {
                return;
            }

            const toggle = document.querySelector('[data-player-toggle="players-' + entrantId + '"]');

            if (toggle) {
                toggle.click();

                return;
            }

            panelBlock.removeAttribute('hidden');
            panelBlock.dataset.opened = '1';
        }

        function apply() {
            if (reading === null) {
                return;
            }

            const placementKey = reading.fields.placement.key;
            const killsKey = reading.fields.kills.key;
            const winnerKey = reading.fields.winner ? reading.fields.winner.key : null;
            const personalKey = reading.player_field.key;

            let filled = 0;

            rowsBox.querySelectorAll('[data-player-sheet-team]').forEach(function (box) {
                if (!box.checked) {
                    return;
                }

                const team = reading.teams[Number(box.getAttribute('data-player-sheet-team'))];

                if (!team) {
                    return;
                }

                const placementInput = field(team.entrant_id, placementKey);
                const killsInput = field(team.entrant_id, killsKey);

                if (placementInput) {
                    placementInput.value = String(team.placement);
                }

                if (killsInput) {
                    killsInput.value = String(team.kills);
                }

                // A radio group, so ticking the winner unticks whoever held it. Never
                // untick on its own: a block that is not rank 1 says nothing about who
                // is, and Nobody stays available at the foot of the table.
                if (winnerKey && team.winner) {
                    const radio = winnerRadio(team.entrant_id, winnerKey);

                    if (radio) {
                        radio.checked = true;
                    }
                }

                filled++;
            });

            rowsBox.querySelectorAll('[data-player-sheet-player]').forEach(function (box) {
                if (!box.checked) {
                    return;
                }

                const parts = box.getAttribute('data-player-sheet-player').split('-');
                const team = reading.teams[Number(parts[0])];
                const player = team ? team.players[Number(parts[1])] : null;

                if (!player || player.id === null) {
                    return;
                }

                // A panel nobody opened is not sent, so it is opened before it is
                // filled in — otherwise this would type a figure into a field that
                // never reaches the server.
                openPanel(team.entrant_id);

                const input = playerField(team.entrant_id, player.id, personalKey);

                if (!input) {
                    return;
                }

                input.value = String(player.value);

                // The running totals under the panel listen for this, so the sums an
                // operator checks against the squad row are right without reloading.
                input.dispatchEvent(new Event('input', { bubbles: true }));

                filled++;
            });

            dialogue.close();

            say(filled === 0
                ? 'Nothing was filled in.'
                : filled + (filled === 1 ? ' figure' : ' figures')
                    + ' filled in. Who played was left alone. Nothing is saved until you press Save.');
        }

        review.reader({
            trigger: trigger,
            picker: document.getElementById('player-sheet-images'),
            url: sheet.dataset.playerSheetUrl,
            token: review.token(),
            say: say,
            onRead: function (data) {
                reading = data;
                render(data);
                dialogue.open();
            },
        });

        dialog.querySelector('[data-player-sheet-apply]')?.addEventListener('click', apply);
    })();
</script>
@endpush
@endif
