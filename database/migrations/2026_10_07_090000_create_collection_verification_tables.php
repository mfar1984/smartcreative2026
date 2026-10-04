<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proving who physically took goods away from a counter.
 *
 * Three tables, all additive, and nothing is backfilled. An order already recorded
 * as collected keeps the note it was given and simply has no row here, which reads
 * correctly: the handover panels only appear when there is something to show.
 *
 *   collection_verifications          one row per code issued
 *   collection_verification_attempts  one row per entry of a code
 *   collection_handovers              one row per thing actually handed over
 *
 * Polymorphic on purpose. The shop hands over an order; the event side will hand
 * over one shirt per participant. Neither is named in here, so the second one needs
 * no further migration.
 *
 * Portable across sqlite and MySQL: no stored expressions, no JSON predicates, no
 * enums, and every index named explicitly so none of them runs past MySQL's
 * 64 character limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_verifications', function (Blueprint $table) {
            $table->id();

            /*
             | What is being collected. Written as the two columns rather than
             | morphs() so the index can be named: the generated name on a table
             | called collection_verifications is long enough to be worth avoiding.
             */
            $table->string('verifiable_type', 190);
            $table->unsignedBigInteger('verifiable_id');

            /*
             | Where the code went, in international digits as the gateway received
             | them. Indexed because the per-number rate limit counts against it, and
             | normalised so 017-859 1411 and 60178591411 cannot be used as two
             | different numbers to get around that limit.
             */
            $table->string('phone', 20);

            /*
             | The code, hashed, and never anything else.
             |
             | A live code readable by anybody with database access would defeat the
             | whole mechanism: the code is the only thing standing between a stranger
             | and somebody else's goods. Nothing in this table records its digits,
             | not even the last few of them.
             */
            $table->string('code_hash');

            $table->unsignedTinyInteger('attempts')->default(0);

            /*
             | The limit as it stood when this code was issued, rather than read back
             | from the settings at verification time. A code is judged by the rules
             | it was created under, so changing the setting mid-event cannot
             | retroactively burn or revive one already in somebody's hand.
             */
            $table->unsignedTinyInteger('max_attempts');

            $table->dateTime('expires_at');

            // Null until the gateway accepted it. The cooldown and the per-number
            // limit both count sent codes only, so a gateway failure can be retried
            // at once rather than locking the counter out for two minutes.
            $table->dateTime('sent_at')->nullable();

            $table->dateTime('verified_at')->nullable();

            // Burned: used, expired, out of attempts, or replaced by a newer code.
            // One column rather than a status, because every one of those means the
            // same thing to the only question asked of it — can this still be used.
            $table->dateTime('burned_at')->nullable();

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('issued_ip', 45)->nullable();

            // What the gateway said when it took the message. Accepted is not
            // delivered, which is exactly why both are kept.
            $table->string('gateway_message_id', 190)->nullable();
            $table->string('gateway_status', 30)->nullable();

            $table->timestamps();

            $table->index(['verifiable_type', 'verifiable_id'], 'cv_verifiable_index');
            $table->index(['phone', 'sent_at'], 'cv_phone_sent_index');
        });

        /*
        | Every entry of a code, right or wrong.
        |
        | A counter against the code alone would answer "was it guessed at" but not
        | "who was guessing, and from where", which is the question asked when goods
        | go missing. The submitted digits are never stored: a wrong guess is still
        | somebody's near miss at a live code.
        */
        Schema::create('collection_verification_attempts', function (Blueprint $table) {
            $table->id();

            /*
             | The constraint is named by hand. The name MySQL would generate from
             | this table and this column — collection_verification_attempts plus
             | collection_verification_id plus _foreign — is 67 characters, and MySQL
             | refuses an identifier over 64. sqlite does not care either way.
             */
            $table->foreignId('collection_verification_id')
                ->constrained(indexName: 'cva_verification_foreign')
                ->cascadeOnDelete();

            $table->string('outcome', 20);

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_label', 190)->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index('collection_verification_id', 'cva_verification_index');
        });

        /*
        | Who actually walked away with the goods.
        |
        | The record that answers a dispute months later, which is why it is
        | structured rather than a free-text note: a name, an identity card number, a
        | telephone number, whether a code was verified or deliberately skipped, the
        | reason if it was skipped, and which member of staff pressed the button.
        */
        Schema::create('collection_handovers', function (Blueprint $table) {
            $table->id();

            $table->string('collectable_type', 190);
            $table->unsignedBigInteger('collectable_id');

            // buyer, or someone acting for them. See CollectionHandover::KINDS.
            $table->string('collector_kind', 20);

            $table->string('collector_name', 190)->nullable();
            $table->string('collector_ic', 30)->nullable();
            $table->string('collector_phone', 30)->nullable();

            $table->foreignId('collection_verification_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->dateTime('verified_at')->nullable();

            // Filled only when staff completed the handover without a working code.
            // Its presence is what marks the record as unverified, so there is no
            // second column to contradict it.
            $table->string('override_reason', 255)->nullable();

            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('confirmed_by_label', 190)->nullable();

            $table->dateTime('collected_at');

            $table->timestamps();

            // One handover per thing collected. The controller already refuses a
            // second press; this makes two simultaneous presses impossible rather
            // than unlikely.
            $table->unique(['collectable_type', 'collectable_id'], 'ch_collectable_unique');
        });
    }

    public function down(): void
    {
        // Children first: the foreign keys would refuse their parent otherwise.
        Schema::dropIfExists('collection_handovers');
        Schema::dropIfExists('collection_verification_attempts');
        Schema::dropIfExists('collection_verifications');
    }
};
