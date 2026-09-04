<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The proof behind a hand-recorded payment.
 *
 * A receipt row on its own is one person's word that money arrived. That is
 * unavoidable for a bank transfer, but it does not have to be all there is: the
 * transfer slip or the screenshot the entrant sent can be attached to the row it
 * justifies, so a figure in the takings can be traced back to something.
 *
 * Nullable, and it stays nullable. Cash across a counter has no slip, and refusing
 * to record a payment without a file would push somebody into either not recording
 * it or attaching something irrelevant. Gateway payments have no attachment either:
 * their evidence is the gateway's own record, already stored on the registration.
 *
 * One file per receipt rather than a list. Two transfers are two receipts, and each
 * one carries its own slip, which is exactly the structure the ledger already has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_registration_payments', function (Blueprint $table) {
            $table->string('proof_path')->nullable()->after('note');

            // The name the file was uploaded with. The stored path carries a hashed
            // name so uploads cannot collide, which would otherwise leave the counter
            // looking at a link reading as a random string.
            $table->string('proof_name')->nullable()->after('proof_path');
        });
    }

    public function down(): void
    {
        Schema::table('event_registration_payments', function (Blueprint $table) {
            $table->dropColumn(['proof_path', 'proof_name']);
        });
    }
};
