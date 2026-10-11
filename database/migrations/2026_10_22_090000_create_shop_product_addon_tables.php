<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extras a buyer may add to a product, and what an order line actually took.
     *
     * Three tables in one migration, the precedent set by create_event_addon_tables
     * and create_shop_catalogue_tables: an add-on without its options and without
     * somewhere to record what was bought means nothing on its own.
     *
     * Additive only. Nothing on shop_products, shop_product_variants or
     * shop_order_items is altered, because a size and an extra are different
     * questions: the size is which one of the product you want, the add-on is what
     * else goes with it. Both can be answered for the same basket line, and the
     * existing columns keep the meaning every order already written relies on.
     */
    public function up(): void
    {
        // Something extra that goes with a product, such as laser engraving.
        Schema::create('shop_product_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_product_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('description')->nullable();

            // Price per unit. An option may override it.
            $table->decimal('price', 10, 2)->default(0);

            /*
             | A compulsory add-on is taken on every purchase of the product: with no
             | options it is simply charged, and with options the buyer has to pick
             | one before the basket will accept the line.
             */
            $table->boolean('is_required')->default(false);

            // Null means no cap beyond available stock.
            $table->unsignedSmallInteger('max_quantity')->nullable();

            /*
             | Null means unlimited, which is not the same as zero: zero is sold out.
             | A blank box in the form therefore keeps the meaning the operator
             | intended rather than quietly withdrawing the add-on.
             |
             | Stock lives in two places, with the rule the product/variant pair
             | already uses: when the add-on has options the option's stock governs
             | and this figure is not consulted, the same way ShopProduct::stockLeft()
             | ignores the product count once variants exist. "3 engravings left" is
             | not a useful answer when all three are Front only.
             */
            $table->unsignedInteger('stock')->nullable();
            $table->unsignedInteger('stock_taken')->default(0);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['shop_product_id', 'is_active']);
        });

        // A choice within an add-on, such as which side gets engraved.
        Schema::create('shop_product_addon_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_product_addon_id')->constrained()->cascadeOnDelete();

            $table->string('label', 60);

            /*
             | Null means charge the add-on's price, which is the shop's convention
             | for shop_product_variants.price and is deliberately NOT the event
             | module's ("adds nothing on top"). Options that cost the same as the
             | add-on do not have to repeat the figure; set it only where that choice
             | genuinely costs more.
             */
            $table->decimal('price', 10, 2)->nullable();

            // Null means unlimited; zero means sold out.
            $table->unsignedInteger('stock')->nullable();
            $table->unsignedInteger('stock_taken')->default(0);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // Two options with the same label would be indistinguishable to a buyer.
            $table->unique(['shop_product_addon_id', 'label']);
        });

        // What one order line actually took.
        Schema::create('shop_order_item_addons', function (Blueprint $table) {
            $table->id();

            /*
             | The extras were chosen against one basket line — this size AND these
             | extras — so they belong to the line rather than to the order.
             */
            $table->foreignId('shop_order_item_id')->constrained()->cascadeOnDelete();

            /*
             | Nullable with nullOnDelete, as event_registration_addons does: the line
             | survives the catalogue entry being removed, and the snapshot columns
             | below carry what was bought. The relations exist only so the admin can
             | link back to an entry that is still there.
             */
            $table->foreignId('shop_product_addon_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shop_product_addon_variant_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('variant_label', 60)->nullable();

            /*
             | Snapshot of what was charged, so renaming or repricing the add-on
             | later cannot rewrite an invoice that has already been issued.
             */
            $table->decimal('unit_price', 10, 2);
            $table->unsignedSmallInteger('quantity');
            $table->decimal('line_total', 10, 2);

            $table->timestamps();

            $table->index('shop_order_item_id');
        });
    }

    public function down(): void
    {
        // Children first: the foreign keys would refuse the parents otherwise.
        Schema::dropIfExists('shop_order_item_addons');
        Schema::dropIfExists('shop_product_addon_variants');
        Schema::dropIfExists('shop_product_addons');
    }
};
