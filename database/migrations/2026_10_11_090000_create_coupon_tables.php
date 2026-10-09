<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coupons, as batches rather than as single codes.
 *
 * SUPERSEDED. Everything below about minting is how the model worked when this
 * migration was written. 2026_10_12_090000_make_coupon_codes_a_redemption_ledger
 * reversed it: the batch name is the only code, `quantity` is how many times it may be
 * used, and coupon_codes is a ledger written at the moment of use. Read that one for
 * the current shape and why it changed. This is left as it ran.
 *
 * A `coupons` row is a BATCH. "ABC123" is its name, and the two tables exist because
 * one row has to answer two different questions depending on how many uses it was
 * created with:
 *
 *   quantity > 0  N unique codes are minted up front, one per person, each redeemable
 *                 exactly once. Those codes are what people type.
 *   quantity = 0  nothing is minted. The batch name itself is the shared code, and it
 *                 may be redeemed without limit.
 *
 * `coupon_codes` carries both shapes, which is what keeps the Tracking screen reading
 * one table: a minted code is a row that exists before anybody uses it and is stamped
 * when they do; an unlimited redemption is a row written at the moment of use.
 *
 * WHY `code` IS NULLABLE
 *
 * A minted code is unique across every coupon in the system, so the column carries a
 * unique index. An unlimited batch has no minted codes, so its redemption rows carry
 * null and read their label off the batch name — several redemptions of one shared
 * code cannot each hold that same string in a unique column. Both MySQL and SQLite
 * allow repeated NULLs in a unique index, so one index covers both shapes without a
 * partial index neither engine agrees on.
 *
 * The two pivots are what the admin ticks: a batch applies to any number of events or
 * products, and only ever to the kind it was created for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();

            /*
             | Event registration or shop, one or the other, never both. It decides
             | which form offers the batch and which kind of record a redemption may
             | be attached to.
             */
            $table->string('kind', 20)->index();

            /*
             | The batch name, and for an unlimited batch the code people type. Unique
             | across batches, and CouponRequest also refuses one that collides with a
             | minted code: the two namespaces are typed into the same box by a buyer.
             */
            $table->string('name', 32)->unique();

            // 0 means unlimited. Anything above is how many unique codes were minted.
            $table->unsignedInteger('quantity')->default(0);

            // A date rather than a datetime: the operator sets a day, and redemption
            // compares against the end of it.
            $table->date('expires_at');

            $table->string('discount_type', 12);

            /*
             | A percentage (1-100) or a figure in ringgit, depending on the type. One
             | column rather than two, so a batch cannot be stored claiming both.
             */
            $table->decimal('discount_value', 10, 2);

            // A preset key, or 'custom' when the operator uploaded their own artwork.
            $table->string('design', 32)->default('classic');
            $table->string('design_path')->nullable();

            $table->timestamps();
        });

        Schema::create('coupon_codes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();

            // Null for a redemption of an unlimited batch. See the note above.
            $table->string('code', 32)->nullable()->unique();

            /*
             | Stamped when the code is claimed. Null means a minted code nobody has
             | used yet, which is also what the remaining count is read from.
             */
            $table->dateTime('redeemed_at')->nullable();

            /*
             | What the discount actually came to in ringgit, recorded at redemption.
             | Not recomputed later: a percentage of a charge that has since been
             | corrected would no longer be the figure that was given.
             */
            $table->decimal('discount_amount', 10, 2)->default(0);

            $table->foreignId('event_registration_id')->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('shop_order_id')->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->timestamps();

            // The claim query: the next unused code in one batch.
            $table->index(['coupon_id', 'redeemed_at']);
        });

        Schema::create('coupon_event', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->unique(['coupon_id', 'event_id']);
        });

        Schema::create('coupon_shop_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_product_id')->constrained()->cascadeOnDelete();
            $table->unique(['coupon_id', 'shop_product_id'], 'coupon_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_shop_product');
        Schema::dropIfExists('coupon_event');
        Schema::dropIfExists('coupon_codes');
        Schema::dropIfExists('coupons');
    }
};
