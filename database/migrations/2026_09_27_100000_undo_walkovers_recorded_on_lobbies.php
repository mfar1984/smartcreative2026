<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put back lobby fixtures that were marked as a walkover after being played.
 *
 * A walkover settles a two-sided match. The Nobody Played panel offered it on lobbies
 * too, where it flagged the whole fixture as a walkover, changed no squad, and named a
 * "winner" in a match that has no winner. Every squad's result was still there, so the
 * fixture was played and is marked played again here.
 *
 * Only a lobby or a heat (no round) and only one that has results on it. A lobby that
 * truly was not played has no results and is left alone.
 *
 * The squad the operator meant is not guessed at. Withdrawing a squad is recorded
 * again from the score screen, where it is now a decision about that squad.
 */
return new class extends Migration
{
    public function up(): void
    {
        $scored = DB::table('tournament_match_entrants')
            ->whereNotNull('inputs')
            ->select('tournament_match_id');

        DB::table('tournament_matches')
            ->whereNull('round')
            ->where('status', 'walkover')
            ->whereIn('id', $scored)
            ->update([
                'status' => 'completed',
                'resolution' => null,
                'reason' => null,
                'winner_entrant_id' => null,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Nothing to restore: the walkover described nothing that happened.
    }
};
