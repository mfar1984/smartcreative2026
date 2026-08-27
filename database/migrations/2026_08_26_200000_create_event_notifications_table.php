<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A record of every message the event module sent, or tried to.
     *
     * Needed because the player notice is the only place somebody finds out
     * their identity card was entered by a manager. Without this there would be
     * no way to answer "when did you tell this person", and no way to know a
     * message bounced.
     */
    public function up(): void
    {
        Schema::create('event_notifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_registration_id')->constrained()->cascadeOnDelete();

            // Which template produced it, and over which channel.
            $table->string('template_key', 60);
            $table->string('channel', 20);

            $table->string('recipient');

            // Who this copy speaks for. A list because one address can stand for
            // several players, and a single message then covers all of them.
            // Stored as ids rather than a relation so the record survives a
            // participant being swapped out at the counter afterwards.
            $table->json('participant_ids')->nullable();

            // queued | sent | failed | skipped
            $table->string('status', 20)->default('queued')->index();

            // Why nothing was sent, for a skip or a failure. Kept because "no
            // email address on file" is the answer to a question somebody will
            // ask later.
            $table->text('reason')->nullable();

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['event_registration_id', 'template_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_notifications');
    }
};
