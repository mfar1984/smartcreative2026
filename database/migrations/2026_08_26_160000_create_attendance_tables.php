<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per person who has turned up.
        //
        // Absence is deliberately not stored: someone is absent when no row
        // exists for them. That keeps the two states from ever disagreeing, and
        // means an event that has not started yet reads as everyone absent
        // rather than needing rows created up front.
        Schema::create('event_attendances', function (Blueprint $table) {
            $table->id();

            // Denormalised from the registration so the per event lists and
            // counts do not have to join through it.
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            $table->foreignId('event_registration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_participant_id')->constrained()->cascadeOnDelete();

            // A person can only arrive once. A repeat submission updates this
            // row instead of adding a second arrival.
            $table->unique('event_participant_id');

            $table->timestamp('checked_in_at');
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();

            // Whether the counter actually saw the physical identity card.
            // Checking someone in without one is allowed, because turning a
            // paid participant away over a forgotten card is worse, but the
            // difference is recorded since that is what matters if the entry is
            // ever disputed.
            $table->boolean('ic_verified')->default(true);

            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'checked_in_at']);
        });

        // Audit of players swapped out at the counter.
        //
        // A squad often arrives with someone different from whoever the manager
        // named weeks earlier. The swap rewrites the participant row, so without
        // this table there would be no record that it ever happened.
        Schema::create('event_participant_changes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_registration_id')->constrained()->cascadeOnDelete();

            // Nullable on delete: removing a participant must not erase the
            // record of them having been substituted in.
            $table->foreignId('event_participant_id')
                ->nullable()
                ->constrained('event_participants')
                ->nullOnDelete();

            // Copied in rather than read back through the relation, so the audit
            // still reads correctly after a second swap on the same row.
            $table->string('previous_name');
            $table->string('previous_ic', 32);
            $table->string('new_name');
            $table->string('new_ic', 32);

            // The whole record either side, for anything the four columns above
            // do not carry. Prefixed because BEFORE and AFTER are reserved words
            // in MySQL, which breaks any raw query that touches them unquoted.
            $table->json('details_before')->nullable();
            $table->json('details_after')->nullable();

            $table->string('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_participant_changes');
        Schema::dropIfExists('event_attendances');
    }
};
