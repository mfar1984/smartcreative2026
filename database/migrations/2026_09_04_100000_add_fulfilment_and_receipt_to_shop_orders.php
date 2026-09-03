<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders learn whether they are posted or collected, and can carry a bank receipt.
 *
 * The collection details are snapshotted onto the order rather than read back
 * through the product. An order is a record of an agreement: if the event moves
 * venue, or the product is edited, or the event is deleted, the buyer must still be
 * told the place and time they were actually promised.
 *
 * The identity card is the counter's verification. It is only asked for on a
 * collected order, because a posted parcel is verified by the address it goes to and
 * holding an identity number nobody needs would be collecting personal data for no
 * purpose.
 *
 * No collected_at column: the lifecycle already ends at delivered and delivered_at
 * already records when. Collection is that same moment for an order handed over at a
 * counter, and a second column would give two answers to one question.
 *
 * The receipt belongs to manual bank transfer. Nothing observes a transfer arriving,
 * so the buyer sends proof and somebody checks it against the account by hand. The
 * upload is evidence for that decision, never the decision itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            // online | offline. Indexed because the admin list is split by it.
            $table->string('fulfilment', 10)->default('online')->after('status')->index();

            // Malaysian identity card or a passport number. Offline orders only.
            $table->string('identity_card', 30)->nullable()->after('customer_phone');

            /* ---------------- Where it is collected ---------------- */

            $table->foreignId('collection_event_id')
                ->nullable()
                ->after('shipping_label')
                ->constrained('events')
                ->nullOnDelete();

            // The event title, or "Collection point" for a manual one. Snapshotted so
            // the order still reads correctly after the event is renamed or removed.
            $table->string('collection_label')->nullable()->after('collection_event_id');
            $table->string('collection_location')->nullable()->after('collection_label');
            $table->dateTime('collection_at')->nullable()->after('collection_location');

            /* ---------------- Proof of a bank transfer ---------------- */

            $table->string('payment_receipt_path')->nullable()->after('payment_details');
            $table->timestamp('payment_receipt_uploaded_at')->nullable()->after('payment_receipt_path');
        });
    }

    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collection_event_id');
            $table->dropColumn([
                'fulfilment',
                'identity_card',
                'collection_label',
                'collection_location',
                'collection_at',
                'payment_receipt_path',
                'payment_receipt_uploaded_at',
            ]);
        });
    }
};
