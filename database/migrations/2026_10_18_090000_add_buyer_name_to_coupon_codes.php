<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The buyer's name on a shop redemption, stored the way the participant's already is.
 *
 * WHY IT IS COPIED ONTO THE LEDGER RATHER THAN READ OFF THE ORDER
 *
 * The same reason participant_name exists. A sponsor-facing screen shows who used a
 * code, and on the shop side that is the person who placed the order. Reading it off
 * shop_orders would mean that screen's queries touching a table that also holds a
 * delivery address, a phone number, an email and what the order came to — and the
 * first careless column added to a select there is a leak. With the name here, the
 * sponsor's screen never needs the order row at all.
 *
 * A NAME ONLY, exactly as the event side: no phone, no address, no email, no total.
 * What was BOUGHT is read from the order's items when the screen needs it, because a
 * product name is catalogue information and carries none of that risk.
 *
 * Nullable, because every row written before this column existed has no value for it
 * and because a redemption on an event has no buyer at all. The backfill beside this
 * fills in what can be recovered exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupon_codes', function (Blueprint $table) {
            // Beside the order it belongs to, which is the column it describes.
            $table->string('buyer_name')->nullable()->after('shop_order_id');
        });
    }

    /**
     * Honestly reversible: the column is new and nothing else moved.
     */
    public function down(): void
    {
        Schema::table('coupon_codes', function (Blueprint $table) {
            $table->dropColumn('buyer_name');
        });
    }
};
