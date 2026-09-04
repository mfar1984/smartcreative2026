<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a variant carry its own weight.
 *
 * Without this every option on a product weighs the same, so a shirt in 3XL is
 * quoted as a shirt in S. That was harmless while postage was a flat rate banded
 * by state, and stops being harmless the moment a courier is asked for a real
 * price, because weight is the main thing it prices on.
 *
 * Null means use the product's weight, which is exactly how the price column on
 * this table already behaves: most products have one weight for every option, and
 * making each option restate it would be a field to keep in step for nothing.
 *
 * Whole grams, matching shop_products. Couriers quote in grams, and an integer
 * cannot drift the way a repeatedly rounded decimal can.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_product_variants', function (Blueprint $table) {
            $table->unsignedInteger('weight_grams')->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('shop_product_variants', function (Blueprint $table) {
            $table->dropColumn('weight_grams');
        });
    }
};
