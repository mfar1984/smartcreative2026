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
                    {{ $match->scored_at?->format('d M Y, g:i a') }}
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
                                                                               class="rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                                                                        <label for="took-{{ $entrantId }}-{{ $person->id }}" class="sr-only">
                                                                            {{ $person->full_name }} took part in this match
                                                                        </label>
                                                                    </td>

                                                                    <td class="px-3 py-2">
                                                                        <span class="text-sm text-gray-900">{{ $person->full_name }}</span>
                                                                        @if ($person->ign_player_id)
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
                                                                                   data-player-sum="{{ $entrantId }}-{{ $pkey }}">
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
                                        uploaded {{ $proof->created_at->format('d M, g:i a') }}
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
                                        <p class="text-xs font-bold uppercase tracking-wide text-gray-500">Star of the Match</p>
                                        <p class="text-xs text-gray-500 mt-0.5">
                                            Copied from the game's result screen. Picking a player fills in what is already
                                            recorded for them in this match, and what is saved here counts on the player
                                            leaderboard too. Leave an award to nobody if it was not given.
                                        </p>

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

                <input type="hidden" name="_form_complete" value="1">
            </form>

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
         | Leave out the player panels nobody opened.
         |
         | A panel that stayed closed cannot have been changed, and the server keeps
         | whatever is on file for a player it is not sent. Without this the form grew
         | past PHP's limit on fields per request and the last squad was cut off.
         | Done at the moment of sending, so everything above can still read them.
         */
        const scoreForm = document.getElementById('score-form');

        if (scoreForm && scoreForm.hasAttribute('data-skip-unopened')) {
            scoreForm.addEventListener('submit', function () {
                scoreForm.querySelectorAll('[data-player-block]').forEach(function (block) {
                    if (block.dataset.opened === '1') {
                        return;
                    }

                    block.querySelectorAll('input, select, textarea').forEach(function (field) {
                        field.disabled = true;
                    });
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
