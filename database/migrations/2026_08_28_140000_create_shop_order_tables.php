<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shop orders, their lines, and the trail of how each one moved.
     *
     * Three tables in one migration because none of them means anything alone.
     *
     * The lines follow event_registration_addons: nullable foreign keys so a line
     * survives the product being deleted, and snapshot columns carrying what was
     * bought at the price it was bought for. Without the snapshot, editing a price
     * would rewrite every invoice ever issued.
     */
    public function up(): void
    {
        Schema::create('shop_orders', function (Blueprint $table) {
            $table->id();

            // Sequential and human readable, like REG- on registrations: SO-2026-0001.
            $table->string('reference', 20)->unique();

            /*
             | One lifecycle rather than a status plus a payment status. A shop order
             | is only ever at one point in its life, and two columns would let them
             | disagree.
             |
             | pending_payment | paid | packing | shipped | delivered | cancelled | refunded
             */
            $table->string('status', 30)->default('pending_payment')->index();

            // gateway | cod | bank_transfer
            $table->string('payment_method', 20)->index();

            // What the gateway called the transaction, and the payload it sent back.
            $table->string('payment_reference')->nullable();
            $table->text('payment_details')->nullable();
            $table->timestamp('paid_at')->nullable();

            /* ---------------- Who is buying ---------------- */

            /*
             | Held on the order rather than pointing at a customer record, because
             | checkout takes no account. The details are also a snapshot: a buyer who
             | moves house must not have last year's parcel appear to have gone to the
             | new address.
             */
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone', 40);

            $table->string('address_line_1');
            $table->string('address_line_2')->nullable();
            $table->string('postcode', 10);
            $table->string('city', 120);
            $table->string('state', 120);
            $table->string('country', 120)->default('Malaysia');

            /* ---------------- Money ---------------- */

            /*
             | Every part is stored, not just the total, so the arithmetic on an
             | invoice can be shown rather than asserted.
             */
            $table->decimal('items_total', 10, 2)->default(0);
            $table->decimal('shipping_total', 10, 2)->default(0);
            $table->decimal('grand_total', 10, 2)->default(0);

            /*
             | How the postage figure was arrived at, in words. Live courier quoting
             | comes later; until then this reads as a flat rate, and afterwards it
             | names the courier that was quoted.
             */
            $table->string('shipping_label')->nullable();

            /* ---------------- Delivery ---------------- */

            $table->string('courier_name')->nullable();
            $table->string('tracking_number')->nullable()->index();
            $table->string('tracking_url', 500)->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            /*
             | Cash on delivery is confirmed by the person who received the parcel,
             | through a signed link, so the time and the address it came from are
             | kept as the evidence that it was them.
             */
            $table->timestamp('received_confirmed_at')->nullable();
            $table->string('received_confirmed_ip', 45)->nullable();

            /* ---------------- Refunds ---------------- */

            // Same shape as event_registrations, so the money screens can read both.
            $table->decimal('refunded_amount', 10, 2)->default(0);
            $table->timestamp('refunded_at')->nullable();
            $table->string('refund_reason')->nullable();

            /* ---------------- Housekeeping ---------------- */

            $table->text('notes')->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('customer_email');
        });

        Schema::create('shop_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_order_id')->constrained()->cascadeOnDelete();

            /*
             | Nullable and nullOnDelete: a line has to survive its product being
             | deleted, because the order it belongs to already happened.
             */
            $table->foreignId('shop_product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shop_product_variant_id')->nullable()->constrained()->nullOnDelete();

            // Snapshots. What was bought, called what it was called, at the price it
            // was sold for.
            $table->string('name');
            $table->string('variant_label', 60)->nullable();
            $table->string('sku', 80)->nullable();
            $table->decimal('unit_price', 10, 2);
            $table->unsignedSmallInteger('quantity');
            $table->decimal('line_total', 10, 2);

            // Kept per line so a parcel weight can be worked out from the order
            // alone, without reading products that may since have changed.
            $table->unsignedInteger('weight_grams')->nullable();

            $table->timestamps();
        });

        Schema::create('shop_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_order_id')->constrained()->cascadeOnDelete();

            // The status the order moved to, or null for a note that changed nothing.
            $table->string('status', 30)->nullable();
            $table->string('note')->nullable();

            /*
             | Null when nobody did it: a gateway callback, or the buyer confirming
             | receipt. actor_label is stored beside the key so the trail stays
             | readable after a staff account is deleted, matching activity_logs.
             */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_label')->nullable();

            $table->timestamps();

            $table->index(['shop_order_id', 'id']);
        });
    }

    public function down(): void
    {
        // Children first: the foreign keys would refuse the parent otherwise.
        Schema::dropIfExists('shop_order_events');
        Schema::dropIfExists('shop_order_items');
        Schema::dropIfExists('shop_orders');
    }
};
