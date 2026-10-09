<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which handlers run which tournament.
 *
 * Additive, and the only thing that binds a handler to a competition. Until a row
 * exists here a handler is assigned to nothing, which is why an unassigned handler
 * sees an empty list rather than everything.
 *
 * A pivot rather than a column on tournaments, because a tournament is run by a
 * team on the day: a referee on the desk and an organiser on the floor are two
 * handlers on one tournament, and the same person runs several of them over a
 * weekend.
 *
 * Nothing about an existing administrator reads this table, so no admin role is
 * narrowed by it being empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_handler', function (Blueprint $table) {
            $table->id();

            // Both cascade: an assignment describes a pairing, so it means nothing
            // once either side is gone, and leaving it behind would be a row
            // granting access to a tournament that no longer exists.
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            // One assignment per person per tournament. The form sends a tick list,
            // so a double submit must not be able to record the same handler twice.
            $table->unique(['tournament_id', 'user_id'], 'tournament_handler_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_handler');
    }
};
