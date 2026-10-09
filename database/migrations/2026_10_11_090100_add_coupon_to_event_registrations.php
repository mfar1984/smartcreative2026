<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The discount a coupon gave one registration.
 *
 * `discount_amount` is a record of what was taken off, NOT a figure anything
 * subtracts a second time. The discount lives inside `amount`, which is the number
 * every money figure in the system keys off:
 *
 *   amount = registration_fee + addons_total - discount_amount, floored at zero
 *
 * Doing it that way is what keeps the whole Payments module correct with no changes.
 * PaymentFigures computes outstanding as `amount - amount_paid` rather than reading a
 * stored column, so reducing `amount` reduces what is owed everywhere at once; and
 * EventRegistration::paymentStatusFromLedger() already answers PAID for isFree(), so a
 * 100% coupon needs no new status logic at all.
 *
 * Both columns default to nothing, so every stored registration keeps exactly the
 * charge it has today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->decimal('discount_amount', 10, 2)
                ->default(0)
                ->after('addons_total');

            /*
             | Which redemption paid for it. nullOnDelete rather than cascade: losing
             | the coupon must never take the registration with it, and the figure
             | above is the part that matters to the books.
             */
            $table->foreignId('coupon_code_id')->nullable()
                ->after('discount_amount')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_code_id');
            $table->dropColumn('discount_amount');
        });
    }
};
