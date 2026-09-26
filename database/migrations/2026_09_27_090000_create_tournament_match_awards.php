<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Star of the Match: the awards a game announces at the end of one fixture.
 *
 * Kept apart from the player leaderboard on purpose. The leaderboard is a sum over a
 * tournament and moves every time a result is saved; an award like Going All Out is a
 * statement about one match, chosen by whoever read the result screen, and it must
 * read the same next year as it did the night it was given.
 *
 * So two things are added. The point rule gains the list of awards it hands out and
 * the figures each one carries, and every award given is written to its own row with
 * those labels and figures copied onto it, the way a published podium is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('point_rules', function (Blueprint $table) {
            // [{key, label, headline, fields: [{key, label, decimal}]}]
            $table->json('match_awards')->nullable()->after('player_slots');
        });

        Schema::create('tournament_match_awards', function (Blueprint $table) {
            $table->id();

            // Held directly as well as through the match, so a profile can ask for a
            // person's awards across tournaments without walking the match table.
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();

            // Discarding a draw deletes its fixtures, and an award for a fixture that
            // no longer exists describes nothing, so it goes with it.
            $table->foreignId('tournament_match_id')
                ->constrained('tournament_matches')
                ->cascadeOnDelete();

            /*
             | Nulled rather than cascaded. Removing somebody from an entry is an admin
             | correction to a registration; it should not rewrite what a match was
             | seen to produce. The copied name below still says who it was.
             */
            $table->foreignId('tournament_entrant_id')->nullable()
                ->constrained('tournament_entrants')->nullOnDelete();
            $table->foreignId('event_participant_id')->nullable()
                ->constrained('event_participants')->nullOnDelete();

            // Which award, copied so renaming it on the rule later does not rename
            // one already given.
            $table->string('award_key', 40);
            $table->string('award_label', 120);

            // Its place in the rule's list when it was given, which decides the
            // colour the card is drawn in. Copied for the same reason as the label.
            $table->unsignedTinyInteger('award_position')->default(0);

            $table->string('headline_key', 40);
            $table->json('fields');
            $table->json('figures');

            // The public name at the time, never the name on an identity card.
            $table->string('display_name');
            $table->string('ign', 60)->nullable();
            $table->string('entrant_name');

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One holder per award per fixture.
            $table->unique(['tournament_match_id', 'award_key'], 'tma_match_award_unique');
            $table->index('event_participant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_match_awards');

        Schema::table('point_rules', function (Blueprint $table) {
            $table->dropColumn('match_awards');
        });
    }
};
