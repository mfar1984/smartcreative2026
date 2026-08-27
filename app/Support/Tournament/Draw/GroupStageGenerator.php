<?php

namespace App\Support\Tournament\Draw;

use App\Models\TournamentEntrant;
use App\Models\TournamentGroup;
use App\Models\TournamentMatch;
use App\Models\TournamentStage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Round robin inside each group. Everybody plays everybody in their own group.
 *
 * Groups are filled in a snake, so seeds 1 and 2 land in different groups and the
 * groups end up of comparable strength. Dealing them in blocks would put the four
 * strongest teams together and decide the tournament in the group stage.
 *
 * This is the format the organiser wanted for eight to sixteen teams, because every
 * team is guaranteed several matches rather than being knocked out in one.
 */
final class GroupStageGenerator implements DrawGenerator
{
    public function refusal(TournamentStage $stage, Collection $entrants): ?string
    {
        $groups = $this->groupCount($stage, $entrants->count());

        if ($entrants->count() < 4) {
            return 'A group stage needs at least four entrants. Below that a straight knockout is simpler.';
        }

        if ($entrants->count() < $groups * 2) {
            return sprintf('%d groups would leave fewer than two entrants in some of them.', $groups);
        }

        return null;
    }

    public function generate(TournamentStage $stage, Collection $entrants): void
    {
        $groupCount = $this->groupCount($stage, $entrants->count());

        DB::transaction(function () use ($stage, $entrants, $groupCount) {
            $buckets = $this->snake($entrants->values(), $groupCount);

            foreach ($buckets as $index => $members) {
                $group = TournamentGroup::create([
                    'tournament_stage_id' => $stage->id,
                    'name' => 'Group ' . chr(65 + $index),
                    'sequence' => $index + 1,
                ]);

                $this->roundRobin($stage, $group, $members);
            }
        });
    }

    /**
     * Every pairing within one group, each played once.
     *
     * @param  Collection<int, TournamentEntrant>  $members
     */
    private function roundRobin(TournamentStage $stage, TournamentGroup $group, Collection $members): void
    {
        $list = $members->values();
        $position = 1;

        for ($i = 0; $i < $list->count(); $i++) {
            for ($j = $i + 1; $j < $list->count(); $j++) {
                $match = TournamentMatch::create([
                    'tournament_id' => $stage->tournament_id,
                    'tournament_stage_id' => $stage->id,
                    'tournament_group_id' => $group->id,

                    // Round one throughout: a group stage has no tree, and the round
                    // column is what tells a bracket apart from a table.
                    'round' => 1,
                    'position' => $position++,
                    'best_of' => $stage->bestOfForRound(1),
                    'status' => TournamentMatch::STATUS_SCHEDULED,
                ]);

                $match->entrants()->create(['tournament_entrant_id' => $list[$i]->id, 'slot' => 1]);
                $match->entrants()->create(['tournament_entrant_id' => $list[$j]->id, 'slot' => 2]);
            }
        }
    }

    /**
     * Deal the seeds into groups in a snake: 1,2,3,4 then 4,3,2,1.
     *
     * @param  Collection<int, TournamentEntrant>  $entrants
     * @return array<int, Collection<int, TournamentEntrant>>
     */
    private function snake(Collection $entrants, int $groupCount): array
    {
        /*
         | Built in a loop rather than with array_fill, which would put the same
         | Collection instance in every slot: pushing into one would push into all of
         | them and every group would end up holding the whole field.
         */
        $buckets = [];

        for ($i = 0; $i < $groupCount; $i++) {
            $buckets[$i] = collect();
        }

        $index = 0;
        $forward = true;

        foreach ($entrants as $entrant) {
            $buckets[$index]->push($entrant);

            if ($forward) {
                $index++;

                if ($index === $groupCount) {
                    $index = $groupCount - 1;
                    $forward = false;
                }

                continue;
            }

            $index--;

            if ($index < 0) {
                $index = 0;
                $forward = true;
            }
        }

        return $buckets;
    }

    /**
     * How many groups to split into.
     *
     * Taken from advance_count when the operator set it, otherwise two, which is the
     * shape the organiser described for eight to twelve teams.
     */
    private function groupCount(TournamentStage $stage, int $entrants): int
    {
        $configured = (int) ($stage->match_count ?: 0);

        if ($configured >= 2) {
            return $configured;
        }

        return $entrants >= 16 ? 4 : 2;
    }
}
