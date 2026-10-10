<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sponsorship accounts, and the blocks of codes a sponsor funded.
 *
 * A SPONSOR IS AN ORDINARY USERS ROW, exactly as a handler is: the sponsor role plus
 * a flag, signing in at the same /admin/login. No second guard and no second accounts
 * table — the handler feature settled that shape and nothing here needs a different
 * one.
 *
 * WHY THE TAG SITS ON THE ALLOCATION AND NOT ON THE BATCH
 *
 * The owner's case is an NGO commissioning a thousand codes handed out through ten
 * representatives, and his question is whose block ran out first. That question is
 * asked of a block, so the block is what a sponsor funds. A batch-level tag could not
 * express it: one batch is routinely split between sponsors, and a second block issued
 * next month would silently inherit a tag nobody chose.
 *
 * One sponsor per allocation, which is the other half of keeping the money honest. Two
 * sponsors on one block would mean the same discount counted twice, once on each of
 * their screens, and neither figure could be called wrong.
 *
 * nullOnDelete, so deleting a sponsorship account releases its blocks instead of
 * destroying codes that have already been printed and handed out.
 *
 * WHERE THE COMMITTED AMOUNT LIVES, AND WHY NOT ON THE BATCH
 *
 * `coupons.committed_amount` already exists and stays exactly as it is: it is what was
 * pledged against that COUPON TYPE, which is what the staff Report shows. A sponsor's
 * own pledge is a different number — the owner's example is RM2,000 against coupons
 * worth RM20, and the same batch may carry blocks from two sponsors — so reusing the
 * batch figure would show a sponsor somebody else's money. It is therefore recorded
 * on the sponsorship account, typed by hand, derived from nothing, and optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             | The pair the login landing and the Sponsorship tab read. Written as a
             | constant by the three sponsor endpoints and by nothing else, the same
             | way is_handler is.
             */
            $table->boolean('is_sponsor')->default(false)->after('is_handler');

            // What this sponsor pledged, in ringgit. A promise somebody made, not a
            // figure the system can work out, so it is nullable and never derived.
            $table->decimal('sponsor_committed_amount', 12, 2)->nullable()->after('is_sponsor');
        });

        Schema::table('coupon_allocations', function (Blueprint $table) {
            /*
             | The foreign key is the index as well. MySQL indexes a constrained
             | column on its own, which is exactly the lookup the sponsor's area
             | runs — my blocks — so a second declared index would be the same
             | index twice, and one MySQL then refuses to drop on the way back
             | down because the constraint still needs it.
             */
            $table->foreignId('sponsor_user_id')->nullable()->after('coupon_holder_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Honestly reversible: everything up() added is new, and nothing existing moved.
     */
    public function down(): void
    {
        Schema::table('coupon_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sponsor_user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_sponsor', 'sponsor_committed_amount']);
        });
    }
};
