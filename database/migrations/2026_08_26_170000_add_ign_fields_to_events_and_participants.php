<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * In game identifiers, for events where they are needed.
     *
     * A tournament has to know which account is playing, and a name on an
     * identity card does not say that. A course or a conference has no such
     * thing, so this is opt in per event rather than two more boxes everyone has
     * to look at.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Whether the public form asks each person for their game account.
            $table->boolean('requires_ign')->default(false)->after('max_players');
        });

        Schema::table('event_participants', function (Blueprint $table) {
            // Nullable regardless of the event setting: an event can be switched
            // to require these after people have already registered, and the
            // rows that came in earlier are not retroactively wrong.
            $table->string('ign_player_id', 60)->nullable()->after('ic_number');
            $table->string('ign_server_id', 60)->nullable()->after('ign_player_id');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('requires_ign');
        });

        Schema::table('event_participants', function (Blueprint $table) {
            $table->dropColumn(['ign_player_id', 'ign_server_id']);
        });
    }
};
