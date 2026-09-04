<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How much of the charge has actually been received.
 *
 * Distinct from `amount`, which is what is owed, and from `refunded_amount`,
 * which is what went back. Without this the only answer available was a status,
 * so an entry was either fully settled or not settled at all.
 *
 * Denormalised from event_registration_payments on purpose. Every money figure in
 * the admin is a SUM over one column on this table, and making them all join and
 * group a child table would rewrite the reporting layer to add one feature. The
 * ledger stays the record of what happened; this is the figure the reports read.
 *
 * Existing paid rows are backfilled to their full charge, because that is exactly
 * what they mean today: the gateway took the whole fee. Refunded rows are
 * backfilled the same way, since money can only come back if it arrived first,
 * and `refunded_amount` already records the return separately.
 *
 * A ledger row is written for each of them as well. Skipping that would leave the
 * Settlements screen, which reconciles against a bank statement, blind to every
 * payment taken before this migration ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->decimal('amount_paid', 10, 2)->default(0)->after('amount');
        });

        /*
         | Walked in PHP rather than expressed as one UPDATE ... JOIN. The tests run
         | on SQLite, which has no such syntax, and a migration that only works on
         | MySQL is a migration that fails in the one place it is checked.
         */
        $settled = DB::table('event_registrations')
            ->whereIn('payment_status', ['paid', 'refunded'])
            ->where('amount', '>', 0)
            ->get(['id', 'amount', 'payment_reference', 'payment_synced_at', 'updated_at', 'created_at']);

        foreach ($settled as $registration) {
            DB::table('event_registrations')
                ->where('id', $registration->id)
                ->update(['amount_paid' => $registration->amount]);

            // The date the gateway confirmed, where there is one. updated_at is the
            // closest honest guess otherwise, and is what dailyCollected() already
            // fell back to before this table existed.
            $receivedAt = $registration->payment_synced_at
                ?? $registration->updated_at
                ?? $registration->created_at;

            DB::table('event_registration_payments')->insert([
                'event_registration_id' => $registration->id,
                'amount' => $registration->amount,
                'received_at' => $receivedAt,
                'reference' => $registration->payment_reference,
                'note' => 'Recorded when the payment ledger was introduced.',
                'source' => 'gateway',
                'recorded_by' => null,
                'actor_label' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->dropColumn('amount_paid');
        });
    }
};
