<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The discount a coupon gave one shop order.
 *
 * Comes off the ITEMS and never off the postage, which is the one rule worth spelling
 * out here because the alternative loses real money: a courier charges whatever it
 * charges whether or not the buyer had a code.
 *
 *   grand_total = items_total - discount_total + shipping_total
 *
 * with the discount capped at items_total, so it can never go negative and never eat
 * the postage. ShopPaymentLinkSender already refuses an order whose grand_total is not
 * positive, so a fully discounted order takes the existing zero-charge path.
 *
 * Defaults to nothing, so every stored order keeps exactly the total it has today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->decimal('discount_total', 10, 2)
                ->default(0)
                ->after('items_total');

            $table->foreignId('coupon_code_id')->nullable()
                ->after('discount_total')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_code_id');
            $table->dropColumn('discount_total');
        });
    }
};
