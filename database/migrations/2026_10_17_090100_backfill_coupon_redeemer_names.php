<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put the redeemer's name on the ledger rows that were written before the column
 * existed.
 *
 * WHY THEY ARE BLANK, WHICH IS NOT A BUG IN THE WRITER
 *
 * 2026_10_12_090000 turned coupon_codes into a redemption ledger, and real redemptions
 * started landing in it. 2026_10_14_090000 added event_participant_id and
 * participant_name — and deliberately did not touch the rows already there, saying so
 * in its own note. It shipped as a separate deployment, so every use redeemed in
 * between carries NULL in both columns for ever.
 *
 * That is why the owner's sponsor screen showed "USED BY —" against a registration
 * that plainly still exists and plainly has one named participant: the row predates
 * the column. CouponRedeemer records the name correctly and is asserted doing it
 * through the real public registration form, for one person and for a group of ten.
 * There was nothing to fix in the write path; there was data to repair.
 *
 * HOW A NAME IS WORKED OUT, AND WHY IT IS THE ORIGINAL PAIRING AND NOT A GUESS
 *
 * The same rule CouponRedeemer::peopleCovered() applies, run backwards:
 *
 *   a row that already names a participant takes that participant's name, which is
 *   exact.
 *
 *   otherwise the rows of one redemption — one registration, one batch, ordered by id
 *   — are paired against that registration's participants ordered by id, and ONLY
 *   when the two counts match exactly. That is the order writeLedger() wrote them in,
 *   so position for position reproduces who each row actually covered.
 *
 *   a count that does not match is LEFT ALONE. A single use covering a group is not
 *   attributable to any one of them, and a roster edited since the redemption cannot
 *   be realigned — putting somebody's name on either would be a guess with a real
 *   person's name on it, on a screen a sponsor reads.
 *
 * Runs after the orphan release beside it, so rows belonging to deleted registrations
 * are already gone rather than being examined here and skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        $blank = DB::table('coupon_codes')
            ->whereNull('participant_name')
            ->whereNotNull('event_registration_id')
            ->orderBy('id')
            ->get(['id', 'coupon_id', 'event_registration_id', 'event_participant_id']);

        if ($blank->isEmpty()) {
            return;
        }

        // The exact cases: the row still names its participant, so there is nothing to
        // work out. Only the name was missing.
        foreach ($blank->whereNotNull('event_participant_id') as $row) {
            $name = DB::table('event_participants')->where('id', $row->event_participant_id)->value('full_name');

            if (filled($name)) {
                DB::table('coupon_codes')->where('id', $row->id)->update(['participant_name' => $name]);
            }
        }

        /*
         | The rest, by position within one redemption. Grouped by registration AND
         | batch because the pairing is per claim: two batches on one registration
         | would be two separate claims, each with its own run of rows.
         */
        $groups = $blank
            ->whereNull('event_participant_id')
            ->groupBy(fn ($row) => $row->event_registration_id.':'.$row->coupon_id);

        foreach ($groups as $rows) {
            $rows = $rows->values();

            $people = DB::table('event_participants')
                ->where('event_registration_id', $rows->first()->event_registration_id)
                ->orderBy('id')
                ->get(['id', 'full_name']);

            // The redeemer's own rule: name somebody only when the uses and the heads
            // line up exactly.
            if ($people->count() !== $rows->count()) {
                continue;
            }

            foreach ($rows as $index => $row) {
                $person = $people[$index];

                DB::table('coupon_codes')->where('id', $row->id)->update([
                    'event_participant_id' => $person->id,
                    'participant_name' => $person->full_name,
                ]);
            }
        }
    }

    /**
     * Deliberately a no-op.
     *
     * The names written above are the correct ones, recovered from the registrations
     * that are still standing. Blanking them again on the way down would do nothing
     * but reinstate the defect, and the columns themselves belong to
     * 2026_10_14_090000, which drops them properly if the schema really has to go
     * back.
     */
    public function down(): void
    {
        // Nothing to undo. See the note above.
    }
};
