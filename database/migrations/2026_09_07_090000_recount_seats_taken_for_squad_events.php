<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put the seat counter back in the unit it is now kept in.
 *
 * Squad entries used to take one place per person, so an event offering thirty
 * two places to teams recorded seven of them taken by a single seven player
 * squad. Every screen then reported the event a fifth full when one team had
 * entered, and the entry form would have refused the fifth squad outright.
 *
 * The counter is denormalised, so correcting the code that maintains it leaves
 * the existing rows wrong. They are recomputed here from the registrations
 * themselves, which are the record of what was actually taken.
 *
 * Individual events are left alone: one person taking one place was always
 * right for them, and their counter has been accurate all along.
 */
return new class extends Migration
{
    /**
     * Hardcoded rather than read from Event::MODE_MANAGER. A migration records
     * what the database held at a point in time, and must keep working if the
     * constant is later renamed.
     */
    private const MANAGER_MODE = 'manager';

    public function up(): void
    {
        foreach ($this->squadEventIds() as $eventId) {
            DB::table('events')->where('id', $eventId)->update([
                'seats_taken' => DB::table('event_registrations')
                    ->where('event_id', $eventId)
                    ->count(),
            ]);
        }
    }

    /**
     * Back to one place per person, which is what the old code would have
     * written. Restoring the previous numbers exactly is not possible, but this
     * reproduces them for any entry that has not changed since.
     */
    public function down(): void
    {
        foreach ($this->squadEventIds() as $eventId) {
            DB::table('events')->where('id', $eventId)->update([
                'seats_taken' => DB::table('event_participants')
                    ->join(
                        'event_registrations',
                        'event_registrations.id',
                        '=',
                        'event_participants.event_registration_id',
                    )
                    ->where('event_registrations.event_id', $eventId)
                    ->count(),
            ]);
        }
    }

    /**
     * Walked one event at a time rather than as a single correlated update, so
     * the statement stays portable across MySQL and the SQLite used by the test
     * suite. The number of events is small enough that it does not matter.
     *
     * @return array<int, int>
     */
    private function squadEventIds(): array
    {
        return DB::table('events')
            ->where('registration_mode', self::MANAGER_MODE)
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }
};
