<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why goods were handed to somebody who had not finished paying.
 *
 * The event collection counter is where this turns up. A grouping of six owing
 * RM 240.00 and having paid RM 40.00 will arrive on the day with their shirts
 * ordered, and both of the obvious answers are wrong: handing them over quietly
 * loses the organiser the balance with no record of the decision, and refusing flatly
 * strands six people at a counter that cannot take the money either.
 *
 * So the handover is allowed with a reason typed in, and the reason lives here.
 * Deliberately a second column rather than reusing override_reason, which answers
 * "was this person who they said they were". Two different questions get asked of
 * this record by two different people, and one text box holding either would make
 * both of them unanswerable by query.
 *
 * Nullable, additive, no default to write over anything, and nothing existing reads
 * it: every row already in the table keeps meaning exactly what it meant. The shop's
 * own handover never sets it, because a shop order cannot be collected until it is
 * paid in the first place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_handovers', function (Blueprint $table) {
            // Beside the other override, so anybody reading the schema sees that
            // there are two ways past a refusal and that both are recorded.
            $table->string('payment_override_reason', 255)->nullable()->after('override_reason');
        });
    }

    public function down(): void
    {
        Schema::table('collection_handovers', function (Blueprint $table) {
            $table->dropColumn('payment_override_reason');
        });
    }
};
