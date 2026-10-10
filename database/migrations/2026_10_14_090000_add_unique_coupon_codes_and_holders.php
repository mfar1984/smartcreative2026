<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A batch gains a MODE, unique codes are issued in ALLOCATIONS, and the ledger gains
 * the PARTICIPANT each use covered.
 *
 * WHY THIS IS NOT A REVERT OF THE LEDGER MIGRATION
 *
 * 2026_10_12_090000 removed minted codes because a unique bearer code bought no
 * per-person audit: with no membership database, whoever read the code used it, and
 * nobody could say whose code was whose. The missing half was IDENTITY, and the owner
 * now supplies it by hand — each block of codes is issued to a named holder. That is
 * what makes a unique code mean something, so both models now coexist as a mode on the
 * batch:
 *
 *   shared  today's behaviour, unchanged. The batch NAME is the code and `quantity` is
 *           how many times it may be used. For a poster.
 *   unique  codes are minted in blocks, each block issued to a holder. For
 *           distribution through representatives, where "whose allocation ran out" is
 *           the question.
 *
 * Every existing batch is written as `shared` explicitly, so nothing about a live
 * coupon changes.
 *
 * THE FOUR LEVELS, AND WHY AN ALLOCATION SITS IN THE MIDDLE
 *
 *   coupons              the coupon type: name, kind, discount, expiry, design, and
 *                        what a sponsor committed.
 *   coupon_allocations   ONE ISSUE of codes: how many, to whom, when. Issuing a
 *                        hundred more later is a NEW allocation with its own holder,
 *                        never a top-up of the first — that is how a second
 *                        representative gets their own block.
 *   coupon_issued_codes  the codes themselves, each belonging to an allocation and so
 *                        to its holder.
 *   coupon_codes         the ledger, one row per participant covered.
 *
 * The allocation is what makes the redemption rule natural rather than an extra
 * constraint: a typed code identifies its allocation, so "uses come from that holder's
 * own allocation" is simply "counted inside that allocation".
 *
 * WHY HOLDERS ARE THEIR OWN TABLE
 *
 * A representative may hold a hundred codes. Repeating their name, email, IC and phone
 * would mean a single typo fragmenting them, and the "whose allocation is finished"
 * grouping splitting one person into two while still looking grouped. One holder row,
 * referenced by the allocation, so grouping is a foreign key rather than a string
 * comparison — and editing a holder edits every code in the block at once.
 *
 * Scoped to the coupon, because a batch is one sponsor's commitment: the allocation
 * question is always asked inside a batch, and the same person acting for two sponsors
 * is two separate allocations. Deleting a batch takes its holder list with it, which is
 * right — those rows exist only to describe that batch's distribution.
 *
 * `identity_key` is the stable grouping key: the IC if there is one, else the email,
 * else the normalised name. Unique per batch, so two blocks issued to the same person
 * land on the same holder even when the name was typed slightly differently. See
 * App\Support\CouponHolderIdentity.
 *
 * WHAT THE LEDGER GAINS
 *
 * coupon_codes is still the redemption ledger and every existing row is untouched —
 * they are the record real discounts on real registrations point at, and Tracking and
 * Report read them. It gains a nullable participant reference plus a copy of that
 * participant's name, because a use is now counted PER PARTICIPANT on an event that
 * charges per participant: a group of ten entering one code writes ten rows, each
 * naming the person it covered.
 *
 * The name is copied rather than only joined for the same reason `code` is: the row
 * has to keep reading sensibly after a participant is removed, and nullOnDelete would
 * otherwise leave a ledger line with nobody's name on it.
 *
 * A NOTE ON WHOSE PERSONAL DATA IS WHERE
 *
 * coupon_holders holds the REPRESENTATIVE who hands codes out — the sponsor's or
 * NGO's own staff — and their contact details exist so the office can trace a code.
 * coupon_codes.participant_name holds the REDEEMER, the person who actually used it,
 * and deliberately carries a NAME ONLY. No IC, no phone, no payment detail. A later
 * sponsor-facing view shows redeemers, and the shape of this table is what stops it
 * being able to leak a participant's IC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            /*
             | shared or unique. Defaulted to shared so a row written by anything that
             | has not been told about modes keeps today's behaviour.
             */
            $table->string('mode', 10)->default('shared')->after('kind');

            /*
             | What a sponsor committed, in ringgit. Optional, and deliberately NOT
             | derived from anything: it is a promise somebody made, not a figure the
             | system can work out. Everything else on the Report is computed, and the
             | screen labels which is which.
             */
            $table->decimal('committed_amount', 12, 2)->nullable()->after('discount_value');
        });

        // Explicit rather than left to the column default, so an existing batch reads
        // as shared in the data and not only in the absence of a value.
        DB::table('coupons')->update(['mode' => 'shared']);

        Schema::table('coupons', function (Blueprint $table) {
            $table->index('mode');
        });

        Schema::create('coupon_holders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();

            /*
             | All four optional, because the owner may know only a name, or only a
             | phone. What is NOT allowed is all four empty, which is not a holder at
             | all — the form request refuses that rather than the schema, so the
             | operator gets told why.
             */
            $table->string('full_name')->nullable();
            $table->string('email')->nullable();
            $table->string('ic_number', 32)->nullable();
            $table->string('phone', 32)->nullable();

            /*
             | IC, else email, else the normalised name. 191 so the unique index below
             | fits inside MySQL's utf8mb4 key length on older servers.
             */
            $table->string('identity_key', 191);

            $table->timestamps();

            $table->unique(['coupon_id', 'identity_key']);
        });

        Schema::create('coupon_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();

            /*
             | Null is a valid, deliberate state: an unassigned block works and groups
             | under "Not assigned". nullOnDelete rather than cascade, because removing
             | a holder must never silently destroy the codes issued to them.
             */
            $table->foreignId('coupon_holder_id')->nullable()->constrained()->nullOnDelete();

            // How many codes this block put out. The codes themselves are the record;
            // this is what was asked for, kept so a short mint would be visible.
            $table->unsignedInteger('quantity');

            // When the block went out, for the audit trail and for ordering blocks on
            // the report. A real instant, so it is shifted for display.
            $table->dateTime('issued_at');

            $table->timestamps();

            $table->index(['coupon_id', 'coupon_holder_id']);
        });

        Schema::create('coupon_issued_codes', function (Blueprint $table) {
            $table->id();

            /*
             | The batch, denormalised off the allocation on purpose. Resolving a typed
             | code to its batch and counting a batch's whole stock are both one hop
             | this way, and the two columns cannot drift: a code is never moved
             | between allocations.
             */
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();

            $table->foreignId('coupon_allocation_id')->constrained()->cascadeOnDelete();

            /*
             | The string somebody types. Unique across this table, and
             | Coupon::codeTaken() also refuses one that collides with a batch name:
             | the two go into the same box on the public form.
             */
            $table->string('code', 32)->unique();

            // Stamped when the code is spent. This is what an allocation's remaining
            // balance is counted from, under the batch lock.
            $table->dateTime('used_at')->nullable();

            /*
             | The ledger row this code paid for, one to one: a code covers exactly one
             | participant. nullOnDelete so a removed ledger row cannot take the record
             | of the code with it.
             */
            $table->foreignId('coupon_code_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // The two queries that matter: this batch's stock, and this allocation's
            // unused balance.
            $table->index(['coupon_id', 'used_at']);
            $table->index(['coupon_allocation_id', 'used_at'], 'coupon_issued_allocation_index');
        });

        Schema::table('coupon_codes', function (Blueprint $table) {
            // The person this row's share of the discount covered. Null when the use
            // covers the registration as a whole rather than one named head.
            $table->foreignId('event_participant_id')->nullable()->after('event_registration_id')
                ->constrained()
                ->nullOnDelete();

            // A copy of their name, so the line still reads after the participant is
            // removed. The REDEEMER's name only — never their IC or phone.
            $table->string('participant_name')->nullable()->after('event_participant_id');
        });
    }

    /**
     * Honestly reversible: everything up() added is new, and nothing existing moved.
     *
     * The ledger rows that were already there are not touched on the way down any
     * more than they were on the way up. The three new tables and the four new columns
     * simply go, which puts the schema back exactly where it was.
     */
    public function down(): void
    {
        Schema::table('coupon_codes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_participant_id');
            $table->dropColumn('participant_name');
        });

        Schema::dropIfExists('coupon_issued_codes');
        Schema::dropIfExists('coupon_allocations');
        Schema::dropIfExists('coupon_holders');

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropIndex(['mode']);
            $table->dropColumn(['mode', 'committed_amount']);
        });
    }
};
