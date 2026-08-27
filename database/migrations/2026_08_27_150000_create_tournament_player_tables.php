<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The player ledger: personal figures, kept entirely apart from team figures.
 *
 * Nothing in these tables is ever added to `tournament_standings` or to
 * `tournament_champions`, and nothing from those is ever added here. That is the
 * whole point. If player points fed into team points, player scoring could not be
 * optional, because the podium would then change depending on whether an operator
 * found time to fill the rows in.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | Hung off tournament_match_entrants rather than off the match plus the
         | participant. The team-match row already knows which team and which match,
         | so there is no repeated column, discarding a draw cascades in one step,
         | and showing a player sum beside the team's own figure is one query
         | against one parent instead of a three-way join.
         */
        if (! Schema::hasTable('tournament_match_players')) {
            Schema::create('tournament_match_players', function (Blueprint $table) {
                $table->id();

                $table->foreignId('tournament_match_entrant_id')
                    ->constrained('tournament_match_entrants')
                    ->cascadeOnDelete();

                $table->foreignId('event_participant_id')
                    ->constrained('event_participants')
                    ->cascadeOnDelete();

                // Attendance, kept apart from the figures. A player who turned up
                // and got no kills is not the same as one who did not play.
                $table->boolean('took_part')->default(true);

                $table->json('inputs')->nullable();
                $table->decimal('points', 10, 3)->default(0);
                $table->json('component_points')->nullable();
                $table->json('component_counts')->nullable();

                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

                $table->timestamps();

                $table->unique(
                    ['tournament_match_entrant_id', 'event_participant_id'],
                    'tmp_entrant_participant_unique',
                );
                $table->index('event_participant_id');
            });
        }

        /*
         | Always recomputed by counting these source rows, never incremented. Same
         | reason as tournament_standings: an incremented counter loses writes when
         | two people save at once, which happened for real in the Campaign module.
         */
        if (! Schema::hasTable('tournament_player_standings')) {
            Schema::create('tournament_player_standings', function (Blueprint $table) {
                $table->id();

                $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();

                // Null stage means the whole tournament rather than one stage.
                $table->foreignId('tournament_stage_id')->nullable()
                    ->constrained('tournament_stages')->cascadeOnDelete();

                $table->foreignId('tournament_entrant_id')
                    ->constrained('tournament_entrants')->cascadeOnDelete();

                $table->foreignId('event_participant_id')
                    ->constrained('event_participants')->cascadeOnDelete();

                $table->string('display_name');
                $table->string('ign', 60)->nullable();

                $table->unsignedSmallInteger('matches_played')->default(0);
                $table->json('component_totals')->nullable();
                $table->json('component_counts')->nullable();
                $table->decimal('total_points', 10, 3)->default(0);
                $table->unsignedSmallInteger('rank')->nullable();

                // The entrant was thrown out; the player's own figures stay visible.
                $table->boolean('entrant_is_disqualified')->default(false);

                $table->timestamps();

                $table->unique(
                    ['tournament_id', 'tournament_stage_id', 'event_participant_id'],
                    'tps_stage_participant_unique',
                );
                $table->index(['tournament_id', 'rank']);
            });
        }

        /*
         | Every meaningful field is COPIED, not referenced. Correcting a kill three
         | months after the trophy was handed over must not change the MVP that was
         | announced. Same rule as tournament_champions.
         */
        if (! Schema::hasTable('tournament_player_awards')) {
            Schema::create('tournament_player_awards', function (Blueprint $table) {
                $table->id();

                $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
                $table->foreignId('event_participant_id')->nullable()
                    ->constrained('event_participants')->nullOnDelete();

                $table->string('award_key', 40);
                $table->string('award_label');
                $table->unsignedSmallInteger('rank');

                $table->string('display_name');
                $table->string('ign', 60)->nullable();
                $table->string('entrant_name');
                $table->decimal('total_points', 10, 3)->default(0);
                $table->json('component_totals')->nullable();

                $table->timestamp('published_at')->nullable();
                $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();

                $table->timestamps();

                $table->unique(['tournament_id', 'award_key', 'rank'], 'tpa_award_rank_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_player_awards');
        Schema::dropIfExists('tournament_player_standings');
        Schema::dropIfExists('tournament_match_players');
    }
};
