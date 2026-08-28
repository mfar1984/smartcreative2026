<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The shop catalogue: categories, products, their images and their variants.
     *
     * One migration because the five tables are meaningless apart, the same way
     * create_event_addon_tables groups an add-on with its variants.
     *
     * Variants deliberately mirror event_addon_variants rather than inventing a
     * second convention: a null price means "charge the product price" and a null
     * stock means "unlimited", so a blank box in the form keeps its meaning instead
     * of being coerced to zero. A shirt in S, M and L is the same problem the event
     * add-on builder already solved.
     *
     * Nothing here records an order. Selling needs a cart, a checkout and a payment,
     * which is a separate piece of work; putting order columns in now would be
     * guessing at their shape before the checkout exists.
     */
    public function up(): void
    {
        Schema::create('shop_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');

            // Name of a case in the admin icon component, shown beside the
            // category in the admin list and on the storefront filter.
            $table->string('icon', 40)->nullable();

            $table->string('description')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('shop_products', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');

            /*
             | Nullable and unique. MySQL allows many nulls in a unique index, so a
             | product without a code does not block another one, while two products
             | cannot share a code by accident.
             */
            $table->string('sku', 80)->nullable()->unique();
            $table->string('barcode', 80)->nullable();

            // Shown on the listing card. Kept short by validation.
            $table->string('short_description', 400)->nullable();
            $table->text('description')->nullable();

            /* ---------------- Pricing ---------------- */

            $table->decimal('price', 10, 2);

            /*
             | The old price, shown struck through beside the current one. Null means
             | the product is not on offer, which is different from being on offer at
             | no discount.
             */
            $table->decimal('compare_at_price', 10, 2)->nullable();

            // What it costs us. Admin only, for margin. Never reaches a visitor.
            $table->decimal('cost_price', 10, 2)->nullable();

            /* ---------------- Inventory ---------------- */

            /*
             | False means stock is not counted at all and the product never sells
             | out. A made-to-order medal behaves that way.
             */
            $table->boolean('track_inventory')->default(true);

            /*
             | On hand, and how much of it orders have claimed. Only consulted when
             | the product has no variants; with variants the stock lives per variant,
             | because "3 shirts left" is not a useful answer when they are all size S.
             */
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->unsignedInteger('stock_taken')->default(0);

            $table->unsignedSmallInteger('low_stock_threshold')->default(5);

            /* ---------------- Shipping ---------------- */

            /*
             | Whole grams and whole millimetres rather than decimal kilograms and
             | centimetres. Couriers quote in grams, and integers cannot drift the way
             | a repeatedly rounded decimal can. The form accepts kg and cm and does
             | the conversion once.
             */
            $table->unsignedInteger('weight_grams')->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();

            /* ---------------- Variants ---------------- */

            /*
             | What the variant list is a list of, for example "Size" or "Colour".
             | Null when the product has no variants. Used as the chooser heading on
             | the storefront so it reads "Choose a Size" rather than "Choose an
             | option".
             */
            $table->string('option_name', 60)->nullable();

            /* ---------------- Copy ---------------- */

            /*
             | One item per line, the same convention events.rules uses. Plain text,
             | not HTML: these are written in the admin area and rendered on a public
             | page, so accepting markup would let anyone who reaches an admin account
             | run script in every visitor's browser.
             */
            $table->text('highlights')->nullable();
            $table->text('included_items')->nullable();

            // One per line as "Label: Value", rendered as a specification table.
            $table->text('specifications')->nullable();

            /* ---------------- Organisation ---------------- */

            $table->string('vendor')->nullable();
            $table->string('brand')->nullable();

            // draft | active | archived
            $table->string('status', 20)->default('draft')->index();

            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            /* ---------------- Search engines ---------------- */

            $table->string('seo_title', 70)->nullable();
            $table->string('seo_description', 180)->nullable();
            $table->string('seo_keywords')->nullable();

            $table->timestamps();

            $table->index(['status', 'is_featured']);
        });

        Schema::create('shop_category_product', function (Blueprint $table) {
            $table->foreignId('shop_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_product_id')->constrained()->cascadeOnDelete();

            // A product belongs to a category once. The pair is the identity of the
            // row, so there is no surrogate id to keep unique separately.
            $table->primary(['shop_category_id', 'shop_product_id']);
        });

        Schema::create('shop_product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_product_id')->constrained()->cascadeOnDelete();

            // Stored on the public disk, like event posters and portfolio images.
            $table->string('path');

            /*
             | Describes the picture for a screen reader and for the case where it
             | fails to load. Nullable because a decorative extra shot of the same
             | item adds nothing when described a third time.
             */
            $table->string('alt_text')->nullable();

            /*
             | The one shown on the listing card. Enforced as at most one per product
             | by the controller rather than by the schema, because a partial unique
             | index is not portable.
             */
            $table->boolean('is_featured')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['shop_product_id', 'sort_order']);
        });

        Schema::create('shop_product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_product_id')->constrained()->cascadeOnDelete();

            // What the buyer picks, for example "M" or "M / Red".
            $table->string('label', 60);

            $table->string('sku', 80)->nullable();

            // Null means charge the product price.
            $table->decimal('price', 10, 2)->nullable();

            // Null means unlimited.
            $table->unsignedInteger('stock')->nullable();
            $table->unsignedInteger('stock_taken')->default(0);

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // Two options with the same label would be indistinguishable to a buyer.
            $table->unique(['shop_product_id', 'label']);
        });
    }

    public function down(): void
    {
        // Children first: the foreign keys would refuse the parents otherwise.
        Schema::dropIfExists('shop_product_variants');
        Schema::dropIfExists('shop_product_images');
        Schema::dropIfExists('shop_category_product');
        Schema::dropIfExists('shop_products');
        Schema::dropIfExists('shop_categories');
    }
};
