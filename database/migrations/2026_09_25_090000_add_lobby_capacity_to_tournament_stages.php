<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many entrants one lobby holds.
 *
 * Was a constant of sixteen in the lobby generator, on the reasoning that sixteen
 * squads is what a competitive room holds. That is the PMPL convention rather than a
 * limit of the game: a custom room takes considerably more, and an organiser running
 * twenty teams in one room had no way to say so. Twenty entrants were split into two
 * lobbies of ten, which is a different competition from the one being run.
 *
 * Per stage rather than per tournament, because a qualifier of twenty and a final of
 * sixteen is a real shape, and because a tournament's settings are frozen when it is
 * created: a key added afterwards could never appear in one that already exists.
 *
 * Nullable, and the generator reads sixteen when it is null, so every stage already
 * drawn keeps exactly the shape it was drawn with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournament_stages', function (Blueprint $table) {
            $table->unsignedSmallInteger('lobby_capacity')
                ->nullable()
                ->after('match_count');
        });
    }

    public function down(): void
    {
        Schema::table('tournament_stages', function (Blueprint $table) {
            $table->dropColumn('lobby_capacity');
        });
    }
};
