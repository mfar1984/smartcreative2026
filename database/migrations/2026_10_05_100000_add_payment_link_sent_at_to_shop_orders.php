<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a payment link was last emailed for this order.
 *
 * One nullable column, no default, no index, no existing row touched. NULL on every
 * row that exists today means "never reminded", which is exactly how the per-order
 * envelope behaved before this column existed, so today's behaviour is reproduced.
 *
 * It exists to hold a cooldown. A payment link is an outbound email to a real
 * customer, and the bulk control sends one to everybody on a filtered list at once:
 * without a record of the last send, a double press or an impatient second look at
 * the same screen would mail the same buyer twice in a minute. There was nothing to
 * reuse — the only trace of a send was a sentence in shop_order_events.note, and
 * reading a cooldown out of a LIKE against free text would be a cooldown that breaks
 * the day somebody rewords the sentence.
 *
 * timestamp()->nullable() rather than a datetime with a default, so the column means
 * the same thing on sqlite in development and MySQL in production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->timestamp('payment_link_sent_at')->nullable()->after('payment_receipt_uploaded_at');
        });
    }

    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->dropColumn('payment_link_sent_at');
        });
    }
};
