@extends('layouts.admin')

@section('title', $mode === 'create' ? 'New Point Rule' : 'Edit ' . $rule->name)

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.tournaments.rules') }}" class="hover:text-gray-700 transition">Point Rules</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $mode === 'create' ? 'New' : Str::limit($rule->name, 40) }}</span>
@endsection

@section('content')
    @php
        use App\Models\PointRule;

        $input = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
        $cell = 'w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm text-center tabular-nums text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';

        $kind = old('kind', $rule->kind);
        $squadSize = (int) old('squad_size', $rule->squad_size ?: 4);

        // How far the placement table runs. Battle royale lobbies hold 16; a race
        // can pay out to any depth, so the stored table decides.
        $placementRows = $kind === PointRule::KIND_BATTLE_ROYALE
            ? 16
            : max(10, count($placement));
    @endphp

    <x-admin.page-card
        :title="$mode === 'create' ? 'New Point Rule' : 'Edit ' . $rule->name"
        description="What a result is worth. Every value here is data, so a new sport needs no new code."
        :back="route('admin.tournaments.rules', ['tab' => $kind])">

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

        <form action="{{ $mode === 'create' ? route('admin.tournaments.rules.store') : route('admin.tournaments.rules.update', $rule) }}"
              method="POST">
            @csrf
            @if ($mode === 'edit') @method('PUT') @endif

            <x-admin.panel title="What It Is" icon="clipboard">
                <x-admin.field-row label="Name" help="How you will recognise it when a tournament picks one." for="name" :required="true" error="name">
                    <input type="text" id="name" name="name" required maxlength="190"
                           value="{{ old('name', $rule->name) }}"
                           placeholder="e.g. PMPL / PMGC Official"
                           class="{{ $input }}">
                </x-admin.field-row>

                <x-admin.field-row label="Family" help="Decides what the score form asks for. Cannot be changed once saved." for="kind" :required="true" error="kind">
                    @if ($mode === 'create')
                        <select id="kind" name="kind" class="{{ $input }} bg-white" data-kind>
                            @foreach ($kinds as $value => $label)
                                <option value="{{ $value }}" @selected($kind === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-500 mt-1.5">
                            Changing this reloads the form, because each family collects different numbers.
                        </p>
                    @else
                        <input type="hidden" name="kind" value="{{ $kind }}">
                        <p class="text-sm text-gray-900 md:pt-2">{{ $kinds[$kind] ?? $kind }}</p>
                        <p class="text-xs text-gray-500 mt-0.5">
                            Fixed. A profile that has scored matches cannot change family without
                            making those results mean something different.
                        </p>
                    @endif
                </x-admin.field-row>

                @if (in_array($kind, [PointRule::KIND_BATTLE_ROYALE, PointRule::KIND_BRACKET], true))
                    <x-admin.field-row label="Full squad" help="How many players make a complete team. The penalty table measures against this." for="squad_size" error="squad_size">
                        <input type="number" id="squad_size" name="squad_size" min="1" max="50"
                               value="{{ old('squad_size', $rule->squad_size) }}"
                               class="{{ $input }} max-w-32">
                    </x-admin.field-row>
                @endif

                <x-admin.field-row label="Active" help="An inactive rule stays for the record but is not offered to a new tournament.">
                    <label class="inline-flex items-center gap-2 md:pt-2 cursor-pointer select-none">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1"
                               @checked(old('is_active', $rule->exists ? $rule->is_active : true))
                               class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                        <span class="text-sm text-gray-700">Offer this rule to new tournaments</span>
                    </label>
                </x-admin.field-row>
            </x-admin.panel>

            {{-- ============ Battle royale and race: the placement table ============ --}}
            @if (in_array($kind, [PointRule::KIND_BATTLE_ROYALE, PointRule::KIND_RACE], true))
                <x-admin.panel title="Placement Points" icon="activity">
                    <div class="px-5 py-4">
                        <p class="text-sm text-gray-600 mb-4">
                            What each finishing position is worth. Leave a row empty for nothing.
                        </p>

                        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3">
                            @for ($position = 1; $position <= $placementRows; $position++)
                                <div>
                                    <label for="placement-{{ $position }}"
                                           class="block text-xs font-semibold text-gray-600 mb-1 text-center">
                                        {{ $position }}{{ [1 => 'st', 2 => 'nd', 3 => 'rd'][$position] ?? 'th' }}
                                    </label>
                                    <input type="number" step="0.5" id="placement-{{ $position }}"
                                           name="placement[{{ $position }}]"
                                           value="{{ old('placement.' . $position, $placement[(string) $position] ?? null) }}"
                                           class="{{ $cell }}">
                                </div>
                            @endfor
                        </div>
                    </div>
                </x-admin.panel>
            @endif

            {{-- ============ Battle royale: kills, WWCD, squad penalty ============ --}}
            @if ($kind === PointRule::KIND_BATTLE_ROYALE)
                <x-admin.panel title="Kills And WWCD" icon="mobile">
                    <x-admin.field-row label="Each kill" help="Points earned per kill." for="kill_value" error="kill_value">
                        <input type="number" step="0.5" min="0" id="kill_value" name="kill_value"
                               value="{{ old('kill_value', $killValue) }}"
                               class="{{ $input }} max-w-32">
                    </x-admin.field-row>

                    <x-admin.field-row label="WWCD bonus" help="Extra points for finishing first. Usually zero, but it is still counted first at a tie." for="wwcd_value" error="wwcd_value">
                        <input type="number" step="0.5" min="0" id="wwcd_value" name="wwcd_value"
                               value="{{ old('wwcd_value', $wwcdValue) }}"
                               class="{{ $input }} max-w-32">
                    </x-admin.field-row>
                </x-admin.panel>

                <x-admin.panel title="Short Squad Penalty" icon="lock">
                    <div class="px-5 py-4">
                        <p class="text-sm text-gray-600 mb-4">
                            Points taken off when a team fields fewer than {{ $squadSize }}. Applied once for
                            each match they were short. Enter negative numbers.
                        </p>

                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 max-w-lg">
                            @for ($present = $squadSize - 1; $present >= 1; $present--)
                                <div>
                                    <label for="penalty-{{ $present }}"
                                           class="block text-xs font-semibold text-gray-600 mb-1 text-center">
                                        {{ $present }} {{ Str::plural('player', $present) }}
                                    </label>
                                    <input type="number" step="0.5" max="0" id="penalty-{{ $present }}"
                                           name="penalty[{{ $present }}]"
                                           value="{{ old('penalty.' . $present, $penalty[(string) $present] ?? null) }}"
                                           class="{{ $cell }}">
                                </div>
                            @endfor
                        </div>

                        <div class="mt-4 max-w-xs">
                            <label for="disqualify_at" class="block text-xs font-semibold text-gray-600 mb-1">
                                Disqualify the match at or below
                            </label>
                            <input type="number" min="0" id="disqualify_at" name="disqualify_at"
                                   value="{{ old('disqualify_at', $disqualifyAt) }}"
                                   class="{{ $cell }}">
                            <p class="text-xs text-gray-500 mt-1">
                                Marks that one match DQ. The team stays in the tournament for later matches.
                            </p>
                        </div>
                    </div>
                </x-admin.panel>
            @endif

            {{-- ============ Bracket ============ --}}
            @if ($kind === PointRule::KIND_BRACKET)
                <x-admin.panel title="Series Result" icon="grid">
                    <x-admin.field-row label="What is counted" help="Games for Mobile Legends, sets for badminton or tennis." for="series_label" error="series_label">
                        <input type="text" id="series_label" name="series_label" maxlength="40"
                               value="{{ old('series_label', $seriesLabel) }}"
                               placeholder="Games Won"
                               class="{{ $input }} max-w-64">
                    </x-admin.field-row>

                    <x-admin.field-row label="Most in a series" help="3 for a best of five, 2 for a best of three." for="series_max" error="series_max">
                        <input type="number" min="1" max="9" id="series_max" name="series_max"
                               value="{{ old('series_max', $seriesMax) }}"
                               class="{{ $input }} max-w-32">
                    </x-admin.field-row>

                    <x-admin.field-row label="Record total points" help="Worth nothing on its own, but it settles a tie and it is what a disputed result is argued over.">
                        <label class="inline-flex items-center gap-2 md:pt-2 cursor-pointer select-none">
                            <input type="hidden" name="track_points_scored" value="0">
                            <input type="checkbox" name="track_points_scored" value="1"
                                   @checked(old('track_points_scored', $trackPointsScored))
                                   class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                            <span class="text-sm text-gray-700">Ask for points scored as well</span>
                        </label>
                    </x-admin.field-row>
                </x-admin.panel>
            @endif

            {{-- ============ Judged ============ --}}
            @if ($kind === PointRule::KIND_JUDGED)
                <x-admin.panel title="The Panel" icon="users">
                    <x-admin.field-row label="Judges" help="How many marks the form asks for." for="judge_count" :required="true" error="judge_count">
                        <input type="number" min="1" max="15" id="judge_count" name="judge_count"
                               value="{{ old('judge_count', $judgeCount) }}"
                               class="{{ $input }} max-w-32">
                    </x-admin.field-row>

                    <x-admin.field-row label="Highest mark" help="The top of each judge's scale." for="judge_max" error="judge_max">
                        <input type="number" step="0.5" min="1" id="judge_max" name="judge_max"
                               value="{{ old('judge_max', $judgeMax) }}"
                               class="{{ $input }} max-w-32">
                    </x-admin.field-row>

                    <x-admin.field-row label="How marks combine" for="judge_method" :required="true" error="judge_method">
                        <select id="judge_method" name="judge_method" class="{{ $input }} bg-white">
                            @foreach ($methods as $value => $label)
                                <option value="{{ $value }}" @selected(old('judge_method', $judgeMethod) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-500 mt-1.5">
                            Dropping the highest and lowest stops one generous or one harsh judge
                            deciding the result. It needs at least three marks to mean anything.
                        </p>
                    </x-admin.field-row>

                    <x-admin.field-row label="Each fault" help="Points taken off per fault. Leave at zero to not ask about faults." for="deduction_value" error="deduction_value">
                        <input type="number" step="0.5" max="0" id="deduction_value" name="deduction_value"
                               value="{{ old('deduction_value', $deductionValue) }}"
                               class="{{ $input }} max-w-32">
                    </x-admin.field-row>
                </x-admin.panel>
            @endif

            {{-- ============ Tie-break ============ --}}
            @if (filled($tiebreakOptions))
                <x-admin.panel title="Tie-break Order" icon="shield">
                    <div class="px-5 py-4">
                        <p class="text-sm text-gray-600 mb-4">
                            When two competitors finish level on points, these are compared in turn.
                            The PMPL order is WWCD, then placement, then kills.
                        </p>

                        <div class="space-y-3 max-w-md">
                            @for ($position = 0; $position < count($tiebreakOptions); $position++)
                                <div class="flex items-center gap-3">
                                    <span class="text-sm font-semibold text-gray-500 w-5 text-right">{{ $position + 1 }}.</span>
                                    <label for="tiebreak-{{ $position }}" class="sr-only">Tie-break {{ $position + 1 }}</label>
                                    <select id="tiebreak-{{ $position }}" name="tiebreak[{{ $position }}]"
                                            class="{{ $input }} bg-white">
                                        <option value="">Nothing</option>
                                        @foreach ($tiebreakOptions as $key => $label)
                                            <option value="{{ $key }}"
                                                @selected(old('tiebreak.' . $position, $rule->tiebreak[$position] ?? null) === $key)>
                                                {{ $label }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            @endfor
                        </div>

                        <p class="text-xs text-gray-500 mt-3">
                            Competitors still level after all of these are shown as genuinely tied,
                            for the organiser to settle.
                        </p>
                    </div>
                </x-admin.panel>
            @endif

            {{-- ============ Personal player scoring ============
                 A second ledger. Nothing set here is ever added to the team figures
                 above, and nothing above is added here. That is what allows it to be
                 left switched off, or switched on and left empty, without the
                 tournament result depending on it. --}}
            @php
                $currentTrack = old('track_players', $trackPlayers);
                $statRows = old('player_stats', $playerStats);
                $statSlots = max(6, count($statRows) + 2);
            @endphp

            <x-admin.panel title="Personal Player Scoring" icon="users">
                <div class="px-5 py-4 border-b border-gray-100">
                    <p class="text-sm text-gray-600">
                        An optional second scoreboard for individual players, used for MVP and
                        Top Fragger. It is counted on its own and
                        <strong class="font-semibold text-gray-900">never added to a team's points</strong>,
                        so a tournament can be run and published without any of it being filled in.
                    </p>
                </div>

                <x-admin.field-row label="Track players" help="Off hides the player rows on the score form entirely." for="track_players" error="track_players">
                    <select id="track_players" name="track_players" class="{{ $input }} bg-white max-w-md"
                            data-track-players>
                        @foreach ($trackModes as $value => $label)
                            <option value="{{ $value }}" @selected($currentTrack === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-admin.field-row>

                <div data-player-fields @class(['hidden' => $currentTrack === PointRule::TRACK_OFF])>
                    <div class="px-5 py-4 border-t border-gray-100">
                        <p class="text-sm text-gray-600 mb-1">
                            What is recorded for each player, and what a unit is worth.
                        </p>
                        <p class="text-xs text-gray-500 mb-4">
                            Leave the points at <span class="font-semibold">0</span> to record a figure
                            without it earning anything. Damage set to 0 still shows on the leaderboard
                            and can still settle a tie, in the same way a WWCD does for a squad.
                        </p>

                        <div class="space-y-2 max-w-2xl">
                            <div class="grid grid-cols-12 gap-3 px-1">
                                <span class="col-span-6 text-xs font-bold uppercase tracking-wide text-gray-500">What is recorded</span>
                                <span class="col-span-3 text-xs font-bold uppercase tracking-wide text-gray-500">Points each</span>
                                <span class="col-span-3 text-xs font-bold uppercase tracking-wide text-gray-500">Must be filled</span>
                            </div>

                            @for ($slot = 0; $slot < $statSlots; $slot++)
                                @php $row = $statRows[$slot] ?? null; @endphp

                                <div class="grid grid-cols-12 gap-3 items-center">
                                    <div class="col-span-6">
                                        <label for="player-stat-{{ $slot }}" class="sr-only">Player stat {{ $slot + 1 }}</label>
                                        <input type="hidden" name="player_stats[{{ $slot }}][key]" value="{{ $row['key'] ?? '' }}">
                                        <input type="text" id="player-stat-{{ $slot }}"
                                               name="player_stats[{{ $slot }}][label]"
                                               value="{{ $row['label'] ?? '' }}"
                                               maxlength="60"
                                               placeholder="{{ $slot === 0 ? 'e.g. Kills' : ($slot === 1 ? 'e.g. Knocks' : 'Leave blank to skip') }}"
                                               class="{{ $input }}">
                                    </div>

                                    <div class="col-span-3">
                                        <label for="player-stat-value-{{ $slot }}" class="sr-only">Points per unit for stat {{ $slot + 1 }}</label>
                                        <input type="number" step="0.5" id="player-stat-value-{{ $slot }}"
                                               name="player_stats[{{ $slot }}][value]"
                                               value="{{ $row['value'] ?? '' }}"
                                               class="{{ $input }} text-center tabular-nums">
                                    </div>

                                    <div class="col-span-3">
                                        <label class="inline-flex items-center gap-2 cursor-pointer select-none">
                                            <input type="hidden" name="player_stats[{{ $slot }}][required]" value="0">
                                            <input type="checkbox" name="player_stats[{{ $slot }}][required]" value="1"
                                                   @checked($row['required'] ?? false)
                                                   class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                                            <span class="text-xs text-gray-600">Required</span>
                                        </label>
                                    </div>
                                </div>
                            @endfor
                        </div>

                        <p class="text-xs text-gray-500 mt-3 max-w-2xl">
                            Renaming a row keeps the figures already recorded against it. Clearing the
                            name removes the column from the score form; anything already saved under it
                            stays in the database but stops being counted.
                        </p>
                    </div>

                    @if (filled($playerTiebreakOptions))
                        <div class="px-5 py-4 border-t border-gray-100">
                            <p class="text-sm text-gray-600 mb-4">
                                When two players finish level on personal points, these are compared in
                                turn. This order is separate from the team tie-break above.
                            </p>

                            <div class="space-y-3 max-w-md">
                                @for ($position = 0; $position < count($playerTiebreakOptions); $position++)
                                    <div class="flex items-center gap-3">
                                        <span class="text-sm font-semibold text-gray-500 w-5 text-right">{{ $position + 1 }}.</span>
                                        <label for="player-tiebreak-{{ $position }}" class="sr-only">Player tie-break {{ $position + 1 }}</label>
                                        <select id="player-tiebreak-{{ $position }}" name="player_tiebreak[{{ $position }}]"
                                                class="{{ $input }} bg-white">
                                            <option value="">Nothing</option>
                                            @foreach ($playerTiebreakOptions as $key => $label)
                                                <option value="{{ $key }}"
                                                    @selected(old('player_tiebreak.' . $position, $playerTiebreak[$position] ?? null) === $key)>
                                                    {{ $label }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endfor
                            </div>
                        </div>
                    @else
                        <div class="px-5 py-4 border-t border-gray-100">
                            <p class="text-xs text-gray-500">
                                Save the stats above once and the player tie-break order will appear here.
                            </p>
                        </div>
                    @endif
                </div>
            </x-admin.panel>

            @if ($liveTournaments > 0)
                <div role="alert" class="flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-lg p-4 mt-5">
                    <x-admin.icon name="lock" class="w-5 h-5 mt-0.5 shrink-0 text-amber-600" />
                    <div class="text-sm text-amber-800">
                        <p class="font-semibold mb-1">
                            {{ $liveTournaments }} {{ Str::plural('tournament', $liveTournaments) }} already scored against this rule
                        </p>
                        <label class="inline-flex items-start gap-2 cursor-pointer select-none">
                            <input type="checkbox" name="confirm_recalculate" value="1"
                                   @checked(old('confirm_recalculate'))
                                   class="w-4 h-4 mt-0.5 rounded border-amber-400 text-amber-600 focus:ring-2 focus:ring-amber-500/40">
                            <span>
                                I understand those standings will be recalculated with the new values.
                            </span>
                        </label>
                        @error('confirm_recalculate')
                            <p class="text-xs text-red-700 mt-1.5 font-semibold">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-4 bg-white rounded-lg border border-gray-200 px-5 py-4 mt-5">
                <p class="text-xs text-gray-500 max-w-md">
                    A rule can be edited later. Tournaments already scored against it will have
                    their standings worked out again from the matches.
                </p>
                <button type="submit"
                        class="rounded-lg border border-blue-600 bg-blue-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm shrink-0">
                    {{ $mode === 'create' ? 'Save Point Rule' : 'Save Changes' }}
                </button>
            </div>
        </form>
    </x-admin.page-card>
@endsection

@push('scripts')
<script>
    (function () {
        // Each family collects different numbers, so switching reloads the form
        // rather than hiding half of it behind JavaScript that the server would
        // then have to second-guess.
        const kind = document.querySelector('[data-kind]');

        kind?.addEventListener('change', function () {
            const url = new URL(window.location.href);
            url.searchParams.set('kind', kind.value);
            window.location.href = url.toString();
        });

        /*
         | Player tracking hides its own fields rather than reloading, because the
         | fields are the same whichever mode is picked. The server clears them when
         | the mode is off, so a hidden field cannot save anything by accident.
         */
        const track = document.querySelector('[data-track-players]');
        const fields = document.querySelector('[data-player-fields]');

        track?.addEventListener('change', function () {
            fields?.classList.toggle('hidden', track.value === 'off');
        });
    })();
</script>
@endpush
