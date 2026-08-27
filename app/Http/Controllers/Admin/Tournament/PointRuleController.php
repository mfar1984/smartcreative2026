<?php

namespace App\Http\Controllers\Admin\Tournament;

use App\Http\Controllers\Controller;
use App\Models\PointRule;
use App\Services\AdminLogger;
use App\Support\Tournament\PlayerStandingsCalculator;
use App\Support\Tournament\Rescorer;
use App\Support\Tournament\StandingsCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Point Rules: the scoring an operator sets, rather than the scoring a programmer
 * decided.
 *
 * The form does not ask anybody to edit JSON. It asks for a placement table, a
 * value per kill, a penalty table and a tie-break order, and assembles the
 * components from those answers. What is stored is generic; what is shown is the
 * language of the sport.
 */
class PointRuleController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * What each tab holds, shown above its table.
     *
     * @var array<string, array<string, string>>
     */
    private const TAB_INTRO = [
        PointRule::KIND_BATTLE_ROYALE => [
            'label' => 'Battle Royale',
            'icon' => 'mobile',
            'title' => 'Battle Royale',
            'description' => 'Points from finishing position plus kills, the way PUBG is scored.',
            'accent' => 'amber',
        ],
        PointRule::KIND_BRACKET => [
            'label' => 'Bracket',
            'icon' => 'grid',
            'title' => 'Bracket',
            'description' => 'Head to head. The winner advances, so the series result is all that is recorded.',
            'accent' => 'blue',
        ],
        PointRule::KIND_RACE => [
            'label' => 'Race',
            'icon' => 'activity',
            'title' => 'Race',
            'description' => 'Finishing order worked out from times, then points awarded by position.',
            'accent' => 'green',
        ],
        PointRule::KIND_JUDGED => [
            'label' => 'Judged',
            'icon' => 'users',
            'title' => 'Judged',
            'description' => 'A panel awards marks, combined by the method you choose.',
            'accent' => 'purple',
        ],
    ];

    public function index(Request $request)
    {
        $kind = $this->resolveKind($request->query('tab'));
        $search = trim((string) $request->query('q', ''));

        $query = PointRule::query()
            ->withCount('tournaments')
            ->where('kind', $kind)
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"));

        $counts = PointRule::selectRaw('kind, COUNT(*) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind');

        return view('admin.tournament.rules', [
            'tabs' => collect(self::TAB_INTRO)
                ->map(fn (array $tab, string $slug) => [
                    'label' => $tab['label'],
                    'icon' => $tab['icon'],
                    'count' => (int) ($counts[$slug] ?? 0),
                ])
                ->all(),

            'activeTab' => $kind,
            'intro' => self::TAB_INTRO[$kind],
            'route' => 'admin.tournaments.rules',
            'rules' => $query->orderBy('name')->paginate(self::PER_PAGE)->withQueryString(),
            'search' => $search,
            'isFiltered' => $search !== '',
            'canCreate' => $request->user()->hasPermission('tournaments.rules.create'),
            'canUpdate' => $request->user()->hasPermission('tournaments.rules.update'),
            'canDelete' => $request->user()->hasPermission('tournaments.rules.delete'),
        ]);
    }

    public function create(Request $request)
    {
        $kind = $this->resolveKind($request->query('kind'));

        return view('admin.tournament.rule-form', $this->formData(
            new PointRule(['kind' => $kind] + $this->defaultsFor($kind)),
        ));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $rule = PointRule::create($this->assemble($data) + [
            'created_by' => $request->user()->id,
        ]);

        AdminLogger::activity('tournaments.rules.create', sprintf('Created point rule %s.', $rule->name));

        return redirect()
            ->route('admin.tournaments.rules', ['tab' => $rule->kind])
            ->with('status', sprintf('Point rule %s saved.', $rule->name));
    }

    public function edit(PointRule $rule)
    {
        return view('admin.tournament.rule-form', $this->formData($rule));
    }

    public function update(
        Request $request,
        PointRule $rule,
        Rescorer $rescorer,
        StandingsCalculator $calculator,
        PlayerStandingsCalculator $playerCalculator,
    ) {
        $data = $this->validated($request, $rule);

        /*
         | A profile in use by a live tournament is a different matter from one
         | nobody has scored against yet. Editing it will change standings already
         | recorded, so the operator has to say so deliberately.
         */
        $liveCount = $rule->tournaments()->whereIn('status', ['ongoing', 'completed', 'published'])->count();

        if ($liveCount > 0 && ! $request->boolean('confirm_recalculate')) {
            return back()->withInput()->withErrors([
                'confirm_recalculate' => sprintf(
                    'This rule scores %d tournament(s) that already have results. Tick the box to confirm those standings will be recalculated.',
                    $liveCount,
                ),
            ]);
        }

        $tracked = ['name', 'squad_size', 'components', 'tiebreak', 'track_players', 'player_components', 'player_tiebreak'];
        $before = $rule->only($tracked);

        $rule->update($this->assemble($data));

        /*
         | Actually recalculate, rather than only promising to.
         |
         | The operator was asked to confirm that standings would be worked out again,
         | so they must be. Done in this request for the same reason score entry is:
         | there is no queue worker certain to be running, and a table that silently
         | disagrees with its own point rule is worse than a slow save.
         |
         | Both ledgers, because a change to `player_components` moves the player
         | leaderboard and a change to `components` moves the team table. Neither can
         | move the other.
         */
        $affected = $rule->tournaments()
            ->whereIn('status', ['ongoing', 'completed', 'published'])
            ->get();

        foreach ($affected as $tournament) {
            /*
             | Re-score before rebuilding. The standings calculators add up stored
             | component points, so without this step they would total the old numbers
             | again and the new values would never show. `inputs` is what the operator
             | typed; everything else is derived from it and is rebuilt here.
             */
            $rescorer->rescore($tournament);

            $calculator->recalculate($tournament);
            $playerCalculator->recalculate($tournament);
        }

        AdminLogger::activity('tournaments.rules.update', sprintf('Updated point rule %s.', $rule->name));
        AdminLogger::audit($rule, 'point_rule.updated', $before, $rule->only($tracked));

        return redirect()
            ->route('admin.tournaments.rules', ['tab' => $rule->kind])
            ->with('status', $liveCount > 0
                ? sprintf(
                    'Point rule %s saved. Standings for %d %s recalculated.',
                    $rule->name,
                    $affected->count(),
                    $affected->count() === 1 ? 'tournament were' : 'tournaments were',
                )
                : sprintf('Point rule %s saved.', $rule->name));
    }

    public function destroy(PointRule $rule)
    {
        $used = $rule->tournaments()->pluck('name');

        if ($used->isNotEmpty()) {
            return back()->withErrors([
                'rule' => sprintf(
                    'Used by %s, so it cannot be deleted. Point the tournament at another rule first.',
                    $used->implode(', '),
                ),
            ]);
        }

        $name = $rule->name;
        $kind = $rule->kind;
        $rule->delete();

        AdminLogger::activity('tournaments.rules.delete', sprintf('Deleted point rule %s.', $name));

        return redirect()
            ->route('admin.tournaments.rules', ['tab' => $kind])
            ->with('status', sprintf('Point rule %s deleted.', $name));
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?PointRule $rule = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:190',
                Rule::unique('point_rules', 'name')->ignore($rule?->id),
            ],
            'kind' => ['required', Rule::in(array_keys(PointRule::KINDS))],
            'squad_size' => ['nullable', 'integer', 'min:1', 'max:50'],

            // Battle royale and race
            'placement' => ['array'],
            'placement.*' => ['nullable', 'numeric', 'between:-100,1000'],

            'kill_value' => ['nullable', 'numeric', 'between:0,100'],
            'wwcd_value' => ['nullable', 'numeric', 'between:0,100'],

            'penalty' => ['array'],
            'penalty.*' => ['nullable', 'numeric', 'between:-100,0'],
            'disqualify_at' => ['nullable', 'integer', 'min:0'],

            // Bracket
            'series_label' => ['nullable', 'string', 'max:40'],
            'series_max' => ['nullable', 'integer', 'min:1', 'max:9'],
            'track_points_scored' => ['nullable', 'boolean'],

            // Judged
            'judge_count' => ['nullable', 'integer', 'min:1', 'max:15'],
            'judge_method' => ['nullable', Rule::in(array_keys(PointRule::AGGREGATE_METHODS))],
            'judge_max' => ['nullable', 'numeric', 'min:1', 'max:100'],
            'deduction_value' => ['nullable', 'numeric', 'between:-100,0'],

            'tiebreak' => ['array'],
            'tiebreak.*' => ['nullable', 'string', 'max:40'],

            /*
             | The player ledger. A separate set of figures with its own values and its
             | own tie-break, which is why none of it reuses the fields above.
             */
            'track_players' => ['nullable', Rule::in(array_keys(PointRule::TRACK_MODES))],
            'player_stats' => ['array'],
            'player_stats.*.key' => ['nullable', 'string', 'max:40'],
            'player_stats.*.label' => ['nullable', 'string', 'max:60'],
            'player_stats.*.value' => ['nullable', 'numeric', 'between:-100,100'],
            'player_stats.*.required' => ['nullable', 'boolean'],
            'player_tiebreak' => ['array'],
            'player_tiebreak.*' => ['nullable', 'string', 'max:40'],

            'is_active' => ['nullable', 'boolean'],
        ], [
            'name.unique' => 'A point rule with that name already exists.',
        ]);
    }

    /**
     * Build the player ledger out of the stat rows the form collected.
     *
     * Every stat becomes one `per_unit` component and one integer input. That covers
     * every per-player figure in the sports asked for: kills, knocks, damage, assists,
     * deaths. A stat worth zero points is still counted, so it can settle an MVP tie
     * without being a score, which is the same trick the team side uses for a WWCD.
     *
     * Deliberately narrower than the team builder. A player table or a player bonus
     * would need this method extended; the engine already supports them.
     *
     * @param  array<string, mixed>  $data
     * @return array{track_players: string, player_components: array<int, array<string, mixed>>, player_inputs: array<int, array<string, mixed>>, player_tiebreak: array<int, string>}
     */
    private function assemblePlayers(array $data): array
    {
        $mode = $data['track_players'] ?? PointRule::TRACK_OFF;

        if ($mode === PointRule::TRACK_OFF) {
            // Nothing kept. Leaving stale definitions behind would make switching
            // tracking back on resurrect columns the operator had removed.
            return [
                'track_players' => PointRule::TRACK_OFF,
                'player_components' => [],
                'player_inputs' => [],
                'player_tiebreak' => [],
            ];
        }

        $components = [];
        $inputs = [];

        foreach ($data['player_stats'] ?? [] as $stat) {
            $label = trim((string) ($stat['label'] ?? ''));

            if ($label === '') {
                continue;
            }

            // An existing row carries its key so renaming the label does not orphan
            // the figures already recorded against it.
            $key = trim((string) ($stat['key'] ?? ''));
            $key = $key !== '' ? $key : Str::slug($label, '_');

            if ($key === '' || array_key_exists($key, $components)) {
                continue;
            }

            $components[$key] = [
                'key' => $key,
                'label' => $label,
                'type' => PointRule::TYPE_PER_UNIT,
                'source' => $key,
                'value' => (float) ($stat['value'] ?? 0),
            ];

            $inputs[$key] = [
                'key' => $key,
                'label' => $label,
                'type' => 'integer',
                'min' => 0,
                'required' => (bool) ($stat['required'] ?? false),
            ];
        }

        $available = array_keys($components);

        $tiebreak = collect($data['player_tiebreak'] ?? [])
            ->filter(fn ($key) => $key !== null && $key !== '' && in_array($key, $available, true))
            ->unique()
            ->values()
            ->all();

        return [
            'track_players' => $mode,
            'player_components' => array_values($components),
            'player_inputs' => array_values($inputs),
            'player_tiebreak' => $tiebreak,
        ];
    }

    /**
     * Build the stored component list out of the answers the form collected.
     *
     * All the sport-specific language lives here and nowhere else. Everything
     * downstream reads generic components.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function assemble(array $data): array
    {
        $components = [];
        $inputs = [];

        $placementValues = collect($data['placement'] ?? [])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->mapWithKeys(fn ($value, $position) => [(string) (int) $position => (float) $value])
            ->all();

        if ($data['kind'] === PointRule::KIND_BATTLE_ROYALE) {
            $components[] = [
                'key' => 'placement', 'label' => 'Placement',
                'type' => PointRule::TYPE_TABLE, 'source' => 'placement',
                'values' => $placementValues,
            ];
            $components[] = [
                'key' => 'kills', 'label' => 'Kills',
                'type' => PointRule::TYPE_PER_UNIT, 'source' => 'kills',
                'value' => (float) ($data['kill_value'] ?? 1),
            ];
            $components[] = [
                'key' => 'wwcd', 'label' => 'WWCD',
                'type' => PointRule::TYPE_BONUS,
                'when' => ['source' => 'placement', 'equals' => 1],
                'value' => (float) ($data['wwcd_value'] ?? 0),
            ];

            $penaltyValues = collect($data['penalty'] ?? [])
                ->filter(fn ($value) => $value !== null && $value !== '')
                ->mapWithKeys(fn ($value, $present) => [(string) (int) $present => (float) $value])
                ->all();

            $components[] = [
                'key' => 'squad_penalty', 'label' => 'Squad Penalty',
                'type' => PointRule::TYPE_PENALTY_TABLE, 'source' => 'players_present',
                'values' => $penaltyValues,
                'disqualify_at' => (int) ($data['disqualify_at'] ?? 0),
            ];

            $inputs = [
                ['key' => 'placement', 'label' => 'Placement', 'type' => 'integer', 'min' => 1, 'required' => true, 'unique_in_match' => true],
                ['key' => 'kills', 'label' => 'Kills', 'type' => 'integer', 'min' => 0, 'required' => true],
                ['key' => 'players_present', 'label' => 'Players', 'type' => 'integer', 'min' => 0, 'max_from' => 'squad_size', 'required' => true],
            ];
        }

        if ($data['kind'] === PointRule::KIND_BRACKET) {
            $label = $data['series_label'] ?: 'Games Won';

            $components[] = [
                'key' => 'series_won', 'label' => $label,
                'type' => PointRule::TYPE_PER_UNIT, 'source' => 'series_won',
                'value' => 1,
            ];

            $inputs[] = [
                'key' => 'series_won', 'label' => $label, 'type' => 'integer',
                'min' => 0, 'max' => (int) ($data['series_max'] ?? 3), 'required' => true,
            ];

            // Worth nothing on its own, kept because it settles a tie and because it
            // is what a disputed result is argued over.
            if (! empty($data['track_points_scored'])) {
                $components[] = [
                    'key' => 'points_scored', 'label' => 'Points Scored',
                    'type' => PointRule::TYPE_PER_UNIT, 'source' => 'points_scored',
                    'value' => 0,
                ];

                $inputs[] = [
                    'key' => 'points_scored', 'label' => 'Total Points', 'type' => 'integer',
                    'min' => 0, 'required' => false,
                ];
            }
        }

        if ($data['kind'] === PointRule::KIND_RACE) {
            $components[] = [
                'key' => 'placement', 'label' => 'Placement',
                'type' => PointRule::TYPE_TABLE, 'source' => 'placement',
                'values' => $placementValues,
            ];

            $inputs[] = [
                'key' => 'finish_time', 'label' => 'Finish Time', 'type' => 'duration',
                'placeholder' => 'hh:mm:ss', 'required' => false,
            ];
        }

        if ($data['kind'] === PointRule::KIND_JUDGED) {
            $components[] = [
                'key' => 'judges', 'label' => 'Judges',
                'type' => PointRule::TYPE_AGGREGATE, 'source' => 'judges',
                'method' => $data['judge_method'] ?? 'trimmed_mean',
            ];

            $inputs[] = [
                'key' => 'judges', 'label' => 'Judge Marks', 'type' => 'marks',
                'count' => (int) ($data['judge_count'] ?? 5),
                'min' => 0, 'max' => (float) ($data['judge_max'] ?? 10),
                'step' => 0.5, 'required' => true,
            ];

            if (($data['deduction_value'] ?? null) !== null && (float) $data['deduction_value'] !== 0.0) {
                $components[] = [
                    'key' => 'deductions', 'label' => 'Deductions',
                    'type' => PointRule::TYPE_PER_UNIT, 'source' => 'faults',
                    'value' => (float) $data['deduction_value'],
                ];

                $inputs[] = [
                    'key' => 'faults', 'label' => 'Faults', 'type' => 'integer',
                    'min' => 0, 'required' => false,
                ];
            }
        }

        // Only keys that exist as components, in the order the operator chose.
        $available = array_column($components, 'key');

        $tiebreak = collect($data['tiebreak'] ?? [])
            ->filter(fn ($key) => $key !== null && $key !== '' && in_array($key, $available, true))
            ->unique()
            ->values()
            ->all();

        return [
            'name' => $data['name'],
            'kind' => $data['kind'],
            'squad_size' => $data['squad_size'] ?? null,
            'components' => $components,
            'inputs' => $inputs,
            'tiebreak' => $tiebreak,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ] + $this->assemblePlayers($data);
    }

    /**
     * Pull a stored profile back apart into the fields the form shows.
     *
     * @return array<string, mixed>
     */
    private function formData(PointRule $rule): array
    {
        $placement = $rule->component('placement')['values'] ?? [];
        $penalty = $rule->component('squad_penalty')['values'] ?? [];
        $series = $rule->component('series_won') ?? $rule->component('games_won') ?? $rule->component('sets_won');
        $judges = $rule->component('judges');

        return [
            'rule' => $rule,
            'mode' => $rule->exists ? 'edit' : 'create',
            'kinds' => PointRule::KINDS,
            'methods' => PointRule::AGGREGATE_METHODS,

            'placement' => $placement,
            'penalty' => $penalty,
            'killValue' => $rule->component('kills')['value'] ?? 1,
            'wwcdValue' => $rule->component('wwcd')['value'] ?? 0,
            'disqualifyAt' => $rule->component('squad_penalty')['disqualify_at'] ?? 0,

            'seriesLabel' => $series['label'] ?? 'Games Won',
            'seriesMax' => $rule->input('series_won')['max'] ?? $rule->input('games_won')['max'] ?? 3,
            'trackPointsScored' => $rule->component('points_scored') !== null,

            'judgeCount' => $rule->input('judges')['count'] ?? 5,
            'judgeMethod' => $judges['method'] ?? 'trimmed_mean',
            'judgeMax' => $rule->input('judges')['max'] ?? 10,
            'deductionValue' => $rule->component('deductions')['value'] ?? 0,

            // Every component that could be compared at a tie, for the ordered list.
            'tiebreakOptions' => collect($rule->components ?? [])
                ->mapWithKeys(fn (array $c) => [$c['key'] => $c['label'] ?? $c['key']])
                ->all(),

            /*
             | The player ledger, pulled back apart into editable rows. Read from
             | player_components and player_inputs only; the team side is never
             | consulted, so the two cannot be confused on screen either.
             */
            'trackPlayers' => $rule->track_players ?? PointRule::TRACK_OFF,
            'trackModes' => PointRule::TRACK_MODES,
            'playerStats' => collect($rule->player_components ?? [])
                ->map(fn (array $c) => [
                    'key' => $c['key'] ?? '',
                    'label' => $c['label'] ?? ($c['key'] ?? ''),
                    'value' => $c['value'] ?? 0,
                    'required' => (bool) ($rule->playerInput($c['key'] ?? '')['required'] ?? false),
                ])
                ->values()
                ->all(),
            'playerTiebreak' => $rule->player_tiebreak ?? [],
            'playerTiebreakOptions' => collect($rule->player_components ?? [])
                ->mapWithKeys(fn (array $c) => [$c['key'] => $c['label'] ?? $c['key']])
                ->all(),

            'liveTournaments' => $rule->exists
                ? $rule->tournaments()->whereIn('status', ['ongoing', 'completed', 'published'])->count()
                : 0,
        ];
    }

    /**
     * Sensible starting values so a new profile is not an empty grid.
     *
     * @return array<string, mixed>
     */
    private function defaultsFor(string $kind): array
    {
        if ($kind === PointRule::KIND_BATTLE_ROYALE) {
            return [
                'squad_size' => 4,
                'components' => [
                    ['key' => 'placement', 'label' => 'Placement', 'type' => PointRule::TYPE_TABLE, 'source' => 'placement',
                        'values' => ['1' => 10, '2' => 6, '3' => 5, '4' => 4, '5' => 3, '6' => 2, '7' => 1, '8' => 1]],
                    ['key' => 'kills', 'label' => 'Kills', 'type' => PointRule::TYPE_PER_UNIT, 'source' => 'kills', 'value' => 1],
                    ['key' => 'wwcd', 'label' => 'WWCD', 'type' => PointRule::TYPE_BONUS,
                        'when' => ['source' => 'placement', 'equals' => 1], 'value' => 0],
                    ['key' => 'squad_penalty', 'label' => 'Squad Penalty', 'type' => PointRule::TYPE_PENALTY_TABLE,
                        'source' => 'players_present', 'values' => ['3' => -1, '2' => -2, '1' => -3], 'disqualify_at' => 0],
                ],
                'tiebreak' => ['wwcd', 'placement', 'kills'],
            ];
        }

        if ($kind === PointRule::KIND_RACE) {
            return [
                'components' => [
                    ['key' => 'placement', 'label' => 'Placement', 'type' => PointRule::TYPE_TABLE, 'source' => 'placement',
                        'values' => ['1' => 25, '2' => 18, '3' => 15, '4' => 12, '5' => 10, '6' => 8, '7' => 6, '8' => 4, '9' => 2, '10' => 1]],
                ],
                'tiebreak' => ['placement'],
            ];
        }

        if ($kind === PointRule::KIND_JUDGED) {
            return [
                'components' => [
                    ['key' => 'judges', 'label' => 'Judges', 'type' => PointRule::TYPE_AGGREGATE, 'source' => 'judges', 'method' => 'trimmed_mean'],
                ],
                'tiebreak' => ['judges'],
            ];
        }

        return [
            'squad_size' => 5,
            'components' => [
                ['key' => 'series_won', 'label' => 'Games Won', 'type' => PointRule::TYPE_PER_UNIT, 'source' => 'series_won', 'value' => 1],
            ],
            'tiebreak' => ['series_won'],
        ];
    }

    private function resolveKind(?string $value): string
    {
        return array_key_exists((string) $value, PointRule::KINDS)
            ? (string) $value
            : PointRule::KIND_BATTLE_ROYALE;
    }
}
