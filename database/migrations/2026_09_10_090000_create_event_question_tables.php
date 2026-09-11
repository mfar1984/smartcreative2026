<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Questions an organiser adds to their own registration form.
 *
 * Two kinds, and the difference is only is_required: a terms agreement nobody can
 * submit without, and an optional question such as "are you a Unifi customer".
 * The wording is entirely the organiser's, because the whole point is asking
 * something this application could not have anticipated.
 *
 * Asked of every person on the entry rather than once per entry. A squad of seven
 * therefore produces seven answers, which is what makes the optional ones worth
 * counting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            // The label beside the box, for example "Rules & Terms".
            $table->string('title', 190);

            /*
             | The longer text under it, which is where the actual terms go. Optional
             | because "Are you a Unifi customer?" needs no explanation, while a
             | terms agreement is mostly this field.
             */
            $table->text('body')->nullable();

            /*
             | Required means the form is refused until it is ticked. Enforced on the
             | server, not by the HTML attribute, which anyone can delete from their
             | own browser in seconds.
             */
            $table->boolean('is_required')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['event_id', 'sort_order']);
        });

        Schema::create('event_participant_answers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_participant_id')->constrained()->cascadeOnDelete();

            /*
             | Nulled rather than cascaded when the question is deleted. An organiser
             | tidying up their form must not erase the record that somebody agreed
             | to something, and the snapshot below means the row still reads as
             | itself once the parent is gone.
             */
            $table->foreignId('event_question_id')->nullable()->constrained()->nullOnDelete();

            /*
             | The wording as it was shown to this person.
             |
             | This is the part that matters. Without it, editing the terms next month
             | rewrites what everybody who already registered appears to have agreed
             | to, and the record stops being evidence of anything. Same reasoning as
             | the snapshots on shop_order_items, and it counts for more here.
             */
            $table->string('question_title', 190);
            $table->text('question_body')->nullable();
            $table->boolean('was_required')->default(false);

            $table->boolean('answered')->default(false);

            // When the answer was given, which for a consent is half the record.
            $table->timestamp('answered_at')->nullable();

            $table->timestamps();

            // One answer per person per question, and the lookup for both the
            // registration detail and the reporting counts.
            $table->unique(['event_participant_id', 'event_question_id'], 'epa_participant_question_unique');
            $table->index('event_question_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_participant_answers');
        Schema::dropIfExists('event_questions');
    }
};
