<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A logo supplied by whoever registers.
     *
     * Stored on the registration rather than on each participant, because it
     * belongs to the entry as a whole: a squad has one crest, not one per player.
     * An individual entry works the same way, so the column serves both modes.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('requires_logo')->default(false)->after('requires_ign');
        });

        Schema::table('event_registrations', function (Blueprint $table) {
            // Nullable regardless of the event setting: an event can be switched
            // to require a logo after entries have arrived, and those earlier
            // rows are not retroactively invalid.
            $table->string('logo_path')->nullable()->after('team_name');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('requires_logo');
        });

        Schema::table('event_registrations', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });
    }
};
