<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every checkout ever opened at the gateway for a shop order.
 *
 * Mirrors event_registration_checkouts, which exists because a double press once
 * orphaned a real payment: shop_orders.payment_reference holds one attempt, and
 * markPending() overwrites it each time a checkout is opened. If an earlier purchase
 * is the one that settles, its id would be gone.
 *
 * No backfill, and that is a deliberate departure from the registration precedent.
 * event_registrations.payment_reference only ever holds a gateway purchase id;
 * shop_orders.payment_reference does not, because confirmPayment() lets an
 * administrator type a bank reference straight into it. Seeding this table with
 * strings that are not purchase ids would let a CHIP callback match the wrong order.
 * Rows are only ever written when this application actually opened a purchase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_order_checkouts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_order_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             | The gateway's own id for the purchase. Indexed because the webhook
             | looks an order up by it, and unique per order so re-reading the same
             | purchase cannot add the same row twice.
             */
            $table->string('purchase_id')->index();

            // Where the buyer was sent. Kept so a stalled attempt can be re-opened
            // rather than replaced with a second purchase.
            $table->string('checkout_url', 500)->nullable();

            $table->string('gateway', 20)->default('chip');

            $table->dateTime('opened_at');

            $table->timestamps();

            // Named explicitly to match the registration table's convention.
            $table->unique(['shop_order_id', 'purchase_id'], 'soc_order_purchase_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_order_checkouts');
    }
};
