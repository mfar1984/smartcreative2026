<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One In-Game ID switch becomes four fields, each asked and required separately.
 *
 * Before this, requires_ign turned on Player ID and Server ID together and made
 * both compulsory, with no way to ask for one without the other and no way to ask
 * for something without insisting on it. A tournament that only needs a Player ID
 * had to demand a Server ID nobody had.
 *
 * Player In-Game Name is new. It is the name shown in the game, which is what an
 * organiser reads off a scoreboard, and it is not the name on an identity card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Asked at all.
            $table->boolean('asks_player_id')->default(false)->after('max_players');
            $table->boolean('asks_server_id')->default(false)->after('asks_player_id');
            $table->boolean('asks_ign_name')->default(false)->after('asks_server_id');
            $table->boolean('asks_logo')->default(false)->after('asks_ign_name');

            // Compulsory once asked. Meaningless while the matching asks_* is off,
            // and the form request forces them back to false in that case so a
            // stored pair can never say "not asked but required".
            $table->boolean('requires_player_id')->default(false)->after('asks_logo');
            $table->boolean('requires_server_id')->default(false)->after('requires_player_id');
            $table->boolean('requires_ign_name')->default(false)->after('requires_server_id');
        });

        /*
        | Carry the old settings across so no existing event changes behaviour.
        |
        | requires_ign meant "ask for Player ID and Server ID, both compulsory",
        | so it maps to asked and required on both. requires_logo meant "ask for a
        | logo and insist on it", so it becomes asks_logo while requires_logo
        | itself stays on as the compulsory half of the pair.
        */
        DB::table('events')->update([
            'asks_player_id' => DB::raw('requires_ign'),
            'asks_server_id' => DB::raw('requires_ign'),
            'requires_player_id' => DB::raw('requires_ign'),
            'requires_server_id' => DB::raw('requires_ign'),
            'asks_logo' => DB::raw('requires_logo'),
        ]);

        /*
        | requires_ign is dropped rather than left behind. A column nothing reads
        | is a trap: the next person to touch this will not know which of the two
        | is the truth. Event::asksIgn() now answers the same question by asking
        | whether any of the three is switched on.
        */
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('requires_ign');
        });

        Schema::table('event_participants', function (Blueprint $table) {
            // Nullable on purpose, like the two beside it: switching the setting on
            // later must not invalidate people who registered before it existed.
            $table->string('ign_name', 60)->nullable()->after('ign_server_id');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('requires_ign')->default(false)->after('max_players');
        });

        // Rolling back keeps the event asking for a game account if it asked for
        // either id, which is the closest the single switch can get.
        DB::table('events')->update([
            'requires_ign' => DB::raw('(asks_player_id OR asks_server_id)'),
        ]);

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn([
                'asks_player_id',
                'asks_server_id',
                'asks_ign_name',
                'asks_logo',
                'requires_player_id',
                'requires_server_id',
                'requires_ign_name',
            ]);
        });

        Schema::table('event_participants', function (Blueprint $table) {
            $table->dropColumn('ign_name');
        });
    }
};
