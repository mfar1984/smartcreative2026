<?php

namespace Tests\Feature\Tournament;

use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\PointRule;
use App\Models\Tournament;
use App\Models\TournamentEntrant;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchPlayer;
use App\Models\TournamentStage;
use App\Support\ParticipantOptions;
use App\Support\Tournament\Draw\DrawFactory;
use App\Support\Tournament\PlayerStandingsCalculator;
use App\Support\Tournament\Rescorer;
use App\Support\Tournament\ScoringEngine;
use App\Support\Tournament\StandingsCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Personal player scoring, and the wall between it and the team's.
 *
 * The organiser's condition was blunt: team points must not mix with personal
 * points. Most of what is checked here is that nothing leaks across, because that
 * separation is what allows the whole feature to be optional. If somebody later
 * makes player figures contribute to a squad total, these are the tests that break.
 */
class PlayerScoringTest extends TestCase
{
    use RefreshDatabase;

    private function rule(string $track = PointRule::TRACK_OPTIONAL): PointRule
    {
        return PointRule::create([
            'name' => 'BR with players ' . uniqid(),
            'kind' => PointRule::KIND_BATTLE_ROYALE,
            'squad_size' => 4,
            'track_players' => $track,

            // Team ledger.
            'components' => [
                ['key' => 'placement', 'label' => 'Placement', 'type' => 'table', 'source' => 'placement',
                    'values' => ['1' => 10, '2' => 6, '3' => 5, '4' => 4]],
                ['key' => 'kills', 'label' => 'Kills', 'type' => 'per_unit', 'source' => 'kills', 'value' => 1],
                ['key' => 'wwcd', 'label' => 'WWCD', 'type' => 'bonus',
                    'when' => ['source' => 'placement', 'equals' => 1], 'value' => 0],
            ],
            'inputs' => [
                ['key' => 'placement', 'label' => 'Placement', 'type' => 'integer', 'min' => 1, 'required' => true],
                ['key' => 'kills', 'label' => 'Kills', 'type' => 'integer', 'min' => 0, 'required' => true],
            ],
            'tiebreak' => ['wwcd', 'placement', 'kills'],

            // Player ledger. Shares only the word "kills"; the value and the table it
            // is written to are entirely separate.
            'player_components' => [
                ['key' => 'kills', 'label' => 'Kills', 'type' => 'per_unit', 'source' => 'kills', 'value' => 1],
                ['key' => 'damage', 'label' => 'Damage', 'type' => 'per_unit', 'source' => 'damage', 'value' => 0],
            ],
            'player_inputs' => [
                ['key' => 'kills', 'label' => 'Kills', 'type' => 'integer', 'min' => 0, 'required' => true],
                ['key' => 'damage', 'label' => 'Damage', 'type' => 'integer', 'min' => 0, 'required' => false],
            ],
            'player_tiebreak' => ['kills', 'damage'],
        ]);
    }

    /**
     * A tournament of four squads of four, with a lobby drawn and named players on
     * every registration.
     *
     * @return array{0: Tournament, 1: TournamentStage}
     */
    private function build(PointRule $rule): array
    {
        $event = Event::create([
            'slug' => 'pubg-' . uniqid(),
            'title' => 'PUBG Test',
            'category' => 'E-Sport',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => 'published',
            'registration_mode' => 'manager',
            'seats_total' => 100,
        ]);

        $tournament = Tournament::create([
            'event_id' => $event->id,
            'name' => 'PUBG Players',
            'format' => Tournament::FORMAT_BATTLE_ROYALE,
            'point_rule_id' => $rule->id,
            'status' => Tournament::STATUS_SETUP,
            'seeding_method' => Tournament::SEEDING_MANUAL,
            'settings' => ['buffer_minutes' => 15, 'map_rotation' => ['Erangel']],
        ]);

        for ($i = 1; $i <= 4; $i++) {
            $registration = EventRegistration::create([
                'event_id' => $event->id,
                'reference' => 'R-' . uniqid() . $i,
                'mode' => 'team',
                'team_name' => 'Team ' . $i,
                'status' => EventRegistration::STATUS_CONFIRMED,
                'payment_status' => EventRegistration::PAYMENT_PAID,
                'amount' => 0,
            ]);

            // One manager who is never fielded, and four players who may be.
            EventParticipant::create([
                'event_registration_id' => $registration->id,
                'role' => ParticipantOptions::ROLE_MANAGER,
                'full_name' => 'Manager ' . $i,
                'ic_number' => '900101' . str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'phone' => '0100000' . $i,
            ]);

            for ($p = 1; $p <= 4; $p++) {
                EventParticipant::create([
                    'event_registration_id' => $registration->id,
                    'role' => ParticipantOptions::ROLE_PLAYER,
                    'full_name' => "Team {$i} Player {$p}",
                    'ign_player_id' => "T{$i}P{$p}",
                    'ic_number' => '9502' . $i . str_pad((string) $p, 7, '0', STR_PAD_LEFT),
                    'phone' => "011000{$i}{$p}",
                ]);
            }

            TournamentEntrant::create([
                'tournament_id' => $tournament->id,
                'event_registration_id' => $registration->id,
                'seed' => $i,
                'status' => TournamentEntrant::STATUS_ACTIVE,
            ]);
        }

        $stage = TournamentStage::create([
            'tournament_id' => $tournament->id,
            'name' => 'Qualifiers',
            'type' => TournamentStage::TYPE_LOBBY,
            'sequence' => 1,
            'advance_count' => 0,
            'match_count' => 2,
            'best_of' => ['1' => 1],
        ]);

        app(DrawFactory::class)->generate($stage);

        return [$tournament->fresh(), $stage->fresh()];
    }

    /**
     * Score a match: team figures by seed, and optionally player figures.
     *
     * @param  array<int, array<string, mixed>>  $teamBySeed
     * @param  array<int, array<int, array<string, mixed>>>  $playersBySeed  seed => [player index => figures]
     */
    private function score(TournamentMatch $match, array $teamBySeed, array $playersBySeed = []): void
    {
        $engine = app(ScoringEngine::class);
        $rule = $match->tournament->pointRule;

        foreach ($match->entrants()->with('entrant.registration')->get() as $line) {
            $seed = $line->entrant->seed;
            $inputs = $teamBySeed[$seed] ?? null;

            if ($inputs === null) {
                continue;
            }

            $result = $engine->score($rule, $inputs);

            $line->update([
                'inputs' => $inputs,
                'points' => $result['points'],
                'component_points' => $result['components'],
                'component_counts' => $result['counts'],
                'is_disqualified' => $result['disqualified'],
            ]);

            foreach ($playersBySeed[$seed] ?? [] as $index => $figures) {
                $participant = $this->playersOf($line->entrant)->get($index);

                if ($participant === null) {
                    continue;
                }

                $playerResult = $engine->scorePlayer($rule, $figures);

                $line->players()->updateOrCreate(
                    ['event_participant_id' => $participant->id],
                    [
                        'took_part' => true,
                        'inputs' => $figures,
                        'points' => $playerResult['points'],
                        'component_points' => $playerResult['components'],
                        'component_counts' => $playerResult['counts'],
                    ],
                );
            }
        }

        $match->update([
            'status' => TournamentMatch::STATUS_COMPLETED,
            'scored_at' => now(),
        ]);
    }

    /**
     * @return Collection<int, EventParticipant>
     */
    private function playersOf(TournamentEntrant $entrant): Collection
    {
        return EventParticipant::where('event_registration_id', $entrant->event_registration_id)
            ->where('role', ParticipantOptions::ROLE_PLAYER)
            ->orderBy('id')
            ->get();
    }

    /* -----------------------------------------------------------------
     | The wall between the two ledgers
     * -------------------------------------------------------------- */

    /**
     * The test that matters most. Team standings are computed, player figures are
     * then added, and the team standings must come out byte for byte the same.
     */
    public function test_recording_player_figures_does_not_change_team_standings(): void
    {
        [$tournament, $stage] = $this->build($this->rule());
        $match = $stage->matches()->orderBy('id')->first();

        $team = [
            1 => ['placement' => 1, 'kills' => 12],
            2 => ['placement' => 2, 'kills' => 8],
            3 => ['placement' => 3, 'kills' => 6],
            4 => ['placement' => 4, 'kills' => 2],
        ];

        $this->score($match, $team);
        app(StandingsCalculator::class)->recalculate($tournament->fresh());

        $before = $tournament->fresh()->standings()
            ->orderBy('tournament_entrant_id')
            ->get(['tournament_entrant_id', 'total_points', 'rank', 'component_totals'])
            ->toArray();

        // Now add wildly large personal figures for every player.
        $this->score($match->fresh(), $team, [
            1 => [['kills' => 99, 'damage' => 90000], ['kills' => 99, 'damage' => 90000]],
            2 => [['kills' => 99, 'damage' => 90000]],
            3 => [['kills' => 99, 'damage' => 90000]],
            4 => [['kills' => 99, 'damage' => 90000]],
        ]);

        app(StandingsCalculator::class)->recalculate($tournament->fresh());
        app(PlayerStandingsCalculator::class)->recalculate($tournament->fresh());

        $after = $tournament->fresh()->standings()
            ->orderBy('tournament_entrant_id')
            ->get(['tournament_entrant_id', 'total_points', 'rank', 'component_totals'])
            ->toArray();

        $this->assertSame($before, $after, 'Player figures must not move the team table.');

        // And the player table did fill, so the assertion above is not passing simply
        // because nothing was written.
        $this->assertGreaterThan(0, $tournament->fresh()->playerStandings()->count());
    }

    public function test_a_tournament_reaches_full_standings_with_no_player_figures_at_all(): void
    {
        [$tournament, $stage] = $this->build($this->rule());
        $match = $stage->matches()->orderBy('id')->first();

        $this->score($match, [
            1 => ['placement' => 1, 'kills' => 12],
            2 => ['placement' => 2, 'kills' => 8],
            3 => ['placement' => 3, 'kills' => 6],
            4 => ['placement' => 4, 'kills' => 2],
        ]);

        app(StandingsCalculator::class)->recalculate($tournament->fresh());
        app(PlayerStandingsCalculator::class)->recalculate($tournament->fresh());

        $this->assertSame(4, $tournament->fresh()->standings()->count());
        $this->assertSame(22.0, (float) $tournament->fresh()->standings()->orderBy('rank')->first()->total_points);
        $this->assertSame(0, TournamentMatchPlayer::count());
        $this->assertSame(0, $tournament->fresh()->playerStandings()->count());
    }

    /* -----------------------------------------------------------------
     | The player ledger on its own
     * -------------------------------------------------------------- */

    public function test_a_player_total_is_the_sum_of_their_own_matches(): void
    {
        [$tournament, $stage] = $this->build($this->rule());
        $matches = $stage->matches()->orderBy('id')->get();

        $team = [1 => ['placement' => 1, 'kills' => 10], 2 => ['placement' => 2, 'kills' => 5]];

        $this->score($matches[0], $team, [1 => [['kills' => 6, 'damage' => 2000]]]);
        $this->score($matches[1], $team, [1 => [['kills' => 4, 'damage' => 1500]]]);

        app(PlayerStandingsCalculator::class)->recalculate($tournament->fresh());

        $overall = $tournament->fresh()->playerStandings()
            ->whereNull('tournament_stage_id')
            ->orderByDesc('total_points')
            ->first();

        $this->assertSame('Team 1 Player 1', $overall->display_name);
        $this->assertSame('T1P1', $overall->ign);
        $this->assertSame(2, $overall->matches_played);
        $this->assertSame(10.0, $overall->total_points);
        $this->assertSame(3500, $overall->componentCount('damage'));
    }

    /**
     * Damage is worth no points but settles a tie, exactly as a WWCD does for a squad.
     */
    public function test_damage_breaks_a_tie_without_being_worth_points(): void
    {
        [$tournament, $stage] = $this->build($this->rule());
        $match = $stage->matches()->orderBy('id')->first();

        $this->score($match, [1 => ['placement' => 1, 'kills' => 10]], [
            1 => [
                ['kills' => 5, 'damage' => 1200],
                ['kills' => 5, 'damage' => 2400],
            ],
        ]);

        app(PlayerStandingsCalculator::class)->recalculate($tournament->fresh());

        $rows = $tournament->fresh()->playerStandings()
            ->whereNull('tournament_stage_id')
            ->orderBy('rank')
            ->get();

        $this->assertSame(5.0, $rows[0]->total_points);
        $this->assertSame(5.0, $rows[1]->total_points);
        $this->assertSame(2400, $rows[0]->componentCount('damage'));
        $this->assertSame(1, $rows[0]->rank);
        $this->assertSame(2, $rows[1]->rank);
        $this->assertSame(0.0, $rows[0]->componentTotal('damage'));
    }

    public function test_a_stage_row_and_an_overall_row_are_both_written(): void
    {
        [$tournament, $stage] = $this->build($this->rule());
        $match = $stage->matches()->orderBy('id')->first();

        $this->score($match, [1 => ['placement' => 1, 'kills' => 10]], [
            1 => [['kills' => 5, 'damage' => 1200]],
        ]);

        app(PlayerStandingsCalculator::class)->recalculate($tournament->fresh());

        $this->assertSame(1, $tournament->fresh()->playerStandings()->whereNotNull('tournament_stage_id')->count());
        $this->assertSame(1, $tournament->fresh()->playerStandings()->whereNull('tournament_stage_id')->count());
        $this->assertSame(
            $stage->id,
            $tournament->fresh()->playerStandings()->whereNotNull('tournament_stage_id')->first()->tournament_stage_id,
        );
    }

    /**
     * The squad was thrown out. What the person did is still on record, marked so a
     * reader knows the context rather than silently deleted.
     */
    public function test_a_player_of_a_disqualified_entrant_stays_on_the_leaderboard_marked(): void
    {
        [$tournament, $stage] = $this->build($this->rule());
        $match = $stage->matches()->orderBy('id')->first();

        $this->score($match, [1 => ['placement' => 1, 'kills' => 10]], [
            1 => [['kills' => 5, 'damage' => 1200]],
        ]);

        $tournament->entrants()->where('seed', 1)->update([
            'status' => TournamentEntrant::STATUS_DISQUALIFIED,
        ]);

        app(PlayerStandingsCalculator::class)->recalculate($tournament->fresh());

        $row = $tournament->fresh()->playerStandings()->whereNull('tournament_stage_id')->first();

        $this->assertNotNull($row);
        $this->assertTrue($row->entrant_is_disqualified);
        $this->assertSame(5.0, $row->total_points);
    }

    /* -----------------------------------------------------------------
     | Tracking switched off
     * -------------------------------------------------------------- */

    public function test_a_profile_with_tracking_off_writes_no_player_standings(): void
    {
        [$tournament, $stage] = $this->build($this->rule(PointRule::TRACK_OFF));
        $match = $stage->matches()->orderBy('id')->first();

        $this->score($match, [1 => ['placement' => 1, 'kills' => 10]], [
            1 => [['kills' => 5, 'damage' => 1200]],
        ]);

        app(PlayerStandingsCalculator::class)->recalculate($tournament->fresh());

        $this->assertFalse($tournament->fresh()->tracksPlayers());
        $this->assertSame(0, $tournament->fresh()->playerStandings()->count());
    }

    /**
     * Switching tracking off after the event clears the leaderboard rather than
     * leaving a stale table nobody is updating and nobody can reach.
     */
    public function test_switching_tracking_off_clears_the_leaderboard(): void
    {
        $rule = $this->rule();
        [$tournament, $stage] = $this->build($rule);
        $match = $stage->matches()->orderBy('id')->first();

        $this->score($match, [1 => ['placement' => 1, 'kills' => 10]], [
            1 => [['kills' => 5, 'damage' => 1200]],
        ]);

        app(PlayerStandingsCalculator::class)->recalculate($tournament->fresh());
        $this->assertSame(2, $tournament->fresh()->playerStandings()->count());

        $rule->update(['track_players' => PointRule::TRACK_OFF]);
        app(PlayerStandingsCalculator::class)->recalculate($tournament->fresh());

        $this->assertSame(0, $tournament->fresh()->playerStandings()->count());
    }

    /* -----------------------------------------------------------------
     | Cascades
     * -------------------------------------------------------------- */

    public function test_discarding_a_draw_removes_the_player_rows_with_it(): void
    {
        [$tournament, $stage] = $this->build($this->rule());
        $match = $stage->matches()->orderBy('id')->first();

        $this->score($match, [1 => ['placement' => 1, 'kills' => 10]], [
            1 => [['kills' => 5, 'damage' => 1200]],
        ]);

        $this->assertSame(1, TournamentMatchPlayer::count());

        $stage->matches()->delete();

        $this->assertSame(0, TournamentMatchPlayer::count());
    }

    /* -----------------------------------------------------------------
     | Re-scoring after a rule is edited
     |
     | The standings calculators add up stored component points, so on their
     | own they cannot notice that a kill is now worth two. Rescorer puts the
     | raw inputs back through the engine first. Without it, the warning the
     | operator confirms when editing a live rule would be a promise the code
     | does not keep.
     * -------------------------------------------------------------- */

    public function test_editing_a_team_component_changes_team_standings_after_rescoring(): void
    {
        $rule = $this->rule();
        [$tournament, $stage] = $this->build($rule);
        $match = $stage->matches()->orderBy('id')->first();

        $this->score($match, [1 => ['placement' => 1, 'kills' => 12]]);
        app(StandingsCalculator::class)->recalculate($tournament->fresh());

        // 10 for first place plus 12 kills at one point each.
        $this->assertSame(22.0, (float) $tournament->fresh()->standings()->orderBy('rank')->first()->total_points);

        $components = $rule->components;
        $components[1]['value'] = 2;
        $rule->update(['components' => $components]);

        app(Rescorer::class)->rescore($tournament->fresh());
        app(StandingsCalculator::class)->recalculate($tournament->fresh());

        // 10 plus 12 kills at two points each.
        $this->assertSame(34.0, (float) $tournament->fresh()->standings()->orderBy('rank')->first()->total_points);
    }

    /**
     * And the mirror, which is the one that matters: changing what a player's kill is
     * worth moves the player leaderboard and leaves every team total alone.
     */
    public function test_editing_a_player_component_leaves_team_standings_untouched(): void
    {
        $rule = $this->rule();
        [$tournament, $stage] = $this->build($rule);
        $match = $stage->matches()->orderBy('id')->first();

        $this->score($match, [1 => ['placement' => 1, 'kills' => 12]], [
            1 => [['kills' => 5, 'damage' => 1200]],
        ]);

        app(StandingsCalculator::class)->recalculate($tournament->fresh());
        app(PlayerStandingsCalculator::class)->recalculate($tournament->fresh());

        $teamBefore = $tournament->fresh()->standings()
            ->orderBy('tournament_entrant_id')
            ->pluck('total_points', 'tournament_entrant_id')
            ->all();

        $this->assertSame(5.0, $tournament->fresh()->playerStandings()->whereNull('tournament_stage_id')->first()->total_points);

        $playerComponents = $rule->player_components;
        $playerComponents[0]['value'] = 3;
        $rule->update(['player_components' => $playerComponents]);

        app(Rescorer::class)->rescore($tournament->fresh());
        app(StandingsCalculator::class)->recalculate($tournament->fresh());
        app(PlayerStandingsCalculator::class)->recalculate($tournament->fresh());

        $this->assertSame(15.0, $tournament->fresh()->playerStandings()->whereNull('tournament_stage_id')->first()->total_points);

        $teamAfter = $tournament->fresh()->standings()
            ->orderBy('tournament_entrant_id')
            ->pluck('total_points', 'tournament_entrant_id')
            ->all();

        $this->assertSame($teamBefore, $teamAfter, 'Changing a player value must not move a team total.');
    }

    /**
     * Fixtures nobody has played must stay empty. Writing a zero result over them
     * would turn a scheduled match into a completed one with everybody on nothing.
     */
    public function test_rescoring_leaves_unplayed_fixtures_alone(): void
    {
        $rule = $this->rule();
        [$tournament, $stage] = $this->build($rule);
        $matches = $stage->matches()->orderBy('id')->get();

        $this->score($matches[0], [1 => ['placement' => 1, 'kills' => 12]]);

        app(Rescorer::class)->rescore($tournament->fresh());

        $untouched = $matches[1]->fresh(['entrants']);

        $this->assertSame(TournamentMatch::STATUS_SCHEDULED, $untouched->status);
        $this->assertTrue($untouched->entrants->every(fn ($line) => $line->inputs === null));
    }

    /**
     * Only players are offered, never the manager, and never anybody from another
     * team's registration.
     */
    public function test_only_the_entrants_own_players_are_eligible(): void
    {
        [$tournament] = $this->build($this->rule());

        $first = $tournament->entrants()->where('seed', 1)->first();
        $second = $tournament->entrants()->where('seed', 2)->first();

        $roster = $this->playersOf($first);

        $this->assertCount(4, $roster);
        $this->assertSame(
            ['Team 1 Player 1', 'Team 1 Player 2', 'Team 1 Player 3', 'Team 1 Player 4'],
            $roster->pluck('full_name')->all(),
        );

        $this->assertEmpty(
            $roster->intersect($this->playersOf($second)),
            'A player on one registration must not appear on another entrant.',
        );

        $this->assertEmpty(
            $roster->where('role', ParticipantOptions::ROLE_MANAGER),
            'A manager is not fielded.',
        );
    }
}
