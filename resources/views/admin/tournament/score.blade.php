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

        $tracksPlayers = $playerInputs !== [] && $rosters !== [];

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
            <form action="{{ route('admin.tournaments.matches.score.save', $match) }}" method="POST"
                  enctype="multipart/form-data" id="score-form">
                @csrf
                @method('PUT')

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
                                                @if ($definition['type'] === 'marks')
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
                                        <tr id="players-{{ $entrantId }}" hidden data-player-block="{{ $entrantId }}">
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
                        </table>
                    </div>

                    <div class="px-4 py-3 border-t border-gray-200 bg-gray-50">
                        <p class="text-xs text-gray-600">
                            Points are worked out from these numbers and cannot be typed. Tab moves
                            down the list in reading order.
                        </p>
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
                    </p>
                    <button type="submit" @disabled(! $canScore)
                            class="rounded-lg border border-blue-600 bg-blue-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm disabled:opacity-50 disabled:cursor-not-allowed shrink-0">
                        {{ $match->isSettled() ? 'Correct Result' : 'Save Result' }}
                    </button>
                </div>
            </form>

            {{-- ============ Walkover, forfeit, DQ ============ --}}
            @if ($canScore)
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
    })();
</script>
@endpush
