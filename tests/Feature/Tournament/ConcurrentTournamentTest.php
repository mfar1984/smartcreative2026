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
use App\Support\Tournament\ScoringEngine;
use App\Support\Tournament\StandingsCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Several tournaments running at once.
 *
 * This is the requirement the organiser was most insistent about: a PUBG tournament
 * and a Mobile Legends tournament must both be able to run the same afternoon, and
 * scoring one must not disturb the other.
 *
 * These tests are the ones that would fail first if anybody later introduced shared
 * state, a cached "current tournament", or a query that forgot its tournament_id.
 */
class ConcurrentTournamentTest extends TestCase
{
    use RefreshDatabase;

    private function event(string $slug): Event
    {
        return Event::create([
            'slug' => $slug,
            'title' => 'Event ' . $slug,
            'category' => 'E-Sport',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => 'published',
            'registration_mode' => 'manager',
            'seats_total' => 100,
        ]);
    }

    private function battleRoyaleRule(): PointRule
    {
        return PointRule::create([
            'name' => 'BR ' . uniqid(),
            'kind' => PointRule::KIND_BATTLE_ROYALE,
            'squad_size' => 4,
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
        ]);
    }

    private function bracketRule(): PointRule
    {
        return PointRule::create([
            'name' => 'Bracket ' . uniqid(),
            'kind' => PointRule::KIND_BRACKET,
            'squad_size' => 5,
            'components' => [
                ['key' => 'series_won', 'label' => 'Games Won', 'type' => 'per_unit', 'source' => 'series_won', 'value' => 1],
            ],
            'inputs' => [
                ['key' => 'series_won', 'label' => 'Games Won', 'type' => 'integer', 'min' => 0, 'max' => 3, 'required' => true],
            ],
            'tiebreak' => ['series_won'],
        ]);
    }

    /**
     * @return array{0: Tournament, 1: TournamentStage}
     */
    private function build(Event $event, string $name, string $format, PointRule $rule, string $stageType, int $entrants): array
    {
        $tournament = Tournament::create([
            'event_id' => $event->id,
            'name' => $name,
            'format' => $format,
            'point_rule_id' => $rule->id,
            'status' => Tournament::STATUS_SETUP,
            'seeding_method' => Tournament::SEEDING_MANUAL,
            'settings' => ['buffer_minutes' => 15, 'map_rotation' => ['Erangel']],
        ]);

        for ($i = 1; $i <= $entrants; $i++) {
            $registration = EventRegistration::create([
                'event_id' => $event->id,
                'reference' => 'R-' . uniqid() . $i,
                'mode' => 'team',
                'team_name' => $name . ' Team ' . $i,
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

        $stage = TournamentStage::create([
            'tournament_id' => $tournament->id,
            'name' => 'Main',
            'type' => $stageType,
            'sequence' => 1,
            'advance_count' => 0,
            'match_count' => 2,
            'best_of' => ['1' => 1, '2' => 3],
        ]);

        app(DrawFactory::class)->generate($stage);

        return [$tournament->fresh(), $stage->fresh()];
    }

    /**
     * Score every line of a match with the given inputs, keyed by seed.
     *
     * @param  array<int, array<string, mixed>>  $bySeed
     */
    private function score(TournamentMatch $match, array $bySeed): void
    {
        $engine = app(ScoringEngine::class);
        $rule = $match->tournament->pointRule;

        foreach ($match->entrants()->with('entrant')->get() as $line) {
            $inputs = $bySeed[$line->entrant->seed] ?? null;

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
        }

        $match->update([
            'status' => TournamentMatch::STATUS_COMPLETED,
            'scored_at' => now(),
        ]);
    }

    public function test_two_tournaments_can_be_ongoing_at_the_same_time(): void
    {
        [$pubg] = $this->build($this->event('pubg'), 'PUBG Main', Tournament::FORMAT_BATTLE_ROYALE,
            $this->battleRoyaleRule(), TournamentStage::TYPE_LOBBY, 4);

        [$ml] = $this->build($this->event('ml'), 'ML Cup', Tournament::FORMAT_SINGLE_ELIM,
            $this->bracketRule(), TournamentStage::TYPE_BRACKET, 4);

        $this->assertSame(Tournament::STATUS_ONGOING, $pubg->status);
        $this->assertSame(Tournament::STATUS_ONGOING, $ml->status);
        $this->assertSame(2, Tournament::where('status', Tournament::STATUS_ONGOING)->count());
    }

    public function test_scoring_one_tournament_leaves_the_other_untouched(): void
    {
        [$pubg, $pubgStage] = $this->build($this->event('pubg'), 'PUBG Main', Tournament::FORMAT_BATTLE_ROYALE,
            $this->battleRoyaleRule(), TournamentStage::TYPE_LOBBY, 4);

        [$ml, $mlStage] = $this->build($this->event('ml'), 'ML Cup', Tournament::FORMAT_SINGLE_ELIM,
            $this->bracketRule(), TournamentStage::TYPE_BRACKET, 4);

        $calculator = app(StandingsCalculator::class);

        // Score the whole of PUBG.
        foreach ($pubgStage->matches as $match) {
            $this->score($match->load('tournament.pointRule'), [
                1 => ['placement' => 1, 'kills' => 10],
                2 => ['placement' => 2, 'kills' => 5],
                3 => ['placement' => 3, 'kills' => 3],
                4 => ['placement' => 4, 'kills' => 1],
            ]);
        }

        $calculator->recalculate($pubg);

        // PUBG has a table; ML has nothing, because nothing of ML was played.
        $this->assertGreaterThan(0, $pubg->standings()->count());
        $this->assertSame(0, $ml->standings()->count());

        // And nothing of ML moved.
        $this->assertSame(Tournament::STATUS_ONGOING, $ml->fresh()->status);
        $this->assertSame(
            0,
            $ml->matches()->whereIn('status', [TournamentMatch::STATUS_COMPLETED, TournamentMatch::STATUS_WALKOVER])->count(),
        );
    }

    public function test_recalculating_one_tournament_does_not_delete_the_other_standings(): void
    {
        [$pubg, $pubgStage] = $this->build($this->event('pubg'), 'PUBG Main', Tournament::FORMAT_BATTLE_ROYALE,
            $this->battleRoyaleRule(), TournamentStage::TYPE_LOBBY, 4);

        [$other, $otherStage] = $this->build($this->event('pubg2'), 'PUBG Ladies', Tournament::FORMAT_BATTLE_ROYALE,
            $this->battleRoyaleRule(), TournamentStage::TYPE_LOBBY, 4);

        $calculator = app(StandingsCalculator::class);

        foreach ([$pubgStage, $otherStage] as $stage) {
            foreach ($stage->matches as $match) {
                $this->score($match->load('tournament.pointRule'), [
                    1 => ['placement' => 1, 'kills' => 10],
                    2 => ['placement' => 2, 'kills' => 5],
                    3 => ['placement' => 3, 'kills' => 3],
                    4 => ['placement' => 4, 'kills' => 1],
                ]);
            }
        }

        $calculator->recalculate($pubg);
        $calculator->recalculate($other);

        $pubgCount = $pubg->standings()->count();
        $otherCount = $other->standings()->count();

        $this->assertGreaterThan(0, $pubgCount);
        $this->assertGreaterThan(0, $otherCount);

        // Recalculating one again must leave the other's rows exactly as they were.
        $calculator->recalculate($pubg->fresh());

        $this->assertSame($pubgCount, $pubg->standings()->count());
        $this->assertSame($otherCount, $other->standings()->count());
    }

    public function test_two_tournaments_on_the_same_event_keep_separate_entrants(): void
    {
        $event = $this->event('shared');

        [$open] = $this->build($event, 'Open Division', Tournament::FORMAT_BATTLE_ROYALE,
            $this->battleRoyaleRule(), TournamentStage::TYPE_LOBBY, 4);

        [$ladies] = $this->build($event, 'Ladies Division', Tournament::FORMAT_BATTLE_ROYALE,
            $this->battleRoyaleRule(), TournamentStage::TYPE_LOBBY, 4);

        $this->assertSame($open->event_id, $ladies->event_id);
        $this->assertSame(4, $open->entrants()->count());
        $this->assertSame(4, $ladies->entrants()->count());

        // No entrant row is shared between them.
        $overlap = $open->entrants()->pluck('id')->intersect($ladies->entrants()->pluck('id'));

        $this->assertCount(0, $overlap);
    }

    public function test_two_tournaments_can_use_different_point_rules_and_formats(): void
    {
        [$pubg] = $this->build($this->event('pubg'), 'PUBG Main', Tournament::FORMAT_BATTLE_ROYALE,
            $this->battleRoyaleRule(), TournamentStage::TYPE_LOBBY, 4);

        [$ml] = $this->build($this->event('ml'), 'ML Cup', Tournament::FORMAT_SINGLE_ELIM,
            $this->bracketRule(), TournamentStage::TYPE_BRACKET, 4);

        $this->assertNotSame($pubg->point_rule_id, $ml->point_rule_id);
        $this->assertSame(PointRule::KIND_BATTLE_ROYALE, $pubg->pointRule->kind);
        $this->assertSame(PointRule::KIND_BRACKET, $ml->pointRule->kind);
    }

    public function test_a_tournaments_settings_are_its_own_copy(): void
    {
        [$first] = $this->build($this->event('a'), 'First', Tournament::FORMAT_BATTLE_ROYALE,
            $this->battleRoyaleRule(), TournamentStage::TYPE_LOBBY, 4);

        [$second] = $this->build($this->event('b'), 'Second', Tournament::FORMAT_BATTLE_ROYALE,
            $this->battleRoyaleRule(), TournamentStage::TYPE_LOBBY, 4);

        $first->update(['settings' => array_merge($first->settings, ['buffer_minutes' => 45])]);

        // Changing one tournament's rules must not reach the other.
        $this->assertSame(45, $first->fresh()->setting('buffer_minutes'));
        $this->assertSame(15, $second->fresh()->setting('buffer_minutes'));
    }

    public function test_standings_are_counted_not_incremented(): void
    {
        [$tournament, $stage] = $this->build($this->event('count'), 'Counting', Tournament::FORMAT_BATTLE_ROYALE,
            $this->battleRoyaleRule(), TournamentStage::TYPE_LOBBY, 4);

        $calculator = app(StandingsCalculator::class);

        foreach ($stage->matches as $match) {
            $this->score($match->load('tournament.pointRule'), [
                1 => ['placement' => 1, 'kills' => 10],
                2 => ['placement' => 2, 'kills' => 5],
                3 => ['placement' => 3, 'kills' => 3],
                4 => ['placement' => 4, 'kills' => 1],
            ]);
        }

        $calculator->recalculate($tournament);
        $first = $tournament->standings()->orderBy('rank')->first()->total_points;

        // Running it three more times must give the same answer, which is what an
        // incrementing counter would fail.
        $calculator->recalculate($tournament->fresh());
        $calculator->recalculate($tournament->fresh());
        $calculator->recalculate($tournament->fresh());

        $this->assertSame($first, $tournament->standings()->orderBy('rank')->first()->total_points);
    }

    public function test_wwcd_breaks_a_tie_before_kills(): void
    {
        [$tournament, $stage] = $this->build($this->event('tie'), 'Tie', Tournament::FORMAT_BATTLE_ROYALE,
            $this->battleRoyaleRule(), TournamentStage::TYPE_LOBBY, 4);

        $matches = $stage->matches;

        /*
         | Two squads finish level on points across two matches. Seed 1 took a first
         | place; seed 2 never did but has the same total. WWCD is the first tie-break,
         | so seed 1 must be ranked above.
         |
         | Match 1: seed 1 first with 0 kills = 10. seed 2 second with 4 kills = 10.
         | Match 2: seed 1 second with 4 kills = 10. seed 2 first with 0 kills = 10.
         | Both on 20, both with one WWCD... so make seed 2 never win instead.
         */
        $this->score($matches[0]->load('tournament.pointRule'), [
            1 => ['placement' => 1, 'kills' => 0],   // 10
            2 => ['placement' => 2, 'kills' => 4],   // 10
            3 => ['placement' => 3, 'kills' => 0],
            4 => ['placement' => 4, 'kills' => 0],
        ]);

        $this->score($matches[1]->load('tournament.pointRule'), [
            1 => ['placement' => 3, 'kills' => 5],   // 10
            2 => ['placement' => 2, 'kills' => 4],   // 10
            3 => ['placement' => 1, 'kills' => 0],
            4 => ['placement' => 4, 'kills' => 0],
        ]);

        app(StandingsCalculator::class)->recalculate($tournament);

        $rows = $tournament->standings()->with('entrant')->orderBy('rank')->get();
        $one = $rows->firstWhere('entrant.seed', 1);
        $two = $rows->firstWhere('entrant.seed', 2);

        $this->assertSame(20.0, $one->total_points);
        $this->assertSame(20.0, $two->total_points);
        $this->assertSame(1, $one->componentCount('wwcd'));
        $this->assertSame(0, $two->componentCount('wwcd'));
        $this->assertLessThan($two->rank, $one->rank, 'A WWCD must outrank an equal total without one.');
    }

    public function test_entrants_still_level_after_every_tiebreak_are_marked_tied(): void
    {
        [$tournament, $stage] = $this->build($this->event('level'), 'Level', Tournament::FORMAT_BATTLE_ROYALE,
            $this->battleRoyaleRule(), TournamentStage::TYPE_LOBBY, 4);

        // Seeds 3 and 4 both score nothing at all, in identical fashion.
        foreach ($stage->matches as $match) {
            $this->score($match->load('tournament.pointRule'), [
                1 => ['placement' => 1, 'kills' => 5],
                2 => ['placement' => 2, 'kills' => 3],
                3 => ['placement' => 9, 'kills' => 0],
                4 => ['placement' => 9, 'kills' => 0],
            ]);
        }

        app(StandingsCalculator::class)->recalculate($tournament);

        $tied = $tournament->standings()->where('is_tied', true)->get();

        $this->assertGreaterThanOrEqual(2, $tied->count(), 'Two identical records must be reported as tied.');
    }
}
