<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A product is either posted out or collected at a counter.
 *
 * Online is what the shop did until now: a parcel, an address, a postage charge.
 * Offline is bought here and handed over in person, which is what makes selling
 * merchandise for an event work: pay on the website, collect at the counter on the
 * day. Nothing is posted, so shipping does not apply to it at all.
 *
 * A collection point is either an event already in the system or typed in by hand.
 * Pointing at the event is preferred because its location and date are already
 * maintained there and a copy would drift; the manual pair exists because not every
 * handover happens at an event.
 *
 * Exactly one of the two is filled in, enforced in the form request rather than by
 * the schema: a check constraint spanning three columns would be invisible to the
 * person filling the form in and would surface as a database error instead of a
 * message beside the field.
 *
 * nullOnDelete on the event, not restrict. Blocking the deletion of an event
 * because a product mentions it would be a surprising way to find out. Instead the
 * product loses its collection point and stops being purchasable, which the admin
 * sees on the product itself, and orders already placed are unaffected because they
 * carry their own snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_products', function (Blueprint $table) {
            // online | offline
            $table->string('fulfilment', 10)->default('online')->after('payment_methods')->index();

            $table->foreignId('collection_event_id')
                ->nullable()
                ->after('fulfilment')
                ->constrained('events')
                ->nullOnDelete();

            $table->string('collection_location')->nullable()->after('collection_event_id');

            // Date and time together: "collect on the 3rd" without a time is not an
            // instruction somebody can turn up for.
            $table->dateTime('collection_at')->nullable()->after('collection_location');
        });
    }

    public function down(): void
    {
        Schema::table('shop_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collection_event_id');
            $table->dropColumn(['fulfilment', 'collection_location', 'collection_at']);
        });
    }
};
