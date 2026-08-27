<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record how much of a payment came back, not just that some of it did.
 *
 * `payment_status` was carrying the whole story as a single flag, and CHIP maps
 * both `refunded` and `partially_refunded` onto it. That meant refunding RM 10 of
 * a RM 100 entry marked the entry refunded, and the reports then counted the full
 * RM 100 as given back while dropping it out of the takings entirely. The figures
 * were wrong by the part that was never refunded.
 *
 * With an amount recorded, a partial refund can stay `paid` and the reports can
 * say RM 90 collected and RM 10 returned, which is what actually happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            if (! Schema::hasColumn('event_registrations', 'refunded_amount')) {
                $table->decimal('refunded_amount', 10, 2)->default(0)->after('amount');
            }

            if (! Schema::hasColumn('event_registrations', 'refunded_at')) {
                $table->timestamp('refunded_at')->nullable()->after('payment_synced_at');
            }

            if (! Schema::hasColumn('event_registrations', 'refund_reason')) {
                // Required on every refund. A refund with no reason is the one thing
                // nobody can explain three months later when it is queried.
                $table->string('refund_reason', 255)->nullable()->after('refunded_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            foreach (['refunded_amount', 'refunded_at', 'refund_reason'] as $column) {
                if (Schema::hasColumn('event_registrations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
