<?php

namespace Tests\Feature\Tournament;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\PointRule;
use App\Models\Tournament;
use App\Models\TournamentEntrant;
use App\Models\TournamentMatch;
use App\Models\TournamentStage;
use App\Support\Tournament\Draw\DrawFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The five draw generators.
 *
 * These need a database because a draw is rows, not a return value, so they sit in
 * Feature rather than Unit.
 */
class DrawGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function tournament(string $format, string $kind, int $entrantCount): Tournament
    {
        $event = Event::create([
            'slug' => 'test-event-' . uniqid(),
            'title' => 'Test Event',
            'category' => 'E-Sport',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => 'published',
            'registration_mode' => 'manager',
            'seats_total' => 100,
        ]);

        $rule = PointRule::create([
            'name' => 'Test ' . $kind . ' ' . uniqid(),
            'kind' => $kind,
            'squad_size' => 4,
            'components' => [
                ['key' => 'placement', 'type' => 'table', 'source' => 'placement', 'values' => ['1' => 10]],
            ],
            'inputs' => [],
            'tiebreak' => ['placement'],
        ]);

        $tournament = Tournament::create([
            'event_id' => $event->id,
            'name' => 'Test Tournament',
            'format' => $format,
            'point_rule_id' => $rule->id,
            'status' => Tournament::STATUS_SETUP,
            'seeding_method' => Tournament::SEEDING_MANUAL,
            'settings' => ['buffer_minutes' => 15, 'map_rotation' => ['Erangel', 'Miramar', 'Sanhok']],
        ]);

        for ($i = 1; $i <= $entrantCount; $i++) {
            $registration = EventRegistration::create([
                'event_id' => $event->id,
                'reference' => 'T-' . uniqid() . '-' . $i,
                'mode' => 'team',
                'team_name' => 'Team ' . $i,
                'status' => EventRegistration::STATUS_CONFIRMED,
                'payment_status' => EventRegistration::PAYMENT_PAID,
                'amount' => 0,
            ]);

            TournamentEntrant::create([
                'tournament_id' => $tournament->id,
                'event_registration_id' => $registration->id,
                'seed' => $i,
                'status' => TournamentEntrant::STATUS_ACTIVE,
            ]);
        }

        return $tournament->fresh(['event']);
    }

    private function stage(Tournament $tournament, string $type, array $attributes = []): TournamentStage
    {
        return TournamentStage::create(array_merge([
            'tournament_id' => $tournament->id,
            'name' => 'Test Stage',
            'type' => $type,
            'sequence' => 1,
            'advance_count' => 2,
            'match_count' => 3,
            'best_of' => ['1' => 1, '2' => 3, '3' => 5, '4' => 5],
        ], $attributes));
    }

    /* -----------------------------------------------------------------
     | Single elimination
     * -------------------------------------------------------------- */

    public function test_sixteen_entrants_produce_fifteen_matches(): void
    {
        $tournament = $this->tournament(Tournament::FORMAT_SINGLE_ELIM, PointRule::KIND_BRACKET, 16);
        $stage = $this->stage($tournament, TournamentStage::TYPE_BRACKET);

        app(DrawFactory::class)->generate($stage);

        $this->assertSame(15, $stage->matches()->count());
        $this->assertSame(8, $stage->matches()->where('round', 1)->count());
        $this->assertSame(1, $stage->matches()->where('round', 4)->count());
    }

    public function test_top_seed_meets_the_lowest_seed_in_round_one(): void
    {
        $tournament = $this->tournament(Tournament::FORMAT_SINGLE_ELIM, PointRule::KIND_BRACKET, 8);
        $stage = $this->stage($tournament, TournamentStage::TYPE_BRACKET);

        app(DrawFactory::class)->generate($stage);

        $first = $stage->matches()->where('round', 1)->where('position', 1)->first();
        $seeds = $first->entrants()->with('entrant')->get()->pluck('entrant.seed')->sort()->values()->all();

        $this->assertSame([1, 8], $seeds);
    }

    public function test_the_two_top_seeds_cannot_meet_before_the_final(): void
    {
        $tournament = $this->tournament(Tournament::FORMAT_SINGLE_ELIM, PointRule::KIND_BRACKET, 8);
        $stage = $this->stage($tournament, TournamentStage::TYPE_BRACKET);

        app(DrawFactory::class)->generate($stage);

        // Seeds 1 and 2 must sit in opposite halves, so no shared match before the last.
        foreach ($stage->matches()->where('round', '<', 3)->get() as $match) {
            $seeds = $match->entrants()->with('entrant')->get()->pluck('entrant.seed')->all();

            $this->assertNotEquals([1, 2], collect($seeds)->sort()->values()->all());
        }
    }

    public function test_eleven_entrants_give_five_byes_to_the_highest_seeds(): void
    {
        $tournament = $this->tournament(Tournament::FORMAT_SINGLE_ELIM, PointRule::KIND_BRACKET, 11);
        $stage = $this->stage($tournament, TournamentStage::TYPE_BRACKET);

        app(DrawFactory::class)->generate($stage);

        $byes = $stage->matches()
            ->where('round', 1)
            ->where('status', TournamentMatch::STATUS_WALKOVER)
            ->get();

        $this->assertCount(5, $byes);

        // A bye must go to a top seed, never to the bottom of the draw.
        foreach ($byes as $bye) {
            $seed = $bye->entrants()->with('entrant')->first()->entrant->seed;
            $this->assertLessThanOrEqual(5, $seed);
        }
    }

    public function test_a_bye_seats_the_entrant_in_the_next_round(): void
    {
        $tournament = $this->tournament(Tournament::FORMAT_SINGLE_ELIM, PointRule::KIND_BRACKET, 11);
        $stage = $this->stage($tournament, TournamentStage::TYPE_BRACKET);

        app(DrawFactory::class)->generate($stage);

        $bye = $stage->matches()->where('round', 1)->where('status', TournamentMatch::STATUS_WALKOVER)->first();
        $next = TournamentMatch::find($bye->winner_to_match_id);

        $this->assertNotNull($next);
        $this->assertTrue(
            $next->entrants()->where('tournament_entrant_id', $bye->winner_entrant_id)->exists(),
            'The entrant given a bye should already be seated in the following round.',
        );
    }

    public function test_best_of_is_taken_from_the_stage_per_round(): void
    {
        $tournament = $this->tournament(Tournament::FORMAT_SINGLE_ELIM, PointRule::KIND_BRACKET, 8);
        $stage = $this->stage($tournament, TournamentStage::TYPE_BRACKET);

        app(DrawFactory::class)->generate($stage);

        $this->assertSame(1, $stage->matches()->where('round', 1)->first()->best_of);
        $this->assertSame(3, $stage->matches()->where('round', 2)->first()->best_of);
        $this->assertSame(5, $stage->matches()->where('round', 3)->first()->best_of);
    }

    /* -----------------------------------------------------------------
     | Group stage
     * -------------------------------------------------------------- */

    public function test_eight_entrants_in_two_groups_produce_twelve_matches(): void
    {
        $tournament = $this->tournament(Tournament::FORMAT_GROUP_SINGLE_ELIM, PointRule::KIND_BRACKET, 8);
        $stage = $this->stage($tournament, TournamentStage::TYPE_GROUP, ['match_count' => 2]);

        app(DrawFactory::class)->generate($stage);

        // Two groups of four, six round-robin matches each.
        $this->assertSame(2, $stage->groups()->count());
        $this->assertSame(12, $stage->matches()->count());
    }

    public function test_the_top_two_seeds_land_in_different_groups(): void
    {
        $tournament = $this->tournament(Tournament::FORMAT_GROUP_SINGLE_ELIM, PointRule::KIND_BRACKET, 8);
        $stage = $this->stage($tournament, TournamentStage::TYPE_GROUP, ['match_count' => 2]);

        app(DrawFactory::class)->generate($stage);

        $groupOf = [];

        foreach ($stage->matches()->with('entrants.entrant')->get() as $match) {
            foreach ($match->entrants as $line) {
                $groupOf[$line->entrant->seed] = $match->tournament_group_id;
            }
        }

        $this->assertNotSame(
            $groupOf[1],
            $groupOf[2],
            'A snake draw must not put the two strongest entrants in the same group.',
        );
    }

    public function test_every_entrant_plays_everybody_in_its_own_group_once(): void
    {
        $tournament = $this->tournament(Tournament::FORMAT_GROUP_SINGLE_ELIM, PointRule::KIND_BRACKET, 8);
        $stage = $this->stage($tournament, TournamentStage::TYPE_GROUP, ['match_count' => 2]);

        app(DrawFactory::class)->generate($stage);

        foreach ($stage->groups as $group) {
            $appearances = [];

            foreach ($group->matches()->with('entrants')->get() as $match) {
                foreach ($match->entrants as $line) {
                    $appearances[$line->tournament_entrant_id] = ($appearances[$line->tournament_entrant_id] ?? 0) + 1;
                }
            }

            // Four in a group means three matches each.
            foreach ($appearances as $count) {
                $this->assertSame(3, $count);
            }
        }
    }

    /* -----------------------------------------------------------------
     | Lobbies
     * -------------------------------------------------------------- */

    public function test_thirty_two_entrants_split_into_two_lobbies(): void
    {
        $tournament = $this->tournament(Tournament::FORMAT_BATTLE_ROYALE, PointRule::KIND_BATTLE_ROYALE, 32);
        $stage = $this->stage($tournament, TournamentStage::TYPE_LOBBY, ['match_count' => 3]);

        app(DrawFactory::class)->generate($stage);

        $this->assertSame(2, $stage->groups()->count());
        $this->assertSame(6, $stage->matches()->count());
    }

    public function test_every_squad_appears_on_every_match_of_its_lobby(): void
    {
        $tournament = $this->tournament(Tournament::FORMAT_BATTLE_ROYALE, PointRule::KIND_BATTLE_ROYALE, 16);
        $stage = $this->stage($tournament, TournamentStage::TYPE_LOBBY, ['match_count' => 3]);

        app(DrawFactory::class)->generate($stage);

        foreach ($stage->matches as $match) {
            $this->assertSame(16, $match->entrants()->count());
        }
    }

    public function test_maps_cycle_through_the_rotation(): void
    {
        $tournament = $this->tournament(Tournament::FORMAT_BATTLE_ROYALE, PointRule::KIND_BATTLE_ROYALE, 16);
        $stage = $this->stage($tournament, TournamentStage::TYPE_LOBBY, ['match_count' => 3]);

        app(DrawFactory::class)->generate($stage);

        $maps = $stage->matches()->orderBy('position')->pluck('map')->all();

        $this->assertSame(['Erangel', 'Miramar', 'Sanhok'], $maps);
    }

    public function test_a_lobby_holds_at_most_sixteen(): void
    {
        $tournament = $this->tournament(Tournament::FORMAT_BATTLE_ROYALE, PointRule::KIND_BATTLE_ROYALE, 17);
        $stage = $this->stage($tournament, TournamentStage::TYPE_LOBBY, ['match_count' => 1]);

        app(DrawFactory::class)->generate($stage);

        foreach ($stage->matches as $match) {
            $this->assertLessThanOrEqual(16, $match->entrants()->count());
        }

        $this->assertSame(2, $stage->groups()->count());
    }

    /* -----------------------------------------------------------------
     | Heats
     * -------------------------------------------------------------- */

    public function test_a_race_is_one_match_holding_everybody(): void
    {
        $tournament = $this->tournament(Tournament::FORMAT_RACE, PointRule::KIND_RACE, 12);
        $stage = $this->stage($tournament, TournamentStage::TYPE_HEAT, ['match_count' => 1]);

        app(DrawFactory::class)->generate($stage);

        $this->assertSame(1, $stage->matches()->count());
        $this->assertSame(12, $stage->matches()->first()->entrants()->count());
    }

    /* -----------------------------------------------------------------
     | Double elimination
     * -------------------------------------------------------------- */

    public function test_four_entrants_produce_an_upper_lower_and_grand_final(): void
    {
        $tournament = $this->tournament(Tournament::FORMAT_DOUBLE_ELIM, PointRule::KIND_BRACKET, 4);
        $stage = $this->stage($tournament, TournamentStage::TYPE_BRACKET);

        app(DrawFactory::class)->generate($stage);

        $this->assertSame(3, $stage->matches()->where('bracket_side', TournamentMatch::SIDE_UPPER)->count());
        $this->assertSame(1, $stage->matches()->where('bracket_side', TournamentMatch::SIDE_LOWER)->count());
        $this->assertSame(1, $stage->matches()->where('bracket_side', TournamentMatch::SIDE_FINAL)->count());
    }

    public function test_first_round_losers_are_wired_into_the_lower_bracket(): void
    {
        $tournament = $this->tournament(Tournament::FORMAT_DOUBLE_ELIM, PointRule::KIND_BRACKET, 4);
        $stage = $this->stage($tournament, TournamentStage::TYPE_BRACKET);

        app(DrawFactory::class)->generate($stage);

        foreach ($stage->matches()->where('bracket_side', TournamentMatch::SIDE_UPPER)->where('round', 1)->get() as $match) {
            $this->assertNotNull($match->loser_to_match_id, 'An upper bracket loser must have somewhere to go.');
        }
    }

    public function test_double_elimination_refuses_a_whole_tournament(): void
    {
        $tournament = $this->tournament(Tournament::FORMAT_DOUBLE_ELIM, PointRule::KIND_BRACKET, 16);
        $stage = $this->stage($tournament, TournamentStage::TYPE_BRACKET);

        $refusal = app(DrawFactory::class)->refusal($stage);

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('playoff', $refusal);
        $this->assertSame(0, $stage->matches()->count());
    }

    /* -----------------------------------------------------------------
     | Guards and scheduling
     * -------------------------------------------------------------- */

    public function test_a_second_draw_is_refused(): void
    {
        $tournament = $this->tournament(Tournament::FORMAT_SINGLE_ELIM, PointRule::KIND_BRACKET, 8);
        $stage = $this->stage($tournament, TournamentStage::TYPE_BRACKET);

        $factory = app(DrawFactory::class);
        $factory->generate($stage);

        $this->assertNotNull($factory->refusal($stage->fresh()));
    }

    public function test_generating_moves_the_tournament_to_ongoing(): void
    {
        $tournament = $this->tournament(Tournament::FORMAT_SINGLE_ELIM, PointRule::KIND_BRACKET, 8);
        $stage = $this->stage($tournament, TournamentStage::TYPE_BRACKET);

        app(DrawFactory::class)->generate($stage);

        $this->assertSame(Tournament::STATUS_ONGOING, $tournament->fresh()->status);
        $this->assertNotNull($tournament->fresh()->draw_generated_at);
    }

    public function test_fixtures_are_spaced_by_the_buffer(): void
    {
        $tournament = $this->tournament(Tournament::FORMAT_BATTLE_ROYALE, PointRule::KIND_BATTLE_ROYALE, 16);
        $stage = $this->stage($tournament, TournamentStage::TYPE_LOBBY, ['match_count' => 3]);

        app(DrawFactory::class)->generate($stage);

        $times = $stage->matches()->orderBy('position')->pluck('scheduled_at');

        // diffInMinutes returns a float in Carbon 3, so compare as integers.
        $this->assertSame(15, (int) $times[0]->diffInMinutes($times[1]));
        $this->assertSame(15, (int) $times[1]->diffInMinutes($times[2]));
    }

    public function test_a_drawn_stage_with_no_results_can_be_discarded(): void
    {
        $tournament = $this->tournament(Tournament::FORMAT_SINGLE_ELIM, PointRule::KIND_BRACKET, 8);
        $stage = $this->stage($tournament, TournamentStage::TYPE_BRACKET);

        $factory = app(DrawFactory::class);
        $factory->generate($stage);
        $factory->discard($stage->fresh());

        $this->assertSame(0, $stage->matches()->count());
        $this->assertNull($stage->fresh()->drawn_at);
    }

    public function test_a_stage_with_a_scored_match_cannot_be_discarded(): void
    {
        $tournament = $this->tournament(Tournament::FORMAT_SINGLE_ELIM, PointRule::KIND_BRACKET, 8);
        $stage = $this->stage($tournament, TournamentStage::TYPE_BRACKET);

        $factory = app(DrawFactory::class);
        $factory->generate($stage);

        $stage->matches()->first()->update([
            'status' => TournamentMatch::STATUS_COMPLETED,
            'scored_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);

        $factory->discard($stage->fresh());
    }
}
