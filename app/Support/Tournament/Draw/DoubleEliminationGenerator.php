<?php

namespace App\Support\Tournament\Draw;

use App\Models\TournamentMatch;
use App\Models\TournamentStage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Two lives each. Lose once and you drop to the lower bracket; lose twice and you
 * are out.
 *
 * Deliberately refuses more than eight entrants. That is the organiser's own advice
 * turned into a rule: double elimination from round one roughly doubles the number
 * of matches and takes far longer than a community tournament has room for. The
 * intended shape is a straight knockout down to the last four or eight, then this as
 * a second stage for the playoff.
 */
final class DoubleEliminationGenerator implements DrawGenerator
{
    private const MAX_ENTRANTS = 8;

    public function refusal(TournamentStage $stage, Collection $entrants): ?string
    {
        $count = $entrants->count();

        if ($count < 4) {
            return 'Double elimination needs at least four entrants.';
        }

        if ($count > self::MAX_ENTRANTS) {
            return sprintf(
                'Double elimination is for a playoff of up to %d, not a whole tournament: it roughly doubles the matches. '
                . 'Run a knockout stage first, then add this as a second stage for the last %d.',
                self::MAX_ENTRANTS,
                self::MAX_ENTRANTS,
            );
        }

        if (($count & ($count - 1)) !== 0) {
            return sprintf('%d entrants does not divide evenly. Use 4 or 8 for a double elimination playoff.', $count);
        }

        return null;
    }

    public function generate(TournamentStage $stage, Collection $entrants): void
    {
        $ordered = $entrants->values();
        $count = $ordered->count();

        DB::transaction(function () use ($stage, $ordered, $count) {
            $upperRounds = (int) log($count, 2);

            // ---------- Upper bracket ----------
            $upper = [];

            for ($round = $upperRounds; $round >= 1; $round--) {
                $inRound = (int) ($count / (2 ** $round));

                for ($position = 1; $position <= $inRound; $position++) {
                    $next = $upper[$round + 1][(int) ceil($position / 2)] ?? null;

                    $upper[$round][$position] = TournamentMatch::create([
                        'tournament_id' => $stage->tournament_id,
                        'tournament_stage_id' => $stage->id,
                        'round' => $round,
                        'position' => $position,
                        'bracket_side' => TournamentMatch::SIDE_UPPER,
                        'best_of' => $stage->bestOfForRound($round),
                        'status' => TournamentMatch::STATUS_SCHEDULED,
                        'winner_to_match_id' => $next?->id,
                        'winner_to_slot' => $next === null ? null : ($position % 2 === 1 ? 1 : 2),
                    ]);
                }
            }

            // Seed the first upper round, 1 against the lowest seed.
            foreach ($upper[1] as $position => $match) {
                $top = $ordered[$position - 1] ?? null;
                $bottom = $ordered[$count - $position] ?? null;

                if ($top !== null) {
                    $match->entrants()->create(['tournament_entrant_id' => $top->id, 'slot' => 1]);
                }

                if ($bottom !== null && $bottom->id !== $top?->id) {
                    $match->entrants()->create(['tournament_entrant_id' => $bottom->id, 'slot' => 2]);
                }
            }

            // ---------- Lower bracket ----------
            /*
             | One lower round per upper round after the first, plus the round that
             | receives the first upper losers. Four entrants gives two lower matches;
             | eight gives four. Kept as a flat list because the shape is small and a
             | general n-round lower bracket is far harder to read than it is worth here.
             */
            $lower = [];
            $lowerRounds = max(1, $upperRounds - 1);

            for ($round = $lowerRounds; $round >= 1; $round--) {
                $inRound = (int) max(1, $count / (2 ** ($round + 1)));

                for ($position = 1; $position <= $inRound; $position++) {
                    $next = $lower[$round + 1][(int) ceil($position / 2)] ?? null;

                    $lower[$round][$position] = TournamentMatch::create([
                        'tournament_id' => $stage->tournament_id,
                        'tournament_stage_id' => $stage->id,
                        'round' => $round,
                        'position' => $position,
                        'bracket_side' => TournamentMatch::SIDE_LOWER,
                        'best_of' => $stage->bestOfForRound($round),
                        'status' => TournamentMatch::STATUS_SCHEDULED,
                        'winner_to_match_id' => $next?->id,
                        'winner_to_slot' => $next === null ? null : ($position % 2 === 1 ? 1 : 2),
                    ]);
                }
            }

            // ---------- Grand final ----------
            $final = TournamentMatch::create([
                'tournament_id' => $stage->tournament_id,
                'tournament_stage_id' => $stage->id,
                'round' => $upperRounds + 1,
                'position' => 1,
                'bracket_side' => TournamentMatch::SIDE_FINAL,
                'best_of' => $stage->bestOfForRound($upperRounds + 1),
                'status' => TournamentMatch::STATUS_SCHEDULED,
            ]);

            /*
             | Wire the losers.
             |
             | Losers of the first upper round fall into the first lower round. Losers
             | of every later upper round meet the lower bracket survivor. The upper
             | winner and the lower winner meet in the grand final.
             */
            foreach ($upper[1] as $position => $match) {
                $target = $lower[1][(int) ceil($position / 2)] ?? null;

                $match->update([
                    'loser_to_match_id' => $target?->id,
                    'loser_to_slot' => $target === null ? null : ($position % 2 === 1 ? 1 : 2),
                ]);
            }

            for ($round = 2; $round <= $upperRounds; $round++) {
                foreach ($upper[$round] ?? [] as $position => $match) {
                    $target = $lower[$round] [$position] ?? $lower[$lowerRounds][1] ?? null;

                    $match->update([
                        'loser_to_match_id' => $target?->id,
                        'loser_to_slot' => 2,
                    ]);
                }
            }

            // Upper champion into the final's top slot, lower survivor into the bottom.
            $upper[$upperRounds][1]->update([
                'winner_to_match_id' => $final->id,
                'winner_to_slot' => 1,
            ]);

            $lower[$lowerRounds][1]->update([
                'winner_to_match_id' => $final->id,
                'winner_to_slot' => 2,
            ]);
        });
    }
}
