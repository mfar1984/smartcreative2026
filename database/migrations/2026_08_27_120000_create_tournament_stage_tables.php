<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stages, groups and fixtures.
 *
 * A tournament holds several stages of different kinds, which is how a group stage
 * followed by a knockout works, and how Single Elimination down to the last four
 * followed by a Double Elimination playoff works. One format per tournament would
 * not have covered either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // group | bracket | lobby | heat
            $table->string('type', 20);

            $table->unsignedSmallInteger('sequence');

            // How many from each group carry on to the next stage.
            $table->unsignedSmallInteger('advance_count')->default(0);

            // Lobbies and heats: how many matches each one plays.
            $table->unsignedSmallInteger('match_count')->default(1);

            // Bracket: round number to best-of, as {"1":1,"2":3,"3":5}.
            $table->json('best_of')->nullable();

            // pending | ongoing | completed
            $table->string('status', 20)->default('pending');

            $table->timestamp('drawn_at')->nullable();
            $table->foreignId('drawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tournament_id', 'sequence']);
            $table->index(['tournament_id', 'status']);
        });

        Schema::create('tournament_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_stage_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->timestamps();

            $table->unique(['tournament_stage_id', 'name']);
        });

        Schema::create('tournament_matches', function (Blueprint $table) {
            $table->id();

            /*
            | Denormalised on purpose. Every standings query filters by tournament,
            | and without this column each one would join two levels up to reach it.
            */
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();

            $table->foreignId('tournament_stage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tournament_group_id')->nullable()->constrained()->cascadeOnDelete();

            // Bracket position. Null for a lobby or a heat, where there is no tree.
            $table->unsignedSmallInteger('round')->nullable();
            $table->unsignedSmallInteger('position')->nullable();

            // upper | lower | final, for double elimination.
            $table->string('bracket_side', 10)->nullable();

            /*
            | Where the winner and loser of this match go next. Written when the draw
            | is generated, so advancing somebody is following a pointer rather than
            | working the tree out again from the round number.
            */
            $table->foreignId('winner_to_match_id')->nullable()->constrained('tournament_matches')->nullOnDelete();
            $table->unsignedTinyInteger('winner_to_slot')->nullable();
            $table->foreignId('loser_to_match_id')->nullable()->constrained('tournament_matches')->nullOnDelete();
            $table->unsignedTinyInteger('loser_to_slot')->nullable();

            $table->unsignedSmallInteger('best_of')->default(1);
            $table->string('map')->nullable();
            $table->dateTime('scheduled_at')->nullable();

            // scheduled | awaiting_result | completed | walkover
            $table->string('status', 20)->default('scheduled');

            $table->foreignId('winner_entrant_id')->nullable()
                ->constrained('tournament_entrants')->nullOnDelete();

            // null | walkover | forfeit | disqualification | withdrawal
            $table->string('resolution', 20)->nullable();
            $table->string('reason')->nullable();

            $table->foreignId('scored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scored_at')->nullable();
            $table->timestamps();

            $table->index(['tournament_id', 'status']);
            $table->index(['tournament_stage_id', 'round', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_matches');
        Schema::dropIfExists('tournament_groups');
        Schema::dropIfExists('tournament_stages');
    }
};
