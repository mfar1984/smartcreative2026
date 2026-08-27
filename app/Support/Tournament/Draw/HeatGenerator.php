<?php

namespace App\Support\Tournament\Draw;

use App\Models\TournamentGroup;
use App\Models\TournamentMatch;
use App\Models\TournamentStage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Races and judged events. One start, one result per competitor.
 *
 * The simplest shape of all: the race itself is the match, and every competitor is a
 * line on it. Several heats exist only when the field is too large to start at once,
 * and each heat is then its own match with its own result.
 */
final class HeatGenerator implements DrawGenerator
{
    public function refusal(TournamentStage $stage, Collection $entrants): ?string
    {
        if ($entrants->isEmpty()) {
            return 'A heat needs at least one competitor.';
        }

        return null;
    }

    public function generate(TournamentStage $stage, Collection $entrants): void
    {
        $heatCount = max(1, (int) ($stage->match_count ?: 1));

        DB::transaction(function () use ($stage, $entrants, $heatCount) {
            $buckets = $this->split($entrants->values(), $heatCount);

            foreach ($buckets as $index => $members) {
                if ($members->isEmpty()) {
                    continue;
                }

                $heat = TournamentGroup::create([
                    'tournament_stage_id' => $stage->id,
                    'name' => $heatCount === 1 ? 'Race' : 'Heat ' . ($index + 1),
                    'sequence' => $index + 1,
                ]);

                $match = TournamentMatch::create([
                    'tournament_id' => $stage->tournament_id,
                    'tournament_stage_id' => $stage->id,
                    'tournament_group_id' => $heat->id,
                    'round' => null,
                    'position' => $index + 1,
                    'best_of' => 1,
                    'status' => TournamentMatch::STATUS_SCHEDULED,
                ]);

                foreach ($members as $member) {
                    $match->entrants()->create([
                        'tournament_entrant_id' => $member->id,
                        'slot' => null,
                    ]);
                }
            }
        });
    }

    /**
     * Deal the field across the heats in order.
     *
     * Not snaked, unlike groups and lobbies: heats do not compete against each other
     * for a result, so balancing them by seed buys nothing.
     *
     * @param  Collection<int, \App\Models\TournamentEntrant>  $entrants
     * @return array<int, Collection<int, \App\Models\TournamentEntrant>>
     */
    private function split(Collection $entrants, int $heatCount): array
    {
        $perHeat = (int) ceil($entrants->count() / $heatCount);

        return $entrants->chunk(max(1, $perHeat))->values()->all();
    }
}
