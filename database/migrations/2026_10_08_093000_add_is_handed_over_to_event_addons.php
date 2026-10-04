<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an add-on is a thing somebody physically takes away on the day.
 *
 * The table already says what an add-on costs, who it is asked of and how it is
 * chosen. It says nothing about whether anything leaves a table and ends up in a
 * pair of hands. A shirt does; a banquet seat, an insurance line and a parking
 * pass do not, and an event can sell both at once.
 *
 * Without the distinction a collection screen has to list every add-on and hope
 * the operator ignores the ones that are not real objects, which is exactly how a
 * shirt gets ticked off against an insurance row.
 *
 * Where and when it is handed over is NOT stored here. The add-on already belongs
 * to an event, and the event already carries starts_at, time, location and
 * address. Re-asking would create a second answer free to disagree with the
 * first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_addons', function (Blueprint $table) {
            /*
             | False, so every add-on already sold keeps behaving exactly as it
             | does today and nothing becomes collectable by surprise. Operators
             | opt a shirt in; nothing opts itself in.
             */
            $table->boolean('is_handed_over')->default(false)->after('per_participant');
        });
    }

    public function down(): void
    {
        Schema::table('event_addons', function (Blueprint $table) {
            $table->dropColumn('is_handed_over');
        });
    }
};
