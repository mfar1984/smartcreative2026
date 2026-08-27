<?php

namespace Tests\Unit\Tournament;

use App\Models\PointRule;
use App\Support\Tournament\ScoringEngine;
use PHPUnit\Framework\TestCase;

/**
 * The scoring engine, tested without a database.
 *
 * The profiles are built in memory rather than seeded, because the point of the
 * engine is that it reads a profile it has never seen before.
 */
class ScoringEngineTest extends TestCase
{
    private ScoringEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new ScoringEngine();
    }

    /**
     * The profile the user actually uses today, taken from their spreadsheet.
     */
    private function pmpl(): PointRule
    {
        return new PointRule([
            'name' => 'PMPL / PMGC Official',
            'kind' => PointRule::KIND_BATTLE_ROYALE,
            'squad_size' => 4,
            'components' => [
                [
                    'key' => 'placement',
                    'label' => 'Placement',
                    'type' => PointRule::TYPE_TABLE,
                    'source' => 'placement',
                    'values' => ['1' => 10, '2' => 6, '3' => 5, '4' => 4, '5' => 3, '6' => 2, '7' => 1, '8' => 1],
                ],
                [
                    'key' => 'kills',
                    'label' => 'Kills',
                    'type' => PointRule::TYPE_PER_UNIT,
                    'source' => 'kills',
                    'value' => 1,
                ],
                [
                    'key' => 'wwcd',
                    'label' => 'WWCD',
                    'type' => PointRule::TYPE_BONUS,
                    'when' => ['source' => 'placement', 'equals' => 1],
                    'value' => 0,
                ],
                [
                    'key' => 'squad_penalty',
                    'label' => 'Squad Penalty',
                    'type' => PointRule::TYPE_PENALTY_TABLE,
                    'source' => 'players_present',
                    'values' => ['4' => 0, '3' => -1, '2' => -2, '1' => -3],
                    'disqualify_at' => 0,
                ],
            ],
            'inputs' => [],
            'tiebreak' => ['wwcd', 'placement', 'kills'],
        ]);
    }

    public function test_placement_table_awards_the_configured_points(): void
    {
        $result = $this->engine->score($this->pmpl(), [
            'placement' => 3,
            'kills' => 0,
            'players_present' => 4,
        ]);

        $this->assertSame(5.0, $result['components']['placement']);
    }

    public function test_placement_past_the_end_of_the_table_earns_nothing(): void
    {
        $result = $this->engine->score($this->pmpl(), [
            'placement' => 12,
            'kills' => 0,
            'players_present' => 4,
        ]);

        $this->assertSame(0.0, $result['components']['placement']);
        $this->assertSame(0.0, $result['points']);
    }

    public function test_each_kill_is_worth_one_point(): void
    {
        $result = $this->engine->score($this->pmpl(), [
            'placement' => 16,
            'kills' => 8,
            'players_present' => 4,
        ]);

        $this->assertSame(8.0, $result['components']['kills']);
        $this->assertSame(8, $result['counts']['kills']);
    }

    /**
     * The worked example from the design: third place, eight kills, a squad of
     * three. Five plus eight less one.
     */
    public function test_short_squad_penalty_is_subtracted(): void
    {
        $result = $this->engine->score($this->pmpl(), [
            'placement' => 3,
            'kills' => 8,
            'players_present' => 3,
        ]);

        $this->assertSame(-1.0, $result['components']['squad_penalty']);
        $this->assertSame(12.0, $result['points']);
        $this->assertFalse($result['disqualified']);
    }

    public function test_a_full_squad_carries_no_penalty(): void
    {
        $result = $this->engine->score($this->pmpl(), [
            'placement' => 1,
            'kills' => 12,
            'players_present' => 4,
        ]);

        $this->assertSame(0.0, $result['components']['squad_penalty']);
        $this->assertSame(22.0, $result['points']);
    }

    public function test_wwcd_is_counted_even_though_it_is_worth_no_points(): void
    {
        $won = $this->engine->score($this->pmpl(), [
            'placement' => 1,
            'kills' => 12,
            'players_present' => 4,
        ]);

        $lost = $this->engine->score($this->pmpl(), [
            'placement' => 2,
            'kills' => 12,
            'players_present' => 4,
        ]);

        $this->assertSame(0.0, $won['components']['wwcd']);
        $this->assertSame(1, $won['counts']['wwcd']);
        $this->assertSame(0, $lost['counts']['wwcd']);
    }

    public function test_a_squad_of_one_loses_three_points(): void
    {
        $result = $this->engine->score($this->pmpl(), [
            'placement' => 5,
            'kills' => 2,
            'players_present' => 1,
        ]);

        $this->assertSame(-3.0, $result['components']['squad_penalty']);
        $this->assertSame(2.0, $result['points']);
    }

    public function test_a_squad_of_nobody_is_disqualified(): void
    {
        $result = $this->engine->score($this->pmpl(), [
            'placement' => 16,
            'kills' => 0,
            'players_present' => 0,
        ]);

        $this->assertTrue($result['disqualified']);
    }

    /* -----------------------------------------------------------------
     | Judged sports
     * -------------------------------------------------------------- */

    private function aerobic(): PointRule
    {
        return new PointRule([
            'name' => 'Aerobic 5 Judges',
            'kind' => PointRule::KIND_JUDGED,
            'components' => [
                [
                    'key' => 'judges',
                    'label' => 'Judges',
                    'type' => PointRule::TYPE_AGGREGATE,
                    'source' => 'judges',
                    'method' => 'trimmed_mean',
                ],
                [
                    'key' => 'deductions',
                    'label' => 'Deductions',
                    'type' => PointRule::TYPE_PER_UNIT,
                    'source' => 'faults',
                    'value' => -0.5,
                ],
            ],
            'inputs' => [],
            'tiebreak' => ['judges', 'deductions'],
        ]);
    }

    public function test_trimmed_mean_drops_the_highest_and_lowest_mark(): void
    {
        // 8.5, 9.0, 8.0, 8.5, 9.5 → drop 8.0 and 9.5 → mean of 8.5, 8.5, 9.0
        $result = $this->engine->score($this->aerobic(), [
            'judges' => [8.5, 9.0, 8.0, 8.5, 9.5],
            'faults' => 0,
        ]);

        $this->assertSame(8.667, $result['components']['judges']);
    }

    public function test_deductions_are_subtracted_from_the_aggregate(): void
    {
        $result = $this->engine->score($this->aerobic(), [
            'judges' => [8.5, 9.0, 8.0, 8.5, 9.5],
            'faults' => 2,
        ]);

        $this->assertSame(-1.0, $result['components']['deductions']);
        $this->assertSame(7.667, $result['points']);
    }

    public function test_a_panel_too_small_to_trim_falls_back_to_a_plain_mean(): void
    {
        $result = $this->engine->score($this->aerobic(), [
            'judges' => [8.0, 9.0],
            'faults' => 0,
        ]);

        $this->assertSame(8.5, $result['components']['judges']);
    }

    /* -----------------------------------------------------------------
     | Races
     * -------------------------------------------------------------- */

    private function runProfile(): PointRule
    {
        return new PointRule([
            'name' => 'Run Series',
            'kind' => PointRule::KIND_RACE,
            'components' => [
                [
                    'key' => 'placement',
                    'label' => 'Placement',
                    'type' => PointRule::TYPE_TABLE,
                    'source' => 'placement',
                    'values' => ['1' => 25, '2' => 18, '3' => 15, '4' => 12, '5' => 10],
                ],
            ],
            'inputs' => [],
            'tiebreak' => ['placement'],
        ]);
    }

    public function test_a_race_derives_placement_from_finishing_times(): void
    {
        $scored = $this->engine->scoreRace($this->runProfile(), [
            'ahmad' => ['finish_time' => '00:39:02'],
            'siti' => ['finish_time' => '00:38:14'],
            'lim' => ['finish_time' => '00:39:47'],
        ]);

        $this->assertSame(1, $scored['siti']['inputs']['placement']);
        $this->assertSame(2, $scored['ahmad']['inputs']['placement']);
        $this->assertSame(3, $scored['lim']['inputs']['placement']);

        $this->assertSame(25.0, $scored['siti']['points']);
        $this->assertSame(18.0, $scored['ahmad']['points']);
        $this->assertSame(15.0, $scored['lim']['points']);
    }

    public function test_equal_times_share_a_placement_and_the_next_one_skips(): void
    {
        $scored = $this->engine->scoreRace($this->runProfile(), [
            'a' => ['finish_time' => '00:30:00'],
            'b' => ['finish_time' => '00:30:00'],
            'c' => ['finish_time' => '00:31:00'],
        ]);

        $this->assertSame(1, $scored['a']['inputs']['placement']);
        $this->assertSame(1, $scored['b']['inputs']['placement']);
        $this->assertSame(3, $scored['c']['inputs']['placement']);
    }

    public function test_a_competitor_with_no_time_scores_nothing_and_is_not_placed(): void
    {
        $scored = $this->engine->scoreRace($this->runProfile(), [
            'finished' => ['finish_time' => '00:30:00'],
            'retired' => ['finish_time' => null],
        ]);

        $this->assertSame(1, $scored['finished']['inputs']['placement']);
        $this->assertNull($scored['retired']['inputs']['placement']);
        $this->assertSame(0.0, $scored['retired']['points']);
    }

    public function test_plain_seconds_are_accepted_as_a_time(): void
    {
        $scored = $this->engine->scoreRace($this->runProfile(), [
            'a' => ['finish_time' => 90],
            'b' => ['finish_time' => 75.5],
        ]);

        $this->assertSame(1, $scored['b']['inputs']['placement']);
        $this->assertSame(2, $scored['a']['inputs']['placement']);
    }

    /* -----------------------------------------------------------------
     | Brackets
     * -------------------------------------------------------------- */

    public function test_a_bracket_profile_counts_the_series_result(): void
    {
        $rule = new PointRule([
            'name' => 'Mobile Legends BO3',
            'kind' => PointRule::KIND_BRACKET,
            'components' => [
                [
                    'key' => 'games_won',
                    'label' => 'Games Won',
                    'type' => PointRule::TYPE_PER_UNIT,
                    'source' => 'games_won',
                    'value' => 1,
                ],
            ],
            'inputs' => [],
            'tiebreak' => ['games_won'],
        ]);

        $result = $this->engine->score($rule, ['games_won' => 2]);

        $this->assertSame(2.0, $result['points']);
        $this->assertSame(2, $result['counts']['games_won']);
    }

    public function test_an_unknown_component_type_is_ignored_rather_than_fatal(): void
    {
        $rule = new PointRule([
            'name' => 'Broken',
            'kind' => PointRule::KIND_BRACKET,
            'components' => [
                ['key' => 'mystery', 'type' => 'something_new', 'source' => 'x'],
            ],
            'inputs' => [],
            'tiebreak' => [],
        ]);

        $result = $this->engine->score($rule, ['x' => 5]);

        $this->assertSame(0.0, $result['points']);
    }

    /* -----------------------------------------------------------------
     | Personal player scoring
     |
     | A second ledger. These tests exist mainly to prove the two do not
     | leak into one another, because that separation is the whole reason
     | player scoring can be optional.
     * -------------------------------------------------------------- */

    /**
     * PMPL again, now carrying the player side as well. Note the player components
     * share not a single key with the team's placement, wwcd or squad_penalty.
     */
    private function pmplWithPlayers(): PointRule
    {
        $rule = $this->pmpl();

        $rule->track_players = PointRule::TRACK_OPTIONAL;
        $rule->player_components = [
            ['key' => 'kills', 'label' => 'Kills', 'type' => PointRule::TYPE_PER_UNIT, 'source' => 'kills', 'value' => 1],
            ['key' => 'knocks', 'label' => 'Knocks', 'type' => PointRule::TYPE_PER_UNIT, 'source' => 'knocks', 'value' => 0],
            ['key' => 'damage', 'label' => 'Damage', 'type' => PointRule::TYPE_PER_UNIT, 'source' => 'damage', 'value' => 0],
        ];
        $rule->player_tiebreak = ['kills', 'damage', 'knocks'];

        return $rule;
    }

    public function test_a_player_earns_from_the_player_components(): void
    {
        $result = $this->engine->scorePlayer($this->pmplWithPlayers(), [
            'kills' => 5,
            'knocks' => 3,
            'damage' => 1420,
        ]);

        $this->assertSame(5.0, $result['points']);
    }

    /**
     * Damage decides an MVP tie without being worth anything, exactly as a WWCD
     * decides a squad tie without being worth anything. Same mechanism, so no sixth
     * component type was needed for players.
     */
    public function test_a_zero_valued_player_component_is_counted_but_earns_nothing(): void
    {
        $result = $this->engine->scorePlayer($this->pmplWithPlayers(), [
            'kills' => 5,
            'knocks' => 3,
            'damage' => 1420,
        ]);

        $this->assertSame(0.0, $result['components']['damage']);
        $this->assertSame(1420, $result['counts']['damage']);
        $this->assertSame(0.0, $result['components']['knocks']);
        $this->assertSame(3, $result['counts']['knocks']);
    }

    /**
     * The important one. A placement is handed to the player scorer, and it must be
     * ignored: placement belongs to the team ledger and has no meaning for a person.
     */
    public function test_player_scoring_ignores_the_team_components_entirely(): void
    {
        $result = $this->engine->scorePlayer($this->pmplWithPlayers(), [
            'placement' => 1,
            'players_present' => 0,
            'kills' => 5,
        ]);

        $this->assertSame(5.0, $result['points']);
        $this->assertArrayNotHasKey('placement', $result['components']);
        $this->assertArrayNotHasKey('wwcd', $result['components']);
        $this->assertArrayNotHasKey('squad_penalty', $result['components']);
        $this->assertFalse($result['disqualified']);
    }

    /**
     * And the mirror. Player figures handed to the team scorer change nothing, so a
     * team total cannot drift because somebody filled in personal numbers.
     */
    public function test_team_scoring_ignores_the_player_components_entirely(): void
    {
        $rule = $this->pmplWithPlayers();

        $withPlayerFigures = $this->engine->score($rule, [
            'placement' => 3,
            'kills' => 8,
            'players_present' => 3,
            'knocks' => 40,
            'damage' => 99999,
        ]);

        $withoutPlayerFigures = $this->engine->score($rule, [
            'placement' => 3,
            'kills' => 8,
            'players_present' => 3,
        ]);

        $this->assertSame(12.0, $withPlayerFigures['points']);
        $this->assertSame($withoutPlayerFigures['points'], $withPlayerFigures['points']);
        $this->assertArrayNotHasKey('knocks', $withPlayerFigures['components']);
        $this->assertArrayNotHasKey('damage', $withPlayerFigures['components']);
    }

    /**
     * A profile with tracking off has no player components, and asking for a player
     * score returns nothing rather than falling back to the team's.
     */
    public function test_a_profile_with_tracking_off_scores_a_player_at_nothing(): void
    {
        $rule = $this->pmpl();

        $this->assertFalse($rule->tracksPlayers());

        $result = $this->engine->scorePlayer($rule, ['kills' => 5, 'placement' => 1]);

        $this->assertSame(0.0, $result['points']);
        $this->assertSame([], $result['components']);
    }

    public function test_track_mode_reading(): void
    {
        $off = $this->pmpl();
        $optional = $this->pmplWithPlayers();

        $required = $this->pmplWithPlayers();
        $required->track_players = PointRule::TRACK_REQUIRED;

        $this->assertFalse($off->tracksPlayers());
        $this->assertFalse($off->requiresPlayers());

        $this->assertTrue($optional->tracksPlayers());
        $this->assertFalse($optional->requiresPlayers());

        $this->assertTrue($required->tracksPlayers());
        $this->assertTrue($required->requiresPlayers());
    }
}
