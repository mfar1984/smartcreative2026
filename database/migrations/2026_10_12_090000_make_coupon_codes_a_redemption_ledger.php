<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * coupon_codes stops being a stock of pre-minted codes and becomes a redemption ledger.
 *
 * WHY
 *
 * The batch name is now the code, and `quantity` is how many times it may be used.
 * Minting N unique bearer codes only buys a per-person audit trail if there is a
 * membership database to issue them against, and this system has none: whoever reads a
 * code uses it. So the uniqueness bought nothing, while being far harder to hand out
 * than one shared code.
 *
 * WHAT THIS DOES TO EXISTING ROWS
 *
 *   redeemed_at null      deleted. These are pre-minted codes nobody ever used: no
 *                         money moved and no record points at them.
 *   redeemed_at set       LEFT ALONE, every one of them. A redeemed row is the record
 *                         a real discount on a registration or an order points at, and
 *                         Tracking and Report both read this table. Deleting one would
 *                         leave a registration reading "RM 40.00 discount" with nothing
 *                         to say where it came from.
 *
 * On the live database at the time of writing that is ten unused rows for one batch
 * (NG68BJ) and zero redemptions anywhere, so nothing of value is lost. The WHERE
 * clause is what makes that safe in general rather than only today.
 *
 * WHY THE UNIQUE INDEX ON `code` GOES
 *
 * A ledger row stores the string that was typed, which is the batch name, so several
 * uses of one coupon each hold the same string. A unique index would refuse the second
 * redemption. It is replaced with a plain index, which is what the Tracking screen
 * actually needs.
 */
return new class extends Migration
{
    /** The index Laravel named when the column was declared unique. */
    private const UNIQUE_INDEX = 'coupon_codes_code_unique';

    public function up(): void
    {
        /*
         | Unused pre-minted rows, and only those. Run before the index change so a
         | half-applied migration leaves the smaller table rather than the larger one.
         */
        DB::table('coupon_codes')->whereNull('redeemed_at')->delete();

        /*
         | Guarded, because down() cannot honestly undo this (see below) and a
         | re-migrate after a rollback would otherwise try to drop an index that is
         | already gone.
         */
        if (Schema::hasIndex('coupon_codes', self::UNIQUE_INDEX)) {
            Schema::table('coupon_codes', function (Blueprint $table) {
                $table->dropUnique(self::UNIQUE_INDEX);
            });
        }

        if (! Schema::hasIndex('coupon_codes', 'coupon_codes_code_index')) {
            Schema::table('coupon_codes', function (Blueprint $table) {
                $table->index('code');
            });
        }
    }

    /**
     * Deliberately a no-op.
     *
     * Neither half of up() can be honestly reversed:
     *
     *   the deleted rows   were codes nobody held and nobody used. Writing fresh
     *                      random strings back would invent stock that never existed
     *                      and that no printed coupon matches — a worse state than the
     *                      one being rolled back from.
     *   the unique index   cannot be restored while any batch has been redeemed more
     *                      than once, because those rows legitimately share a code.
     *                      Restoring it would fail the rollback outright.
     *
     * Leaving the plain index in place is harmless: it is a superset of what the old
     * schema could do, minus a constraint the data no longer satisfies. Rolling this
     * migration back and re-running it is safe, which is what matters.
     */
    public function down(): void
    {
        //
    }
};
