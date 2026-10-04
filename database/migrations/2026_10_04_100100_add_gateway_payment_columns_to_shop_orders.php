<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two nullable columns so a shop order can be settled by the gateway.
 *
 * Additive, no default, no index, no existing row touched. NULL on every row that
 * exists today reproduces today's behaviour exactly, because nothing has ever
 * synced or settled a shop order through CHIP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            /*
             | When the gateway record in payment_details was last read back. Parity
             | with event_registrations.payment_synced_at, declared the same way and
             | for the same reason: a stored payload with no read time cannot be told
             | apart from a fresh one.
             */
            $table->timestamp('payment_synced_at')->nullable()->after('payment_details');

            /*
             | Which gateway purchase actually settled this order.
             |
             | payment_reference cannot answer that, and this is the whole reason the
             | column exists. It holds the most recent attempt, an administrator can
             | type a bank reference straight into it, and adoptPurchase() re-points
             | it. So "is this arriving purchase.paid the one that already paid for
             | this order, or a second collection?" has no answer anywhere today -
             | and getting it wrong means either an error-level alarm on every CHIP
             | replay, or silence when a buyer is charged twice.
             |
             | Written in exactly one place: ShopOrderPaymentUpdater::applyPaid(),
             | after a move to paid succeeds. NULL therefore means "not settled by a
             | gateway purchase", which is the truth for every row that exists today
             | and for every order settled by hand, by cash or by bank transfer.
             |
             | 190 matches the max:190 the hand-confirmation form already validates
             | payment_reference against, and is far more than a CHIP purchase id
             | needs.
             */
            $table->string('paid_purchase_id', 190)->nullable()->after('payment_reference');
        });
    }

    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->dropColumn(['payment_synced_at', 'paid_purchase_id']);
        });
    }
};
