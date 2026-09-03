<?php

use App\Models\ShopOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A product says which ways it may be paid for.
 *
 * The switches on Settings > Integration > Payments decide what the shop can take
 * at all. This narrows that per product: a heavy trophy might be cash on delivery
 * only, a digital voucher card only. The global switches stay the ceiling, so
 * ticking a method here cannot turn on something the shop is not configured for.
 *
 * Stored as an explicit list rather than as "null means all". A blank that means
 * everything reads the same as a blank that means nothing, and that ambiguity is
 * exactly what makes this kind of setting misbehave later. Every row carries the
 * methods it accepts, spelled out.
 *
 * Existing rows are backfilled with all three, which is what they behaved as
 * before this column existed, so nothing that was buyable stops being buyable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_products', function (Blueprint $table) {
            $table->json('payment_methods')->nullable()->after('status');
        });

        DB::table('shop_products')->update([
            'payment_methods' => json_encode(array_keys(ShopOrder::METHODS)),
        ]);
    }

    public function down(): void
    {
        Schema::table('shop_products', function (Blueprint $table) {
            $table->dropColumn('payment_methods');
        });
    }
};
