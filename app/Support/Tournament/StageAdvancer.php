<?php

namespace App\Support\Tournament;

use App\Models\Tournament;
use App\Models\TournamentEntrant;
use App\Models\TournamentMatch;
use App\Models\TournamentStage;
use Illuminate\Support\Facades\DB;

/**
 * Moves winners along.
 *
 * Two separate jobs that both happen when a result lands.
 *
 * Inside a bracket, the winner of a fixture is seated in the next one. The pointer
 * was written when the draw was generated, so this follows it rather than working the
 * tree out again from a round number.
 *
 * Between stages, once every fixture in a stage has a result, whoever the standings
 * marked as advancing carries on and everybody else is eliminated.
 */
final class StageAdvancer
{
    public function advance(TournamentMatch $match): void
    {
        $this->seatNextFixture($match);
        $this->closeStageIfPlayedOut($match->stage);
    }

    /**
     * Put the winner into the fixture it feeds, and the loser into theirs.
     */
    private function seatNextFixture(TournamentMatch $match): void
    {
        if ($match->winner_entrant_id === null) {
            return;
        }

        $loserId = $match->entrants()
            ->whereNotNull('tournament_entrant_id')
            ->where('tournament_entrant_id', '!=', $match->winner_entrant_id)
            ->value('tournament_entrant_id');

        DB::transaction(function () use ($match, $loserId) {
            $this->seat($match->winner_to_match_id, $match->winner_to_slot, $match->winner_entrant_id);

            // Only a double elimination bracket wires losers anywhere. In a single
            // elimination they are simply out.
            if ($loserId !== null && $match->loser_to_match_id !== null) {
                $this->seat($match->loser_to_match_id, $match->loser_to_slot, $loserId);

                return;
            }

            /*
             | Knocked out. Marked on the entrant so a bracket can show who is still in
             | without reading every fixture, and so the standings can separate the
             | eliminated from the withdrawn.
             */
            if ($loserId !== null && $match->round !== null) {
                TournamentEntrant::whereKey($loserId)
                    ->where('status', TournamentEntrant::STATUS_ACTIVE)
                    ->update(['status' => TournamentEntrant::STATUS_ELIMINATED]);
            }
        });
    }

    private function seat(?int $matchId, ?int $slot, int $entrantId): void
    {
        if ($matchId === null) {
            return;
        }

        $target = TournamentMatch::find($matchId);

        if ($target === null) {
            return;
        }

        // Idempotent: scoring a match twice must not put the same competitor into the
        // next fixture twice, and the unique index would refuse the second write anyway.
        $existing = $target->entrants()->where('slot', $slot)->first();

        if ($existing !== null) {
            $existing->update(['tournament_entrant_id' => $entrantId]);

            return;
        }

        if ($target->entrants()->where('tournament_entrant_id', $entrantId)->exists()) {
            return;
        }

        $target->entrants()->create([
            'tournament_entrant_id' => $entrantId,
            'slot' => $slot,
        ]);
    }

    /**
     * Close a stage that has no fixtures left, and carry its qualifiers forward.
     */
    private function closeStageIfPlayedOut(?TournamentStage $stage): void
    {
        if ($stage === null || ! $stage->isPlayedOut()) {
            return;
        }

        $stage->update(['status' => TournamentStage::STATUS_COMPLETED]);

        $next = $stage->tournament
            ->stages()
            ->where('sequence', '>', $stage->sequence)
            ->orderBy('sequence')
            ->first();

        if ($next === null) {
            $this->completeTournamentIfDone($stage->tournament);

            return;
        }

        /*
         | Everybody the standings did not mark as advancing is out. Done here rather
         | than when the next draw is generated, so the entrant list is truthful the
         | moment a stage finishes rather than only later.
         */
        $advancingIds = $stage->tournament
            ->standings()
            ->where('tournament_stage_id', $stage->id)
            ->where('advances', true)
            ->pluck('tournament_entrant_id');

        if ($advancingIds->isEmpty()) {
            return;
        }

        $stage->tournament
            ->entrants()
            ->where('status', TournamentEntrant::STATUS_ACTIVE)
            ->whereNotIn('id', $advancingIds)
            ->update(['status' => TournamentEntrant::STATUS_ELIMINATED]);
    }

    /**
     * A tournament is finished when every stage is, and nothing is left to play.
     */
    private function completeTournamentIfDone(Tournament $tournament): void
    {
        $outstanding = $tournament->matches()
            ->whereIn('status', [TournamentMatch::STATUS_SCHEDULED, TournamentMatch::STATUS_AWAITING])
            ->exists();

        if ($outstanding || $tournament->isPublished()) {
            return;
        }

        $tournament->update([
            'status' => Tournament::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }
}
