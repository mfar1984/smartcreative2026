<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the manager be a player as well, without being entered twice.
 *
 * A squad short of people needs the manager on the roster, and the only way to do
 * that before was a second row with the same name and the same identity card,
 * which the duplicate card check rightly refused.
 *
 * Deliberately a flag on the existing role rather than a third role value. Fourteen
 * places already branch on role: five look the manager up, four collect the
 * players, and the rest decide whether somebody can be swapped or removed. A new
 * enum value would have passed every one of them silently and wrongly, dropping the
 * playing manager out of tournament match line-ups. A flag leaves "who is the
 * manager" answered exactly as before, and moves the only question that changed,
 * "who is playing", into one scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_participants', function (Blueprint $table) {
            $table->boolean('also_plays')->default(false)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('event_participants', function (Blueprint $table) {
            $table->dropColumn('also_plays');
        });
    }
};
