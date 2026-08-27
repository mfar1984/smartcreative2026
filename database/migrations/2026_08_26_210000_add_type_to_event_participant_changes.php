<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let the counter audit record three different things, not just one.
     *
     * The table was built for a single case: a squad place handed to somebody
     * else. Two more now write to it. Removing a player who is not coming has no
     * incoming person at all, so the "new" columns have to be allowed to stand
     * empty. Moving a player from one team to another has an incoming person who
     * came from somewhere, and where they came from is the whole point of the
     * record.
     *
     * Without a type column the three read identically in the audit, which would
     * make the audit useless for the question it exists to answer.
     */
    public function up(): void
    {
        Schema::table('event_participant_changes', function (Blueprint $table) {
            // swap | transfer | removed. Defaulted to swap so every row already
            // written keeps its correct meaning.
            $table->string('type', 20)->default('swap')->after('event_participant_id')->index();

            // Which entry the player was taken from, on a transfer. Null on a
            // swap and on a removal, because neither has an origin.
            //
            // nullOnDelete rather than cascade: deleting that team's entry must
            // not erase the record of a player having left it.
            $table->foreignId('from_registration_id')
                ->nullable()
                ->after('event_registration_id')
                ->constrained('event_registrations')
                ->nullOnDelete();
        });

        // A removal has nobody arriving, so these two stop being compulsory.
        Schema::table('event_participant_changes', function (Blueprint $table) {
            $table->string('new_name')->nullable()->change();
            $table->string('new_ic', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('event_participant_changes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('from_registration_id');
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });

        Schema::table('event_participant_changes', function (Blueprint $table) {
            $table->string('new_name')->nullable(false)->change();
            $table->string('new_ic', 32)->nullable(false)->change();
        });
    }
};
