<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages sent to a competitor through their public profile.
 *
 * A table of its own rather than a row in contact_messages, for two reasons. It has
 * to record who the message was about, and contact_messages has no column for that.
 * And the analytics screen counts contact_messages as service enquiries, so filing
 * these there would report somebody asking after a player as somebody asking for a
 * quotation.
 *
 * The sender's own details are stored because the office has to be able to reply to
 * them. The competitor's details are not copied here: they are already on
 * event_participants, and the link is enough.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_messages', function (Blueprint $table) {
            $table->id();

            /*
             | The participant row that was open when the message was written, not the
             | person as a whole. Which entry somebody was looking at is part of what
             | the office needs to know, and this way the event and team come with it.
             |
             | Cascades on delete: a message about an entry that has been removed has
             | lost its subject, and keeping it would leave an orphan nobody can act on.
             */
            $table->foreignId('event_participant_id')
                ->constrained('event_participants')
                ->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('phone', 30)->nullable();
            $table->text('message');

            // Kept for the same reason the contact form keeps it: the only trail
            // available if the form is abused.
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index(['event_participant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_messages');
    }
};
