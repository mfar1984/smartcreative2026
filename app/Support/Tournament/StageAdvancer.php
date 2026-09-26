<?php

namespace App\Support\Tournament;

use App\Models\Tournament;
use App\Models\TournamentEntrant;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchEntrant;
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
         | The next stage was drawn from these qualifiers. Changing who is in now would
         | leave its fixtures describing a field that no longer exists, so nothing is
         | moved; drawing that stage again is what brings the two back into line.
         */
        if ($next->hasDraw()) {
            return;
        }

        $this->syncQualifiers($stage);
    }

    /**
     * Make who is in and who is out agree with a finished stage's standings as they
     * stand now.
     *
     * Eliminating on the first close was not enough. A correction saved after the
     * stage had closed could lift a squad into the qualifying places, and nothing put
     * it back in: v18 finished 16th of a top 16 and was still out, while the squad it
     * overtook was eliminated as well, leaving fifteen for the Final.
     *
     * Only this stage's own table is read, and only active and eliminated squads are
     * moved. A squad that withdrew or was disqualified stays as it was.
     */
    public function syncQualifiers(TournamentStage $stage): void
    {
        $through = $stage->tournament
            ->standings()
            ->where('tournament_stage_id', $stage->id)
            ->where('advances', true)
            ->pluck('tournament_entrant_id');

        /*
         | Only a squad drawn into this stage can qualify from it. A table can list
         | more than that, a bracket's lists the whole tournament, and a squad knocked
         | out a stage earlier must not come back in on a tie at nil.
         */
        $drawnHere = TournamentMatchEntrant::query()
            ->whereHas('match', fn ($query) => $query->where('tournament_stage_id', $stage->id))
            ->whereNotNull('tournament_entrant_id')
            ->distinct()
            ->pluck('tournament_entrant_id');

        $through = $through->intersect($drawnHere)->values();

        // A stage with no cut has nothing to decide. Checked after the intersect too,
        // because an empty list below would read as "nobody qualified" and put every
        // squad out.
        if ($through->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($stage, $through) {
            // In: qualified here but marked out, which only a correction saved after
            // the stage closed can cause.
            TournamentEntrant::whereIn('id', $through)
                ->where('status', TournamentEntrant::STATUS_ELIMINATED)
                ->update(['status' => TournamentEntrant::STATUS_ACTIVE]);

            // Out: everybody else still in, exactly as closing the stage always did.
            $stage->tournament
                ->entrants()
                ->where('status', TournamentEntrant::STATUS_ACTIVE)
                ->whereNotIn('id', $through)
                ->update(['status' => TournamentEntrant::STATUS_ELIMINATED]);
        });
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
