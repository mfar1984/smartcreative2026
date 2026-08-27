<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where results and standings live.
 *
 * Two decisions here came out of real bugs in the Campaign module, and both are
 * worth stating because they look like extra work until they are needed.
 *
 * Standings are recomputed by counting source rows, never by incrementing a stored
 * counter. Two referees saving at the same moment lose one of the two increments,
 * and the drift is silent.
 *
 * Champions copy the name and the totals rather than pointing at them, so correcting
 * a kill count three months after the prizes were handed out cannot quietly change
 * who won.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_match_entrants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_match_id')->constrained()->cascadeOnDelete();

            /*
            | Nullable because a bracket fixture exists before its competitors are
            | known: the semi final is drawn while the quarter finals are still to be
            | played, and the slot is filled when a winner arrives.
            */
            $table->foreignId('tournament_entrant_id')->nullable()
                ->constrained('tournament_entrants')->cascadeOnDelete();

            // Which side of a two-sided fixture. Null for lobbies and heats.
            $table->unsignedTinyInteger('slot')->nullable();

            // What the operator typed: placement, kills, players_present, sets,
            // finish_time, judge marks. JSON so a new sport needs no migration.
            $table->json('inputs')->nullable();

            $table->decimal('points', 10, 3)->default(0);

            // The per-component breakdown, so a standings table can show Kill, Place
            // and Penalty columns without running the engine again on every page.
            $table->json('component_points')->nullable();

            // Counts kept apart from points, because a WWCD is worth nothing but is
            // the first tie-break and would otherwise vanish into a zero.
            $table->json('component_counts')->nullable();

            $table->boolean('is_disqualified')->default(false);
            $table->timestamps();

            $table->unique(['tournament_match_id', 'tournament_entrant_id'], 'match_entrant_unique');
            $table->index(['tournament_entrant_id']);
        });

        Schema::create('tournament_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_match_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tournament_standings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tournament_stage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tournament_group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('tournament_entrant_id')->constrained('tournament_entrants')->cascadeOnDelete();

            $table->unsignedSmallInteger('played')->default(0);
            $table->unsignedSmallInteger('won')->default(0);
            $table->unsignedSmallInteger('lost')->default(0);

            // Every component total in one place, keyed the way the profile keys them.
            $table->json('component_totals')->nullable();
            $table->json('component_counts')->nullable();

            $table->decimal('total_points', 10, 3)->default(0);
            $table->unsignedSmallInteger('rank')->nullable();

            $table->boolean('is_disqualified')->default(false);
            $table->boolean('advances')->default(false);

            // True when this entrant is level with another after every tie-break in
            // the profile has been tried, so the screen can say the organiser must
            // settle it rather than inventing an order.
            $table->boolean('is_tied')->default(false);

            $table->timestamps();

            $table->unique(
                ['tournament_stage_id', 'tournament_group_id', 'tournament_entrant_id'],
                'standing_unique_per_group',
            );
            $table->index(['tournament_id', 'rank']);
        });

        Schema::create('tournament_champions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tournament_entrant_id')->nullable()
                ->constrained('tournament_entrants')->nullOnDelete();

            $table->unsignedTinyInteger('rank');

            // Copied, not referenced. A published podium is a record of what was
            // announced and must not change when its source is edited.
            $table->string('display_name');
            $table->decimal('total_points', 10, 3)->default(0);
            $table->json('component_totals')->nullable();

            $table->timestamp('published_at');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tournament_id', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_champions');
        Schema::dropIfExists('tournament_standings');
        Schema::dropIfExists('tournament_proofs');
        Schema::dropIfExists('tournament_match_entrants');
    }
};
