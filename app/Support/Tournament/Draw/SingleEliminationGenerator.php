<?php

namespace App\Support\Tournament\Draw;

use App\Models\TournamentMatch;
use App\Models\TournamentStage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A knockout tree. One loss and you are out.
 *
 * Two things here are easy to get wrong and both are the point of the class.
 *
 * The first round is paired 1 against the lowest seed, 2 against the second lowest,
 * and so on down the standard bracket order. That ordering is what keeps the top two
 * seeds apart until the final; pairing 1v2, 3v4 would put them out in round one.
 *
 * When the entrant count is not a power of two, the shortfall is given to the
 * highest seeds as byes. A bye is not a match: the seed is placed straight into the
 * second round, so nobody plays an opponent who does not exist.
 */
final class SingleEliminationGenerator implements DrawGenerator
{
    public function refusal(TournamentStage $stage, Collection $entrants): ?string
    {
        if ($entrants->count() < 2) {
            return 'A knockout needs at least two entrants.';
        }

        return null;
    }

    public function generate(TournamentStage $stage, Collection $entrants): void
    {
        $ordered = $entrants->values();
        $size = $this->bracketSize($ordered->count());
        $rounds = (int) log($size, 2);

        DB::transaction(function () use ($stage, $ordered, $size, $rounds) {
            /*
             | Built back to front: the final first, then the round feeding it, so
             | every match already knows the id of the match its winner goes to.
             | Building forwards would need a second pass to fill those pointers in.
             */
            $byRound = [];

            for ($round = $rounds; $round >= 1; $round--) {
                $inRound = (int) ($size / (2 ** $round));
                $byRound[$round] = [];

                for ($position = 1; $position <= $inRound; $position++) {
                    $next = $byRound[$round + 1][(int) ceil($position / 2)] ?? null;

                    $byRound[$round][$position] = TournamentMatch::create([
                        'tournament_id' => $stage->tournament_id,
                        'tournament_stage_id' => $stage->id,
                        'round' => $round,
                        'position' => $position,
                        'best_of' => $stage->bestOfForRound($round),
                        'status' => TournamentMatch::STATUS_SCHEDULED,
                        'winner_to_match_id' => $next?->id,

                        // Odd positions feed the top slot of the next match, even the
                        // bottom, which is what makes the tree read down the page.
                        'winner_to_slot' => $next === null ? null : ($position % 2 === 1 ? 1 : 2),
                    ]);
                }
            }

            $this->seatFirstRound($stage, $ordered, $size, $byRound);
        });
    }

    /**
     * Place the seeds into round one, and carry byes straight into round two.
     *
     * @param  Collection<int, \App\Models\TournamentEntrant>  $ordered
     * @param  array<int, array<int, TournamentMatch>>  $byRound
     */
    private function seatFirstRound(TournamentStage $stage, Collection $ordered, int $size, array $byRound): void
    {
        $slots = $this->seedOrder($size);
        $count = $ordered->count();

        foreach ($byRound[1] as $position => $match) {
            // Each first round match takes two slots of the bracket order.
            $topSeed = $slots[($position - 1) * 2];
            $bottomSeed = $slots[($position - 1) * 2 + 1];

            $top = $topSeed <= $count ? $ordered[$topSeed - 1] : null;
            $bottom = $bottomSeed <= $count ? $ordered[$bottomSeed - 1] : null;

            /*
             | Both present: an ordinary fixture.
             | One present: a bye, so that entrant is seated in the next round and this
             |   match is left as a walkover with nobody to beat.
             | Neither: cannot happen with a correct bracket size, but the branch is
             |   here rather than assumed.
             */
            if ($top !== null && $bottom !== null) {
                $match->entrants()->create(['tournament_entrant_id' => $top->id, 'slot' => 1]);
                $match->entrants()->create(['tournament_entrant_id' => $bottom->id, 'slot' => 2]);

                continue;
            }

            $advancing = $top ?? $bottom;

            if ($advancing === null) {
                continue;
            }

            $match->update([
                'status' => TournamentMatch::STATUS_WALKOVER,
                'winner_entrant_id' => $advancing->id,
                'resolution' => TournamentMatch::RESOLUTION_WALKOVER,
                'reason' => 'Bye. The bracket was not a power of two, so the highest seeds skip this round.',
            ]);

            $match->entrants()->create(['tournament_entrant_id' => $advancing->id, 'slot' => 1]);

            if ($match->winner_to_match_id !== null) {
                TournamentMatch::find($match->winner_to_match_id)
                    ?->entrants()
                    ->create([
                        'tournament_entrant_id' => $advancing->id,
                        'slot' => $match->winner_to_slot,
                    ]);
            }
        }
    }

    /**
     * The smallest power of two that holds this many entrants.
     */
    private function bracketSize(int $count): int
    {
        $size = 2;

        while ($size < $count) {
            $size *= 2;
        }

        return $size;
    }

    /**
     * Standard bracket seeding order.
     *
     * Built by mirroring: a bracket of four is [1,4,2,3]; of eight it becomes
     * [1,8,4,5,2,7,3,6]. Each doubling pairs every existing seed with its complement,
     * which is what guarantees the top two meet no earlier than the final.
     *
     * @return array<int, int>
     */
    private function seedOrder(int $size): array
    {
        $order = [1, 2];

        while (count($order) < $size) {
            $next = count($order) * 2 + 1;
            $mirrored = [];

            foreach ($order as $seed) {
                $mirrored[] = $seed;
                $mirrored[] = $next - $seed;
            }

            $order = $mirrored;
        }

        return $order;
    }
}
