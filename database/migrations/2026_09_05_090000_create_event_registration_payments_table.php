<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every receipt against a registration, one row each.
 *
 * Until now a registration could only be unpaid or paid, and how much had
 * actually arrived was never recorded: `amount` is what is owed and
 * `refunded_amount` is what went back. That is enough while the gateway collects
 * the whole fee in one go, and useless the moment somebody transfers half of it
 * and the site crashes before the entry is confirmed.
 *
 * A table rather than a column because "partial" implies more will follow. Two
 * transfers a week apart are two facts with two dates and two references, and
 * collapsing them into one figure loses the ability to answer which of them a
 * bank statement line belongs to. The running total is still kept on the parent
 * as `amount_paid`, so the money screens stay simple column sums.
 *
 * Gateway payments are written here too. If only hand-recorded receipts appeared,
 * this table would be a partial ledger, and a partial ledger is worse than none:
 * every figure built on it would quietly exclude the majority of the takings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_registration_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_registration_id')
                ->constrained()
                ->cascadeOnDelete();

            // What arrived. Never negative: money going back is a refund, and that
            // is already recorded on the registration itself.
            $table->decimal('amount', 10, 2);

            /*
             | When the money arrived, as told to us, not when the row was typed.
             | Those differ by days for a transfer somebody reports on Monday, and
             | it is the first that a bank statement will agree with.
             */
            $table->dateTime('received_at')->index();

            // The transfer or receipt number. Nullable because cash across a counter
            // has none, and inventing one would make it look like there was proof.
            $table->string('reference')->nullable()->index();

            $table->string('note')->nullable();

            /*
             | gateway | manual. Kept so a reconciliation can separate what a machine
             | observed from what a person asserted, which are not equally reliable.
             */
            $table->string('source', 20)->default('manual')->index();

            /*
             | Who recorded it. Null for a gateway payment, since nobody did. The
             | label is stored beside the key so the trail stays readable after a
             | staff account is deleted, matching activity_logs and shop_order_events.
             */
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_label')->nullable();

            $table->timestamps();

            // Named explicitly. The generated name for this pair runs to 67
            // characters and MySQL refuses an identifier over 64.
            $table->index(['event_registration_id', 'received_at'], 'erp_registration_received_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_registration_payments');
    }
};
