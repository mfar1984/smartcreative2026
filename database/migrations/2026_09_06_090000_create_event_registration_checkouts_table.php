<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every checkout ever opened for a registration.
 *
 * The registration holds one `payment_reference`, and markPending() overwrote it
 * each time a checkout was opened. That is fine until somebody presses Pay twice:
 * the second attempt replaces the first, and if the first is the one that gets paid,
 * the id of the paid purchase is gone. It happened, and it left RM 248.50 in the
 * account against a registration reading "failed".
 *
 * The column stays as the current attempt, because everything reads it and the
 * webhook finds an entry by it. This table is the memory behind it, so no attempt is
 * ever lost and a reconciliation can ask the gateway about all of them.
 *
 * Existing references are backfilled, so registrations that predate this table still
 * have their one known attempt on record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_registration_checkouts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_registration_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             | The gateway's own id for the purchase. Indexed because a
             | reconciliation looks entries up by it, and unique per registration so
             | re-reading the same purchase cannot add the same row twice.
             */
            $table->string('purchase_id')->index();

            // Where the payer was sent. Kept so a stalled attempt can be re-opened
            // rather than replaced with a new purchase.
            $table->string('checkout_url', 500)->nullable();

            $table->string('gateway', 20)->default('chip');

            $table->dateTime('opened_at');

            $table->timestamps();

            // Named explicitly: the generated name for this pair runs well past the
            // 64 characters MySQL allows in an identifier.
            $table->unique(['event_registration_id', 'purchase_id'], 'erc_registration_purchase_unique');
        });

        /*
         | Backfill. Walked in PHP rather than as an INSERT ... SELECT, because the
         | tests run on SQLite and a migration that only works on MySQL is one that
         | fails in the only place it is checked.
         */
        $existing = DB::table('event_registrations')
            ->whereNotNull('payment_reference')
            ->where('payment_reference', '!=', '')
            ->get(['id', 'payment_reference', 'updated_at', 'created_at']);

        foreach ($existing as $registration) {
            DB::table('event_registration_checkouts')->insert([
                'event_registration_id' => $registration->id,
                'purchase_id' => $registration->payment_reference,
                'checkout_url' => null,
                'gateway' => 'chip',
                'opened_at' => $registration->created_at ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_registration_checkouts');
    }
};
