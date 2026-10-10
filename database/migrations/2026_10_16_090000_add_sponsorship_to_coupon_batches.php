<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sponsorship on the BATCH, beside the one already on each block.
 *
 * WHY A SECOND PLACE FOR THE SAME TAG, WHEN THE LAST MIGRATION ARGUED FOR ONE
 *
 * 2026_10_15_090000 put the tag on the allocation, and the reasoning there still
 * holds: an NGO's thousand codes go out through ten representatives, and "whose block
 * ran out first" is asked of a block. What that reasoning missed is that a SHARED
 * batch has no blocks at all. CouponIssuer refuses to issue unless the batch is
 * unique, so a shared batch never gets an allocation, and a tag that only lives on an
 * allocation therefore could not reach it. A sponsor pledging RM2,000 against RM100
 * coupons is perfectly well one shared code used twenty times, and that case was
 * excluded by accident rather than by decision.
 *
 * So the tag now exists at both levels, with ONE unambiguous rule:
 *
 *   THE BATCH-LEVEL SPONSORSHIP APPLIES TO EVERY BLOCK IN THE BATCH, UNLESS THAT
 *   BLOCK NAMES ITS OWN, WHICH OVERRIDES IT FOR THAT BLOCK ONLY.
 *
 * The rule lives in CouponAllocation::scopeSponsoredBy() and ::effectiveSponsorId(),
 * which is the one implementation every screen and every figure reads. Nothing is
 * copied down: a block left blank stays blank in the database and resolves through its
 * batch when it is read, so moving the batch's sponsorship moves the blocks with it
 * and a block that was deliberately pointed elsewhere stays pointed there.
 *
 * NO SYNTHETIC ALLOCATION FOR A SHARED BATCH. It would have given the tag somewhere
 * familiar to sit, at the price of a row of codes that do not exist — hiding the real
 * difference between the two modes behind something that looks like a block and is
 * not.
 *
 * nullOnDelete, for the same reason the allocation column has it: deleting a
 * sponsorship account releases the batch rather than destroying coupons that are
 * already in use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            /*
             | The foreign key is the index as well. MySQL indexes a constrained
             | column on its own, which is exactly the lookup a sponsor's area runs —
             | my batches — so a second declared index would be the same index twice,
             | and one MySQL then refuses to drop on the way back down because the
             | constraint still needs it.
             */
            $table->foreignId('sponsor_user_id')->nullable()->after('committed_amount')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /** Honestly reversible: the column is new, and nothing existing moved. */
    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sponsor_user_id');
        });
    }
};
