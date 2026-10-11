<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two columns the add-ons feature shipped without, both about how the block
     * reads on the product page rather than about what anything costs.
     *
     * They travel together because they answer the same complaint: the operator
     * could not say how an add-on is answered, and could not say what the group of
     * them is called. Nothing about price, stock or the order lines is touched, so
     * no figure on any screen moves.
     */
    public function up(): void
    {
        Schema::table('shop_product_addons', function (Blueprint $table) {
            /*
             | Existing add-ons keep what they do today: one option only. Checkbox is
             | opt-in per item, exactly as event_addons.selection_type is.
             |
             | Only consulted once the add-on has options. With none there is nothing
             | to choose between and the storefront draws a single tick box, which is
             | what it has always done.
             */
            $table->string('selection_type', 20)
                ->default('radio')
                ->after('is_required');
        });

        Schema::table('shop_products', function (Blueprint $table) {
            /*
             | The heading above the add-ons, typed by whoever set the product up, the
             | same way option_name names the group of sizes. Null means the operator
             | said nothing, and the storefront falls back to "Extras" — so every
             | product already in the shop reads exactly as it does now.
             */
            $table->string('addon_group_name', 60)
                ->nullable()
                ->after('option_name');
        });
    }

    public function down(): void
    {
        Schema::table('shop_product_addons', function (Blueprint $table) {
            $table->dropColumn('selection_type');
        });

        Schema::table('shop_products', function (Blueprint $table) {
            $table->dropColumn('addon_group_name');
        });
    }
};
