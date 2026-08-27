<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BEFORE is a reserved word in MySQL, so a raw query touching that column
     * fails with a syntax error unless it is quoted. Eloquent quotes identifiers
     * for us, which hid the problem, but any reporting query or migration written
     * later would trip over it. Renamed now while there is almost no data rather
     * than leaving the trap in place.
     *
     * The create migration was since changed to write details_before and
     * details_after directly, which leaves this one with nothing to do on a fresh
     * database. It has to stay, because databases that already ran it have it in
     * their migrations table, but it now checks before acting: without the check it
     * fails on any new install, and on SQLite it fails loudly enough to stop the
     * whole test suite from booting.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('event_participant_changes', 'before')) {
            return;
        }

        Schema::table('event_participant_changes', function ($table) {
            $table->renameColumn('before', 'details_before');
            $table->renameColumn('after', 'details_after');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('event_participant_changes', 'details_before')) {
            return;
        }

        Schema::table('event_participant_changes', function ($table) {
            $table->renameColumn('details_before', 'before');
            $table->renameColumn('details_after', 'after');
        });
    }
};
