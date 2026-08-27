<?php

namespace App\Support\Tournament;

use App\Models\PointRule;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchEntrant;
use Illuminate\Support\Facades\DB;

/**
 * Works every recorded result out again against the current point rule.
 *
 * This exists because of a gap that is easy to miss. `StandingsCalculator` adds up
 * `component_points`, which were worked out and stored when the score was entered.
 * Rebuilding standings therefore cannot notice that a kill is now worth two points
 * instead of one: it would add the old numbers up again and reach the old answer.
 *
 * So when a profile is edited, the raw `inputs` have to be put back through the
 * engine first. `inputs` is what the operator typed and never changes; everything
 * else is derived and can be rebuilt from it. That is the whole reason the two are
 * kept in separate columns.
 *
 * Both ledgers are re-scored, each against its own component list. Neither can move
 * the other.
 */
final class Rescorer
{
    public function __construct(private readonly ScoringEngine $engine)
    {
    }

    /**
     * Re-score every settled match line in one tournament.
     *
     * Untouched fixtures are skipped: a line with no `inputs` has never been scored,
     * and writing zeroes over it would turn an empty fixture into a played one.
     */
    public function rescore(Tournament $tournament): void
    {
        $tournament->loadMissing('pointRule');
        $rule = $tournament->pointRule;

        if ($rule === null) {
            return;
        }

        $matches = $tournament->matches()
            ->whereIn('status', [TournamentMatch::STATUS_COMPLETED, TournamentMatch::STATUS_WALKOVER])
            ->with(['entrants.players'])
            ->get();

        DB::transaction(function () use ($matches, $rule) {
            foreach ($matches as $match) {
                $this->rescoreMatch($match, $rule);
            }
        });
    }

    private function rescoreMatch(TournamentMatch $match, PointRule $rule): void
    {
        $scored = $match->entrants->filter(fn (TournamentMatchEntrant $line) => $line->isScored());

        if ($scored->isEmpty()) {
            return;
        }

        /*
         | A race is scored as a whole field at once, because a finishing position only
         | exists relative to everybody else in the heat. Every other family scores one
         | competitor at a time.
         */
        if ($rule->kind === PointRule::KIND_RACE) {
            $results = $this->engine->scoreRace(
                $rule,
                $scored->mapWithKeys(fn ($line) => [$line->tournament_entrant_id => $line->inputs])->all(),
            );

            foreach ($scored as $line) {
                $result = $results[$line->tournament_entrant_id] ?? null;

                if ($result !== null) {
                    $this->writeTeamLine($line, $result['inputs'], $result);
                }
            }
        } else {
            foreach ($scored as $line) {
                $this->writeTeamLine($line, $line->inputs, $this->engine->score($rule, $line->inputs));
            }
        }

        $this->rescorePlayers($match, $rule);
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @param  array<string, mixed>  $result
     */
    private function writeTeamLine(TournamentMatchEntrant $line, array $inputs, array $result): void
    {
        $line->update([
            'inputs' => $inputs,
            'points' => $result['points'],
            'component_points' => $result['components'],
            'component_counts' => $result['counts'],
            'is_disqualified' => $result['disqualified'],
        ]);
    }

    /**
     * The player ledger, re-scored against `player_components`.
     *
     * When tracking has been switched off, the rows are left exactly as they are
     * rather than zeroed. `PlayerStandingsCalculator` already clears the leaderboard
     * in that case, and the raw figures are worth keeping in case tracking is turned
     * back on.
     */
    private function rescorePlayers(TournamentMatch $match, PointRule $rule): void
    {
        if (! $rule->tracksPlayers()) {
            return;
        }

        foreach ($match->entrants as $line) {
            foreach ($line->players as $player) {
                if (! $player->isScored()) {
                    continue;
                }

                $result = $this->engine->scorePlayer($rule, $player->inputs);

                $player->update([
                    'points' => $result['points'],
                    'component_points' => $result['components'],
                    'component_counts' => $result['counts'],
                ]);
            }
        }
    }
}
