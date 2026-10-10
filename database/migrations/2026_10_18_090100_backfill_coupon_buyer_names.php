<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put the buyer's name on the shop ledger rows written before the column existed.
 *
 * The counterpart of 2026_10_17_090100, which did the same for the event side, and the
 * same rule applies: fill in only what can be recovered EXACTLY, and leave alone
 * anything that would have to be guessed.
 *
 * Here the recovery is unambiguous where it is possible at all. A shop redemption is
 * one ledger row for one order — the shop claims one use, never a per-head split — so
 * a row that knows its order knows exactly whose name belongs on it, and
 * shop_orders.customer_name is the name the buyer typed at checkout.
 *
 * WHAT IS LEFT ALONE, AND WHY THAT IS NOT A GAP
 *
 *   a row with no shop_order_id. The pairing is written inside the same transaction
 *   as the order (ShopOrderWriter::place), so a row without one belongs to an order
 *   that was never committed. There is no name to recover and inventing one would put
 *   a real person's name on a redemption that may not be theirs.
 *
 *   a row whose order has since been deleted. Nothing to read.
 *
 *   every event-side row. A buyer is not a participant, and participant_name already
 *   holds that half.
 *
 * In practice this may well find nothing: a shop redemption has to have happened
 * before the column shipped for there to be anything to fill, and this feature is
 * being built before the shop has taken a coupon. A no-op is the expected outcome on
 * a database that has none, which is why it reports nothing and simply returns.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('coupon_codes')
            ->whereNull('buyer_name')
            ->whereNotNull('shop_order_id')
            ->orderBy('id')
            ->get(['id', 'shop_order_id']);

        if ($rows->isEmpty()) {
            return;
        }

        foreach ($rows as $row) {
            // Narrow on purpose. The name is the only thing on an order this ledger
            // is allowed to carry.
            $name = DB::table('shop_orders')->where('id', $row->shop_order_id)->value('customer_name');

            if (filled($name)) {
                DB::table('coupon_codes')->where('id', $row->id)->update(['buyer_name' => $name]);
            }
        }
    }

    /**
     * Deliberately a no-op.
     *
     * The names written above are the correct ones, copied from the orders that are
     * still standing. Blanking them again would only reinstate the defect, and the
     * column itself belongs to 2026_10_18_090000, which drops it properly if the
     * schema really has to go back.
     */
    public function down(): void
    {
        // Nothing to undo. See the note above.
    }
};
