<?php

namespace App\Http\Controllers\Admin\Tournament;

use App\Http\Controllers\Controller;
use App\Models\EventParticipant;
use App\Models\PointRule;
use App\Models\Tournament;
use App\Models\TournamentEntrant;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchEntrant;
use App\Models\TournamentMatchPlayer;
use App\Models\TournamentStage;
use App\Services\AdminLogger;
use App\Support\ParticipantOptions;
use App\Support\Tournament\PlayerStandingsCalculator;
use App\Support\Tournament\ScoringEngine;
use App\Support\Tournament\StageAdvancer;
use App\Support\Tournament\StandingsCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Fixtures and score entry.
 *
 * The score form is generated from the tournament's point rule, so there is one form
 * for every sport rather than one per sport. Nothing here branches on PUBG or
 * badminton; it reads the profile's declared inputs and draws them.
 */
class MatchController extends Controller
{
    private const PER_PAGE = 25;

    /**
     * @var array<string, array<string, string>>
     */
    private const TAB_INTRO = [
        'scheduled' => [
            'label' => 'Scheduled',
            'icon' => 'clipboard',
            'title' => 'Scheduled',
            'description' => 'Not played yet, in the order they are due.',
            'accent' => 'blue',
        ],
        'awaiting' => [
            'label' => 'Awaiting Result',
            'icon' => 'activity',
            'title' => 'Awaiting Result',
            'description' => 'Played, but the score has not been entered.',
            'accent' => 'amber',
        ],
        'completed' => [
            'label' => 'Completed',
            'icon' => 'shield',
            'title' => 'Completed',
            'description' => 'Scored and counted towards the standings.',
            'accent' => 'green',
        ],
    ];

    public function index(Request $request)
    {
        $tab = array_key_exists((string) $request->query('tab'), self::TAB_INTRO)
            ? (string) $request->query('tab')
            : 'scheduled';

        /*
         | A tournament has to be chosen. Several run at once, so a fixture list
         | without one named would mix two competitions into one table.
         */
        $tournaments = Tournament::query()
            ->whereIn('status', [Tournament::STATUS_ONGOING, Tournament::STATUS_COMPLETED, Tournament::STATUS_PUBLISHED])
            ->with('event:id,title')
            ->orderByDesc('id')
            ->get();

        $tournament = $this->resolveTournament($request, $tournaments);

        $counts = ['scheduled' => 0, 'awaiting' => 0, 'completed' => 0];
        $matches = null;
        $stages = collect();

        if ($tournament !== null) {
            $base = fn () => TournamentMatch::where('tournament_id', $tournament->id);

            $counts = [
                'scheduled' => $base()->where('status', TournamentMatch::STATUS_SCHEDULED)->count(),
                'awaiting' => $base()->where('status', TournamentMatch::STATUS_AWAITING)->count(),
                'completed' => $base()->whereIn('status', [
                    TournamentMatch::STATUS_COMPLETED,
                    TournamentMatch::STATUS_WALKOVER,
                ])->count(),
            ];

            $stages = $tournament->stages()->get();

            $stageId = (string) $request->query('stage', '');
            $groupId = (string) $request->query('group', '');

            $matches = $base()
                ->with(['stage:id,name,type', 'group:id,name', 'entrants.entrant.registration:id,team_name,reference'])
                ->where('status', $this->statusFor($tab))
                ->when($tab === 'completed', fn ($q) => $q->orWhere(function ($inner) use ($tournament) {
                    $inner->where('tournament_id', $tournament->id)
                        ->where('status', TournamentMatch::STATUS_WALKOVER);
                }))
                ->when($stageId !== '', fn ($q) => $q->where('tournament_stage_id', $stageId))
                ->when($groupId !== '', fn ($q) => $q->where('tournament_group_id', $groupId))
                ->orderBy('scheduled_at')
                ->orderBy('round')
                ->orderBy('position')
                ->paginate(self::PER_PAGE)
                ->withQueryString();
        }

        return view('admin.tournament.matches', [
            'tabs' => collect(self::TAB_INTRO)
                ->map(fn (array $tab, string $slug) => [
                    'label' => $tab['label'],
                    'icon' => $tab['icon'],
                    'count' => $counts[$slug] ?? 0,
                ])
                ->all(),

            'activeTab' => $tab,
            'intro' => self::TAB_INTRO[$tab],
            'route' => 'admin.tournaments.matches',
            'tournaments' => $tournaments,
            'tournament' => $tournament,
            'stages' => $stages,
            'matches' => $matches,
            'filters' => [
                'stage' => (string) $request->query('stage', ''),
                'group' => (string) $request->query('group', ''),
            ],
            'canScore' => $request->user()->hasPermission('tournaments.matches.score'),

            /*
             | Changing which map a fixture is on, or when it starts, is deciding what
             | the fixture is rather than what its result was. That is the permission
             | that already owns generating and discarding a draw, so a referee who may
             | only enter scores does not get it.
             */
            'canEditFixture' => $request->user()->hasPermission('tournaments.matches.generate'),

            // Read live rather than from the tournament's frozen copy: this is a list
            // of suggestions for a dropdown, not a rule the tournament was run under.
            'mapPool' => TournamentSettingsController::mapPool(),
        ]);
    }

    /**
     * Change which map a fixture is on, and when it starts.
     *
     * Both were written once when the draw was generated and then had nowhere to be
     * corrected. The map came from the rotation in the tournament's frozen settings,
     * cycled by match number, so an organiser who wanted Sanhok for match three had
     * no way to say so: not through the settings screen, because the tournament had
     * already frozen its copy, and not by discarding the draw, because that reads the
     * same frozen copy again. The comment in the scheduler even claimed every time
     * could be edited afterwards, which was never true.
     *
     * Allowed on a fixture that has been played. Neither field is part of the result:
     * they describe where and when it happened, and a wrong map name is worth
     * correcting after the fact rather than being preserved because it is too late.
     */
    public function updateFixture(Request $request, TournamentMatch $match)
    {
        $data = $request->validate([
            'map' => ['nullable', 'string', 'max:60'],
            'scheduled_at' => ['nullable', 'date'],
        ], [
            'scheduled_at.date' => 'Give a real date and time, or leave it empty.',
        ]);

        $match->loadMissing('tournament');

        $before = $match->only(['map', 'scheduled_at']);

        $match->fill([
            'map' => filled($data['map'] ?? null) ? trim($data['map']) : null,
            'scheduled_at' => filled($data['scheduled_at'] ?? null) ? $data['scheduled_at'] : null,
        ]);

        if (! $match->isDirty()) {
            return back()->with('status', sprintf('Nothing was changed on %s.', $match->label()));
        }

        $changed = array_keys($match->getDirty());

        $match->save();

        AdminLogger::activity('tournaments.matches.generate', sprintf(
            'Updated %s in %s: %s.',
            $match->label(),
            $match->tournament?->name ?? 'a tournament',
            implode(', ', $changed),
        ));

        AdminLogger::audit(
            $match,
            'tournament.fixture_updated',
            array_intersect_key($before, array_flip($changed)),
            array_intersect_key($match->only(['map', 'scheduled_at']), array_flip($changed)),
        );

        return back()->with('status', sprintf('%s updated.', $match->label()));
    }

    /**
     * The score entry form for one fixture, built from the profile.
     */
    public function edit(Request $request, TournamentMatch $match)
    {
        $match->load([
            'tournament.pointRule',
            'stage:id,name,type',
            'group:id,name',
            'entrants.entrant.registration:id,team_name,reference',
            'entrants.players',
            'proofs',
        ]);

        $rule = $match->tournament->pointRule;

        return view('admin.tournament.score', [
            'match' => $match,
            'tournament' => $match->tournament,
            'rule' => $rule,
            'inputs' => $rule?->inputs ?? [],
            'lines' => $match->entrants->sortBy(fn ($line) => [$line->slot ?? 99, $line->id])->values(),
            'requiresProof' => (bool) $match->tournament->setting('require_proof', false),
            'canScore' => $request->user()->hasPermission('tournaments.matches.score'),

            // The player ledger. Empty arrays when the profile has tracking off, which
            // is what keeps the form identical to what it is today for those profiles.
            'playerInputs' => $rule?->tracksPlayers() ? ($rule->player_inputs ?? []) : [],
            'rosters' => $this->rostersFor($match, $rule),
            'recorded' => $this->recordedPlayersFor($match),
        ]);
    }

    /**
     * Who each entrant is allowed to field, by entrant id.
     *
     * Only participants on that entrant's own registration, and only those whose role
     * is `player`. A manager is not fielded, and a person on another team's
     * registration cannot appear here at all.
     *
     * @return array<int, \Illuminate\Support\Collection<int, EventParticipant>>
     */
    private function rostersFor(TournamentMatch $match, ?PointRule $rule): array
    {
        if ($rule === null || ! $rule->tracksPlayers()) {
            return [];
        }

        $registrationIds = $match->entrants
            ->pluck('entrant.event_registration_id')
            ->filter()
            ->unique()
            ->all();

        if ($registrationIds === []) {
            return [];
        }

        // playing(), not role = 'player': a manager who also holds a place is on
        // the roster, and leaving them out here would keep them off every match
        // line-up while they are named on the entry.
        $byRegistration = EventParticipant::query()
            ->whereIn('event_registration_id', $registrationIds)
            ->playing()
            ->orderBy('full_name')
            ->get(['id', 'event_registration_id', 'full_name', 'ign_player_id'])
            ->groupBy('event_registration_id');

        $rosters = [];

        foreach ($match->entrants as $line) {
            $registrationId = $line->entrant?->event_registration_id;

            if ($line->tournament_entrant_id === null || $registrationId === null) {
                continue;
            }

            $rosters[$line->tournament_entrant_id] = $byRegistration->get($registrationId, collect());
        }

        return $rosters;
    }

    /**
     * Personal figures already on file, as [entrant id][participant id].
     *
     * @return array<int, array<int, \App\Models\TournamentMatchPlayer>>
     */
    private function recordedPlayersFor(TournamentMatch $match): array
    {
        $recorded = [];

        foreach ($match->entrants as $line) {
            if ($line->tournament_entrant_id === null) {
                continue;
            }

            $recorded[$line->tournament_entrant_id] = $line->players
                ->keyBy('event_participant_id')
                ->all();
        }

        return $recorded;
    }

    /**
     * Save a result and rebuild the standings in the same request.
     */
    public function update(
        Request $request,
        TournamentMatch $match,
        ScoringEngine $engine,
        StandingsCalculator $calculator,
        StageAdvancer $advancer,
        PlayerStandingsCalculator $playerCalculator,
    ) {
        $match->load(['tournament.pointRule', 'entrants.entrant']);
        $tournament = $match->tournament;
        $rule = $tournament->pointRule;

        if ($rule === null) {
            return back()->withErrors(['score' => 'This tournament has no point rule, so nothing can be scored.']);
        }

        if ($tournament->isPublished()) {
            return back()->withErrors([
                'score' => 'The podium for this tournament is published. Withdraw it before correcting a score.',
            ]);
        }

        $request->validate(['proof' => ['nullable', 'image', 'max:5120']]);

        /*
         | When the tournament demands evidence, a fixture cannot be closed without it.
         | Checked before anything is written, and satisfied by a screenshot already on
         | file so a correction does not force the operator to upload it twice.
         */
        if ($tournament->setting('require_proof', false)
            && ! $request->hasFile('proof')
            && $match->proofs()->doesntExist()) {
            return back()->withInput()->withErrors([
                'proof' => 'This tournament requires a screenshot of the result before a fixture can be closed.',
            ]);
        }

        $data = $this->validateScore($request, $match, $rule);

        /*
         | The player ledger is validated separately and cannot stop the team result
         | from being saved unless the profile says players are required. That is the
         | point of it being optional: an operator who skips these rows still gets a
         | correct podium.
         */
        $players = $this->validatePlayers($request, $match, $rule);

        if ($request->hasFile('proof')) {
            $file = $request->file('proof');

            $match->proofs()->create([
                'path' => $file->store('tournament-proofs', 'public'),
                'original_name' => $file->getClientOriginalName(),
                'uploaded_by' => $request->user()->id,
            ]);
        }

        DB::transaction(function () use ($match, $rule, $data, $players, $engine, $request) {
            $before = $match->entrants->mapWithKeys(
                fn ($line) => [$line->tournament_entrant_id => $line->inputs],
            )->all();

            $scored = $rule->kind === PointRule::KIND_RACE
                ? $engine->scoreRace($rule, $data['lines'])
                : collect($data['lines'])
                    ->map(fn (array $inputs) => $engine->score($rule, $inputs) + ['inputs' => $inputs])
                    ->all();

            foreach ($match->entrants as $line) {
                $result = $scored[$line->tournament_entrant_id] ?? null;

                if ($result === null) {
                    continue;
                }

                $line->update([
                    'inputs' => $result['inputs'],
                    'points' => $result['points'],
                    'component_points' => $result['components'],
                    'component_counts' => $result['counts'],
                    'is_disqualified' => $result['disqualified'],
                ]);

                $this->savePlayers($line, $rule, $players[$line->tournament_entrant_id] ?? [], $engine, $request);
            }

            $match->update([
                'status' => TournamentMatch::STATUS_COMPLETED,
                'winner_entrant_id' => $this->decideWinner($match, $scored),
                'resolution' => null,
                'reason' => null,
                'scored_by' => $request->user()->id,
                'scored_at' => now(),
            ]);

            AdminLogger::audit($match, 'tournament.match_scored', $before, $data['lines']);

            /*
             | The player ledger gets its own audit entry rather than being folded into
             | the one above. A dispute is about either a team's placement or a person's
             | kills, never both at once, and two records read more plainly than one
             | nested blob.
             */
            if ($players !== []) {
                AdminLogger::audit($match, 'tournament.player_scores_recorded', null, [
                    'players' => collect($players)
                        ->map(fn (array $rows) => collect($rows)
                            ->map(fn (array $row) => [
                                'took_part' => $row['took_part'],
                                'inputs' => $row['inputs'],
                            ])
                            ->all())
                        ->all(),
                ]);
            }
        });

        // Recomputed inside this request rather than queued: the queue has no worker
        // that is certain to be running, and a referee has to see the table move.
        $calculator->recalculate($tournament->fresh());
        $advancer->advance($match->fresh(['stage']));

        // The second ledger, rebuilt separately. It reads its own rows and writes its
        // own table, so a failure here could not corrupt the team standings above.
        $playerCalculator->recalculate($tournament->fresh());

        AdminLogger::activity('tournaments.matches.score', sprintf(
            'Scored %s in %s.',
            $match->label(),
            $tournament->name,
        ));

        return redirect()
            ->route('admin.tournaments.matches', ['tournament' => $tournament->id, 'tab' => 'completed'])
            ->with('status', sprintf('%s scored. Standings updated.', $match->label()));
    }

    /**
     * Blank a result and put the fixture back to Scheduled.
     *
     * Correcting a score has always been possible: saving the form again rewrites it.
     * What was missing is the case where nothing should have been entered at all, and
     * a wrong figure typed once was enough to freeze the whole tournament. Generating
     * the first draw moves a tournament to Ongoing, which locks its entrants; the
     * draw could be discarded to get back, but discarding is refused the moment
     * anything is scored. So one mistaken score was a dead end.
     *
     * Standings need no surgery. They are a stored table that StandingsCalculator
     * deletes and rebuilds from the match rows on every save, so once this fixture
     * holds no result the rebuild simply leaves it out.
     *
     * Three things are refused rather than guessed at, because StageAdvancer only
     * moves competitors forward and has no counterpart that unwinds them:
     *
     * A published podium. The same refusal scoring gives, for the same reason.
     *
     * A result that has been built on. If the winner of this fixture went on to play
     * a fixture that is itself settled, clearing this one would leave that result
     * describing a match between competitors who never earned their place.
     *
     * A later stage that is already drawn. That draw was built from these standings,
     * so changing them underneath it would leave a bracket nobody qualified for.
     */
    public function clear(
        Request $request,
        TournamentMatch $match,
        StandingsCalculator $calculator,
        PlayerStandingsCalculator $playerCalculator,
    ) {
        $match->load(['tournament', 'stage', 'entrants']);
        $tournament = $match->tournament;

        if (! $match->isSettled()) {
            return back()->withErrors(['score' => sprintf(
                '%s has no result to clear.',
                $match->label(),
            )]);
        }

        if ($tournament->isPublished()) {
            return back()->withErrors(['score' => 'The podium for this tournament is published. Withdraw it before clearing a result.']);
        }

        if ($blocked = $this->clearRefusal($match)) {
            return back()->withErrors(['score' => $blocked]);
        }

        $label = $match->label();
        $stage = $match->stage;
        $reopenedStage = $stage !== null && $stage->status === TournamentStage::STATUS_COMPLETED;

        // Read before anything is blanked: afterwards the match no longer says who
        // won, so there is no way to work out who it knocked out.
        $loserIds = $match->entrants
            ->pluck('tournament_entrant_id')
            ->filter()
            ->reject(fn ($id) => (int) $id === (int) $match->winner_entrant_id)
            ->all();

        DB::transaction(function () use ($match, $stage, $reopenedStage, $loserIds, $tournament, $request) {
            $before = $match->entrants->mapWithKeys(
                fn ($line) => [$line->tournament_entrant_id => $line->inputs],
            )->all();

            // Take the competitors back out of whatever this fixture fed. Checked
            // above to be unsettled, so nothing is being unpicked from a played match.
            $this->unseat($match->winner_to_match_id, $match->winner_to_slot);
            $this->unseat($match->loser_to_match_id, $match->loser_to_slot);

            foreach ($match->entrants as $line) {
                // The personal figures go with the result. They describe a match that
                // is no longer recorded as having been played.
                $line->players()->delete();

                $line->update([
                    'inputs' => null,
                    'points' => 0,
                    'component_points' => null,
                    'component_counts' => null,
                    'is_disqualified' => false,
                ]);
            }

            $match->update([
                'status' => TournamentMatch::STATUS_SCHEDULED,
                'winner_entrant_id' => null,
                'resolution' => null,
                'reason' => null,
                'scored_by' => null,
                'scored_at' => null,
            ]);

            /*
             | Whoever this fixture knocked out is back in. Only those it knocked out:
             | a competitor an earlier round eliminated stays eliminated, and one who
             | was disqualified or withdrew is not touched at all, because neither was
             | a consequence of this score.
             */
            if ($loserIds !== []) {
                TournamentEntrant::whereIn('id', $loserIds)
                    ->where('status', TournamentEntrant::STATUS_ELIMINATED)
                    ->update(['status' => TournamentEntrant::STATUS_ACTIVE]);
            }

            if ($reopenedStage && $stage !== null) {
                $stage->update(['status' => TournamentStage::STATUS_ONGOING]);

                /*
                 | Closing a stage eliminates everybody its standings did not advance.
                 | The stage is being played again, so that has to come undone. Scoped
                 | to competitors who actually appear in this stage's fixtures, so an
                 | earlier stage's casualties are left where they are.
                 */
                $inThisStage = TournamentMatchEntrant::query()
                    ->whereHas('match', fn ($query) => $query->where('tournament_stage_id', $stage->id))
                    ->whereNotNull('tournament_entrant_id')
                    ->pluck('tournament_entrant_id');

                TournamentEntrant::whereIn('id', $inThisStage)
                    ->where('status', TournamentEntrant::STATUS_ELIMINATED)
                    ->update(['status' => TournamentEntrant::STATUS_ACTIVE]);
            }

            // A finished tournament has something left to play again.
            if ($tournament->status === Tournament::STATUS_COMPLETED) {
                $tournament->update([
                    'status' => Tournament::STATUS_ONGOING,
                    'completed_at' => null,
                ]);
            }

            AdminLogger::audit($match, 'tournament.match_result_cleared', $before, null);
        });

        // Both tables are counted from the match rows, so with this fixture holding
        // no result its contribution simply disappears.
        $calculator->recalculate($tournament->fresh());
        $playerCalculator->recalculate($tournament->fresh());

        AdminLogger::activity('tournaments.matches.score', sprintf(
            'Cleared the result of %s in %s.',
            $label,
            $tournament->name,
        ));

        return redirect()
            ->route('admin.tournaments.matches', ['tournament' => $tournament->id, 'tab' => 'scheduled'])
            ->with('status', sprintf(
                '%s cleared and put back to Scheduled. Standings worked out again without it.%s',
                $label,
                $tournament->matches()->whereNotNull('scored_at')->exists()
                    ? ''
                    : ' Nothing is scored in this tournament now, so its draw can be discarded again.',
            ));
    }

    /**
     * Why this result cannot be cleared, or null when it can.
     */
    private function clearRefusal(TournamentMatch $match): ?string
    {
        foreach ([$match->winner_to_match_id, $match->loser_to_match_id] as $onwardId) {
            if ($onwardId === null) {
                continue;
            }

            $onward = TournamentMatch::find($onwardId);

            if ($onward?->isSettled()) {
                return sprintf(
                    '%s fed %s, which has been played. Clear %s first, or this result would be taken away from underneath it.',
                    $match->label(),
                    $onward->label(),
                    $onward->label(),
                );
            }
        }

        $stage = $match->stage;

        if ($stage === null) {
            return null;
        }

        $drawnLater = $stage->tournament
            ->stages()
            ->where('sequence', '>', $stage->sequence)
            ->whereNotNull('drawn_at')
            ->first();

        if ($drawnLater !== null) {
            return sprintf(
                '%s was drawn from this stage\'s standings. Discard that draw before changing what it was built on.',
                $drawnLater->name,
            );
        }

        return null;
    }

    /**
     * Empty the slot this fixture seated somebody into.
     *
     * The line itself stays, because the draw wrote it to describe a place in the
     * bracket. Only who is sitting in it is removed.
     */
    private function unseat(?int $matchId, ?int $slot): void
    {
        if ($matchId === null || $slot === null) {
            return;
        }

        TournamentMatchEntrant::query()
            ->where('tournament_match_id', $matchId)
            ->where('slot', $slot)
            ->update(['tournament_entrant_id' => null]);
    }

    /**
     * Record a walkover, forfeit, disqualification or withdrawal.
     */
    public function resolve(
        Request $request,
        TournamentMatch $match,
        StandingsCalculator $calculator,
        StageAdvancer $advancer,
        PlayerStandingsCalculator $playerCalculator,
    ) {
        $match->load(['tournament', 'entrants.entrant']);

        $data = $request->validate([
            'resolution' => ['required', Rule::in([
                TournamentMatch::RESOLUTION_WALKOVER,
                TournamentMatch::RESOLUTION_FORFEIT,
                TournamentMatch::RESOLUTION_DISQUALIFICATION,
                TournamentMatch::RESOLUTION_WITHDRAWAL,
            ])],
            'winner_entrant_id' => ['nullable', 'exists:tournament_entrants,id'],
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'reason.required' => 'Say why. A walkover or a disqualification without a reason cannot be defended later.',
        ]);

        $loserIds = $match->entrants
            ->pluck('tournament_entrant_id')
            ->filter()
            ->reject(fn ($id) => (string) $id === (string) ($data['winner_entrant_id'] ?? ''))
            ->all();

        DB::transaction(function () use ($match, $data, $loserIds, $request) {
            $match->update([
                'status' => TournamentMatch::STATUS_WALKOVER,
                'winner_entrant_id' => $data['winner_entrant_id'] ?: null,
                'resolution' => $data['resolution'],
                'reason' => $data['reason'],
                'scored_by' => $request->user()->id,
                'scored_at' => now(),
            ]);

            /*
             | A disqualification or a withdrawal is about the competitor, not only this
             | fixture, so their entrant row changes too and their remaining matches
             | become walkovers for the opponent. A plain walkover is about this fixture
             | alone and leaves the competitor able to play again.
             */
            if (in_array($data['resolution'], [
                TournamentMatch::RESOLUTION_DISQUALIFICATION,
                TournamentMatch::RESOLUTION_WITHDRAWAL,
            ], true)) {
                $status = $data['resolution'] === TournamentMatch::RESOLUTION_DISQUALIFICATION
                    ? TournamentEntrant::STATUS_DISQUALIFIED
                    : TournamentEntrant::STATUS_WITHDRAWN;

                TournamentEntrant::whereIn('id', $loserIds)->update([
                    'status' => $status,
                    'reason' => $data['reason'],
                ]);
            }

            AdminLogger::audit($match, 'tournament.match_resolved', null, [
                'resolution' => $data['resolution'],
                'reason' => $data['reason'],
                'winner' => $data['winner_entrant_id'] ?? null,
            ]);
        });

        $calculator->recalculate($match->tournament->fresh());

        /*
         | Rebuilt here too, because disqualifying a squad has to mark its players'
         | leaderboard rows. Their own figures stay: what a person did is still what
         | they did, and deleting it would make the record disagree with the matches.
         */
        $playerCalculator->recalculate($match->tournament->fresh());
        $advancer->advance($match->fresh(['stage']));

        AdminLogger::activity('tournaments.matches.score', sprintf(
            'Recorded a %s for %s in %s.',
            $data['resolution'],
            $match->label(),
            $match->tournament->name,
        ));

        return redirect()
            ->route('admin.tournaments.matches', ['tournament' => $match->tournament_id, 'tab' => 'completed'])
            ->with('status', sprintf('%s recorded as a %s.', $match->label(), $data['resolution']));
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * Validate what was typed against the profile's own input definitions.
     *
     * @return array{lines: array<int, array<string, mixed>>}
     */
    private function validateScore(Request $request, TournamentMatch $match, PointRule $rule): array
    {
        $definitions = collect($rule->inputs ?? [])->keyBy('key');
        $submitted = $request->input('lines', []);
        $lines = [];
        $errors = [];
        $seenPlacements = [];

        foreach ($match->entrants as $line) {
            $entrantId = $line->tournament_entrant_id;

            if ($entrantId === null) {
                continue;
            }

            $raw = $submitted[$entrantId] ?? [];
            $clean = [];

            foreach ($definitions as $key => $definition) {
                $value = $raw[$key] ?? null;

                if ($definition['type'] === 'marks') {
                    $marks = collect(is_array($value) ? $value : [])
                        ->filter(fn ($mark) => $mark !== null && $mark !== '')
                        ->map(fn ($mark) => (float) $mark)
                        ->values()
                        ->all();

                    if (($definition['required'] ?? false) && $marks === []) {
                        $errors["lines.{$entrantId}.{$key}"] = sprintf(
                            '%s needs at least one mark for %s.',
                            $definition['label'] ?? $key,
                            $line->entrant?->displayName() ?? 'this competitor',
                        );
                    }

                    $clean[$key] = $marks;

                    continue;
                }

                if ($value === null || $value === '') {
                    if ($definition['required'] ?? false) {
                        $errors["lines.{$entrantId}.{$key}"] = sprintf(
                            '%s is needed for %s.',
                            $definition['label'] ?? $key,
                            $line->entrant?->displayName() ?? 'this competitor',
                        );
                    }

                    $clean[$key] = null;

                    continue;
                }

                if ($definition['type'] === 'integer') {
                    $number = (int) $value;

                    if (isset($definition['min']) && $number < $definition['min']) {
                        $errors["lines.{$entrantId}.{$key}"] = sprintf(
                            '%s cannot be below %s.',
                            $definition['label'] ?? $key,
                            $definition['min'],
                        );
                    }

                    // Players present cannot exceed a full squad: somebody has typed
                    // the wrong number, and letting it through would cancel a penalty.
                    $max = isset($definition['max_from']) && $definition['max_from'] === 'squad_size'
                        ? $rule->squad_size
                        : ($definition['max'] ?? null);

                    if ($max !== null && $number > $max) {
                        $errors["lines.{$entrantId}.{$key}"] = sprintf(
                            '%s cannot be above %s.',
                            $definition['label'] ?? $key,
                            $max,
                        );
                    }

                    if (! empty($definition['unique_in_match'])) {
                        if (in_array($number, $seenPlacements, true)) {
                            $errors["lines.{$entrantId}.{$key}"] = sprintf(
                                'Two competitors cannot both be %s %s.',
                                strtolower($definition['label'] ?? $key),
                                $number,
                            );
                        }

                        $seenPlacements[] = $number;
                    }

                    $clean[$key] = $number;

                    continue;
                }

                $clean[$key] = $value;
            }

            $lines[$entrantId] = $clean;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'score' => 'This fixture has no competitors yet, so there is nothing to score.',
            ]);
        }

        return ['lines' => $lines];
    }

    /**
     * Check the personal figures, without letting them decide the team result.
     *
     * Returns [entrant id][participant id] => ['took_part' => bool, 'inputs' => array].
     * A profile with tracking off returns an empty array and nothing further happens.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function validatePlayers(Request $request, TournamentMatch $match, PointRule $rule): array
    {
        if (! $rule->tracksPlayers()) {
            return [];
        }

        $definitions = collect($rule->player_inputs ?? [])->keyBy('key');

        if ($definitions->isEmpty()) {
            return [];
        }

        $submitted = $request->input('players', []);
        $rosters = $this->rostersFor($match, $rule);
        $onFile = $this->recordedPlayersFor($match);
        $errors = [];
        $out = [];

        /*
         | A player belongs to one entrant for the whole tournament. Somebody appearing
         | for two teams is either a typo or a roster breach, and either way the figures
         | would be meaningless, so it is refused and the other entrant is named.
         */
        $claimed = TournamentMatchPlayer::query()
            ->whereHas('matchEntrant', fn ($q) => $q
                ->whereHas('match', fn ($m) => $m->where('tournament_id', $match->tournament_id))
                ->where('tournament_match_id', '!=', $match->id))
            ->with('matchEntrant.entrant.registration:id,team_name')
            ->get()
            ->keyBy('event_participant_id');

        foreach ($match->entrants as $line) {
            $entrantId = $line->tournament_entrant_id;

            if ($entrantId === null) {
                continue;
            }

            $roster = $rosters[$entrantId] ?? collect();
            $rows = [];

            foreach ($roster as $participant) {
                $raw = $submitted[$entrantId][$participant->id] ?? [];
                $tookPart = (bool) ($raw['took_part'] ?? false);

                $held = $claimed->get($participant->id);

                if ($held !== null
                    && (int) $held->matchEntrant?->tournament_entrant_id !== (int) $entrantId) {
                    $errors["players.{$entrantId}.{$participant->id}"] = sprintf(
                        '%s is already recorded for %s in this tournament, so they cannot also play for %s.',
                        $participant->full_name,
                        $held->matchEntrant?->entrant?->displayName() ?? 'another competitor',
                        $line->entrant?->displayName() ?? 'this competitor',
                    );

                    continue;
                }

                $clean = [];
                $anyFigure = false;

                foreach ($definitions as $key => $definition) {
                    $value = $raw[$key] ?? null;

                    if ($value === null || $value === '') {
                        /*
                         | Required means required only when the profile says players are
                         | required. On `optional` an empty row is simply an empty row,
                         | because the whole ledger is allowed to be left alone.
                         */
                        if ($rule->requiresPlayers() && ($definition['required'] ?? false)) {
                            $errors["players.{$entrantId}.{$participant->id}.{$key}"] = sprintf(
                                '%s is needed for %s.',
                                $definition['label'] ?? $key,
                                $participant->full_name,
                            );
                        }

                        $clean[$key] = null;

                        continue;
                    }

                    $number = (int) $value;

                    if (isset($definition['min']) && $number < $definition['min']) {
                        $errors["players.{$entrantId}.{$participant->id}.{$key}"] = sprintf(
                            '%s cannot be below %s for %s.',
                            $definition['label'] ?? $key,
                            $definition['min'],
                            $participant->full_name,
                        );
                    }

                    $clean[$key] = $number;
                    $anyFigure = true;
                }

                /*
                 | A row is written only when there is something to say: a figure was
                 | typed, or a row already exists and is being corrected.
                 |
                 | Without this, saving a lobby of sixteen would write sixty-four rows
                 | claiming every player did or did not play, when the operator never
                 | opened those panels and made no such decision. No row means nobody
                 | recorded anything, which is the truth.
                 */
                if (! $anyFigure && ! array_key_exists($participant->id, $onFile[$entrantId] ?? [])) {
                    continue;
                }

                $rows[$participant->id] = [
                    'took_part' => $tookPart,
                    'inputs' => $anyFigure ? $clean : null,
                ];
            }

            if ($rows !== []) {
                $out[$entrantId] = $rows;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /**
     * Write one entrant's player rows.
     *
     * Scored with `scorePlayer`, which reads `player_components`. Nothing computed
     * here is added to the team line that owns these rows.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function savePlayers(
        $line,
        PointRule $rule,
        array $rows,
        ScoringEngine $engine,
        Request $request,
    ): void {
        foreach ($rows as $participantId => $row) {
            $inputs = $row['inputs'];

            $result = $inputs === null
                ? ['points' => 0.0, 'components' => [], 'counts' => []]
                : $engine->scorePlayer($rule, $inputs);

            $line->players()->updateOrCreate(
                ['event_participant_id' => $participantId],
                [
                    'took_part' => $row['took_part'],
                    'inputs' => $inputs,
                    'points' => $result['points'],
                    'component_points' => $result['components'],
                    'component_counts' => $result['counts'],
                    'recorded_by' => $request->user()->id,
                ],
            );
        }
    }

    /**
     * Who won.
     *
     * A two-sided fixture has a winner; a lobby or a heat does not, and forcing one
     * would put a meaningless name in the bracket column.
     *
     * @param  array<int, array<string, mixed>>  $scored
     */
    private function decideWinner(TournamentMatch $match, array $scored): ?int
    {
        if ($match->round === null) {
            return null;
        }

        $best = null;
        $bestPoints = null;

        foreach ($scored as $entrantId => $result) {
            if ($bestPoints === null || $result['points'] > $bestPoints) {
                $bestPoints = $result['points'];
                $best = (int) $entrantId;

                continue;
            }

            // A level series has no winner, and saying so is better than picking the
            // first row.
            if ($result['points'] === $bestPoints) {
                $best = null;
            }
        }

        return $best;
    }

    private function statusFor(string $tab): string
    {
        return match ($tab) {
            'awaiting' => TournamentMatch::STATUS_AWAITING,
            'completed' => TournamentMatch::STATUS_COMPLETED,
            default => TournamentMatch::STATUS_SCHEDULED,
        };
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Tournament>  $tournaments
     */
    private function resolveTournament(Request $request, $tournaments): ?Tournament
    {
        $requested = (string) $request->query('tournament', '');

        if ($requested !== '') {
            return $tournaments->firstWhere('id', (int) $requested);
        }

        return $tournaments->first();
    }
}
