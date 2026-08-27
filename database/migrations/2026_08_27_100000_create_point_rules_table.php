<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable scoring profiles.
 *
 * The whole reason this table exists is that eleven sports cannot each have their
 * own columns. Tennis has no kills, aerobics has no finishing position, a run has
 * neither. Fixed columns would mean a dozen of them with nine always null, and a
 * twelfth sport would mean a migration.
 *
 * So the components that earn points are a list, the inputs the score form asks
 * for are a list, and the tie-break order is a list. Adding a sport is a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // battle_royale | bracket | race | judged
            $table->string('kind', 20)->index();

            /*
            | How many make a full squad, for the shortfall penalty to measure
            | against. Held here rather than read from the event, because one event
            | can run an Open and a Ladies division of different sizes. Null for
            | sports where a squad size is meaningless.
            */
            $table->unsignedSmallInteger('squad_size')->nullable();

            /*
            | The components that turn raw input into points. Five types cover every
            | sport asked for: table, per_unit, bonus, penalty_table, aggregate.
            */
            $table->json('components');

            // Which fields the score entry form must ask for, and how to validate
            // them. This is what lets one form serve every sport.
            $table->json('inputs');

            // Ordered component keys. PMPL is ["wwcd","placement","kills"].
            $table->json('tiebreak');

            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_rules');
    }
};
