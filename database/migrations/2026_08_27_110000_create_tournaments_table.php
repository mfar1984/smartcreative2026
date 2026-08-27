<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tournament, and who is in it.
 *
 * Several rows may point at one event, and several may be ongoing at once. That is
 * the whole requirement: a PUBG tournament and a Mobile Legends tournament running
 * the same afternoon, each with its own format and its own scoring, and neither
 * able to disturb the other. Nothing here is a singleton and nothing is held in
 * configuration, so every read is scoped by tournament_id.
 *
 * Competitors are event_registrations. For a team event that row is the squad; for
 * an individual event it is the person. A second table of teams would mean two
 * sources of truth for the same fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            // "Main Event", "Ladies Division". One event can hold both.
            $table->string('name');

            // single_elim | group_single_elim | double_elim | battle_royale | race | judged
            $table->string('format', 30);

            $table->foreignId('point_rule_id')->constrained()->restrictOnDelete();

            // setup | ongoing | completed | published
            $table->string('status', 20)->default('setup');

            // manual | random | registration
            $table->string('seeding_method', 20)->default('manual');

            /*
            | A copy of Tournament Settings taken when this row was created, not a
            | reference to them. Changing the default buffer next month must not
            | alter a tournament already under way.
            */
            $table->json('settings')->nullable();

            $table->timestamp('draw_generated_at')->nullable();
            $table->timestamp('seeded_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['event_id', 'status']);
            $table->index('status');
        });

        Schema::create('tournament_entrants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_registration_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('seed')->nullable();

            // active | eliminated | disqualified | withdrawn
            $table->string('status', 20)->default('active');

            // True when the operator chose this registration deliberately rather
            // than taking it from the paid-and-confirmed import.
            $table->boolean('added_by_hand')->default(false);

            $table->string('reason')->nullable();
            $table->timestamps();

            // Enforced by the database, not only by the code that writes it: one
            // registration cannot enter the same tournament twice.
            $table->unique(['tournament_id', 'event_registration_id'], 'entrant_unique_per_tournament');
            $table->unique(['tournament_id', 'seed'], 'entrant_seed_unique_per_tournament');
            $table->index(['tournament_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_entrants');
        Schema::dropIfExists('tournaments');
    }
};
