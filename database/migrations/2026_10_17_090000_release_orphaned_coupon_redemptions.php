<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give back the coupon uses left stranded by deleted registrations.
 *
 * WHAT WENT WRONG
 *
 * coupon_codes.event_registration_id is declared nullOnDelete, so deleting a
 * registration NULLED the column and LEFT the ledger row. The use stayed spent: the
 * batch's remaining count stayed reduced, the sponsor's "actually used" figure kept
 * the discount, and in unique mode the holder's code stayed marked used — all for an
 * entry that no longer existed. Nothing on any screen explained it. The owner found it
 * on a shared 50% batch reading "2 / 10 — 8 uses left" with exactly one registration
 * behind it.
 *
 * CouponReleaser now hands these back at the moment of deletion. This clears what the
 * old behaviour already stranded.
 *
 * WHY RELEASING THEM IS SAFE RATHER THAN A GUESS
 *
 * Every orphan that can exist today came from deleting an UNPAID entry.
 * ParticipantController::destroy() has always refused to delete a registration once
 * EventRegistration::hasMoneyReceived() answers true, and the coupon feature is days
 * old — so no orphan here can belong to an entry that took money, which means nobody
 * ever benefited from the discount and the use was never really spent. Handing it back
 * is restoring the truth, not forgiving a debt.
 *
 * The same reasoning covers the other way a row can end up with both keys null: the
 * shop checkout claims the code before the order row exists, and ShopOrderWriter
 * points the two at each other inside its own transaction. A row with no order is one
 * whose order was never written, so nothing was bought with it either.
 *
 * WHAT IT WILL NOT TOUCH
 *
 *   a row that still names a registration or an order. That is a live redemption.
 *   a row that a live registration or order still POINTS AT through its own
 *   coupon_code_id. Both of those foreign keys are nullOnDelete too, so deleting such
 *   a row would quietly erase a standing entry's record of which coupon paid for it.
 *   The application cannot produce that shape, and a destructive migration run against
 *   live money is the wrong place to rely on that.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphans = DB::table('coupon_codes')
            ->whereNull('event_registration_id')
            ->whereNull('shop_order_id')
            ->whereNotIn('id', DB::table('event_registrations')
                ->whereNotNull('coupon_code_id')
                ->select('coupon_code_id'))
            ->whereNotIn('id', DB::table('shop_orders')
                ->whereNotNull('coupon_code_id')
                ->select('coupon_code_id'))
            ->pluck('id')
            ->all();

        if ($orphans === []) {
            return;
        }

        /*
         | The minted codes first, for CouponReleaser's reason: coupon_issued_codes.
         | coupon_code_id is nullOnDelete, so deleting the ledger rows first would
         | null the pairing and leave `used_at` stamped on a code with nothing left to
         | say what it had been spent on.
         */
        DB::table('coupon_issued_codes')
            ->whereIn('coupon_code_id', $orphans)
            ->update([
                'used_at' => null,
                'coupon_code_id' => null,
                'updated_at' => now(),
            ]);

        DB::table('coupon_codes')->whereIn('id', $orphans)->delete();
    }

    /**
     * Not reversible, and saying so rather than pretending.
     *
     * A released row is gone, and there is nothing left anywhere to rebuild it from:
     * the registration it belonged to was deleted before this ran, which is the whole
     * reason it was an orphan. Recreating a row with no registration, no participant
     * and no discount of record would put a use back in a cap with nothing behind it —
     * exactly the state this migration exists to clear.
     *
     * Deliberately a no-op rather than a thrown exception, so rolling the batch back
     * for an unrelated migration still works.
     */
    public function down(): void
    {
        // Nothing to put back. See the note above.
    }
};
