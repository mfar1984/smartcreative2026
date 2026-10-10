<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monitoring accounts, and the events each one may watch.
 *
 * A MONITOR IS AN ORDINARY USERS ROW, exactly as a handler and a sponsor are: the
 * monitor role plus a flag, signing in at the same /admin/login. The handler feature
 * settled that shape and nothing here needs a different one.
 *
 * WHAT A MONITOR IS FOR
 *
 * A third party who runs an event THROUGH this system: the owner's words are that
 * they organise but only monitor. They read the real staff screens for their own
 * events — Participants, Attendance, Collection, Analytic Reporting, and the Coupon
 * screens — including identity card numbers and payment figures, and they may export
 * them. They write nothing at all, and they must not see an event they were not
 * given.
 *
 * WHY A PIVOT AND NOT A COLUMN
 *
 * The same reason tournament_handler is a pivot. One monitor watches several events
 * across a season, and one event can be watched by two people from the same
 * organisation. Until a row exists here a monitor is assigned to nothing, which is
 * why an unassigned monitor sees an empty list rather than everything — the safe
 * direction for a default.
 *
 * Nothing about an existing administrator reads either of these, so no admin role is
 * narrowed by the table being empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             | The flag the login landing, the Monitoring tab and the request scope
             | read. Written as a constant by the three monitor endpoints and by
             | nothing else, the same way is_handler and is_sponsor are.
             */
            $table->boolean('is_monitor')->default(false)->after('sponsor_committed_amount');
        });

        Schema::create('monitor_event', function (Blueprint $table) {
            $table->id();

            // Both cascade: an assignment describes a pairing, so it means nothing
            // once either side is gone, and leaving it behind would be a row
            // granting sight of an event that no longer exists.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            // One assignment per account per event. The form sends a tick list, so a
            // double submit must not be able to record the same pairing twice.
            $table->unique(['user_id', 'event_id'], 'monitor_event_unique');
        });
    }

    /**
     * Honestly reversible: everything up() added is new, and nothing existing moved.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitor_event');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_monitor');
        });
    }
};
