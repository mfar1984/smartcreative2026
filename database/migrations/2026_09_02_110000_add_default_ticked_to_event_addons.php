<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A third state for an add-on: offered ticked, but declinable.
 *
 * is_required already covers "cannot be declined", and a plain optional add-on
 * covers "not chosen unless asked for". Neither expresses the common case of a
 * shirt everybody is assumed to want, where somebody who opts out should be told
 * what they are giving up before they carry on.
 *
 * The reminder is what they are told. It lives beside the flag rather than in a
 * translation file because it is written per add-on by whoever set the add-on up,
 * and it is the only place that knows what declining this particular thing costs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_addons', function (Blueprint $table) {
            $table->boolean('is_checked_by_default')->default(false)->after('is_required');

            // Nullable, and only shown when the buyer actually unticks the box.
            // Text rather than string: this is a sentence or two of explanation,
            // not a label.
            $table->text('uncheck_reminder')->nullable()->after('is_checked_by_default');
        });
    }

    public function down(): void
    {
        Schema::table('event_addons', function (Blueprint $table) {
            $table->dropColumn(['is_checked_by_default', 'uncheck_reminder']);
        });
    }
};
