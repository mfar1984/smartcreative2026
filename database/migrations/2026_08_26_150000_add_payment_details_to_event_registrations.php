<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            // The gateway's own record of the payment, kept verbatim.
            //
            // Stored rather than only fetched on demand so the detail page still
            // has something to show when the gateway cannot be reached, and so
            // there is a record of what it said at the time even if the purchase
            // is later refunded or amended.
            $table->json('payment_details')->nullable()->after('payment_reference');

            // When that snapshot was taken, so the page can say how fresh it is
            // instead of presenting stale data as current.
            $table->timestamp('payment_synced_at')->nullable()->after('payment_details');
        });
    }

    public function down(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->dropColumn(['payment_details', 'payment_synced_at']);
        });
    }
};
