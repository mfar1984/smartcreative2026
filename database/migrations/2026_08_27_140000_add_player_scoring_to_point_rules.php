<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal player scoring, held as a second and wholly separate ledger.
 *
 * These columns describe how an individual earns points. They deliberately do not
 * reuse `components`, `inputs` or `tiebreak`: a squad earns from placement, kills
 * and a WWCD, while a player earns from something else entirely, and placement has
 * no meaning for one person.
 *
 * `track_players` defaults to off so that every profile already seeded keeps
 * behaving exactly as it does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('point_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('point_rules', 'track_players')) {
                $table->string('track_players', 20)->default('off')->after('squad_size');
            }

            if (! Schema::hasColumn('point_rules', 'player_components')) {
                $table->json('player_components')->nullable()->after('tiebreak');
            }

            if (! Schema::hasColumn('point_rules', 'player_inputs')) {
                $table->json('player_inputs')->nullable()->after('player_components');
            }

            if (! Schema::hasColumn('point_rules', 'player_tiebreak')) {
                $table->json('player_tiebreak')->nullable()->after('player_inputs');
            }
        });
    }

    public function down(): void
    {
        Schema::table('point_rules', function (Blueprint $table) {
            foreach (['track_players', 'player_components', 'player_inputs', 'player_tiebreak'] as $column) {
                if (Schema::hasColumn('point_rules', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
