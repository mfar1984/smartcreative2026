<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Confirm the entries that were never going to be confirmed.
 *
 * A free registration was created "Paid" and "Pending" and stayed that way. The
 * only code that sets confirmed is RegistrationPaymentUpdater, which runs when a
 * payment reaches paid, and a free entry never goes near it. So every free entry
 * carried an amber Pending badge while owing nothing, with nothing outstanding for
 * anybody to chase and no action that would ever clear it.
 *
 * Scoped as narrowly as the fact allows: paid, nothing owed, and still pending.
 * A pending entry that owes money is a different thing and is left alone, because
 * it genuinely is waiting for something.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('event_registrations')
            ->where('status', 'pending')
            ->where('payment_status', 'paid')
            // Zero and null both mean nothing was charged. Null appears on rows
            // written before the column had a default.
            ->where(function ($query) {
                $query->where('amount', '<=', 0)->orWhereNull('amount');
            })
            ->update([
                'status' => 'confirmed',
                'updated_at' => now(),
            ]);
    }

    /**
     * Deliberately does nothing.
     *
     * Putting these back to pending would be re-creating the defect, and there is
     * no record of which rows this touched: an entry confirmed by a real payment
     * looks identical to one confirmed here. Reversing it would catch both.
     */
    public function down(): void
    {
    }
};
