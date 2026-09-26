<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many players are recorded per match.
 *
 * Personal figures have always been entered a whole roster at a time: open a squad,
 * tick who played, fill in each of them. That works for a five-a-side where ten people
 * are on the screen. It does not work for a battle royale lobby, where twenty squads of
 * four is eighty rows for one match and nobody is going to type them.
 *
 * Setting this to eight says "record the eight best of the whole match", which is how a
 * player leaderboard is actually kept. Left null, nothing changes and the roster
 * behaviour stands, so a bracket profile is untouched.
 *
 * Lives on the point rule rather than on the event, because it only means anything
 * where track_players is on. That is the same switch that decides whether a sport has
 * personal figures at all, so badminton and racing never see this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('point_rules', function (Blueprint $table) {
            $table->unsignedSmallInteger('player_slots')
                ->nullable()
                ->after('player_tiebreak');
        });
    }

    public function down(): void
    {
        Schema::table('point_rules', function (Blueprint $table) {
            $table->dropColumn('player_slots');
        });
    }
};
