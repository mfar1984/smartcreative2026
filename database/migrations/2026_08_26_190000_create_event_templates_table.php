<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Message templates used by the event module.
     *
     * Global rather than per event: they live under Event > Settings alongside
     * Registration and Attendance, so one set of wording covers every event. A
     * per event override would need a nullable event_id here, which is a change
     * this table can take later without rewriting what already uses it.
     *
     * The body is plain text. It is rendered into an HTML shell at send time, so
     * an administrator cannot put markup or script into a message that lands in
     * somebody else's inbox.
     */
    public function up(): void
    {
        Schema::create('event_templates', function (Blueprint $table) {
            $table->id();

            // Identifies which moment this template speaks for, for example
            // registration.manager. Paired with the channel because the same
            // moment needs different wording by email and by SMS.
            $table->string('key', 60);
            $table->string('channel', 20);
            $table->unique(['key', 'channel']);

            // Email only. SMS has no subject line.
            $table->string('subject')->nullable();

            $table->text('body');

            // Lets a message be switched off without losing its wording, which
            // matters for the player notice: an organiser may not want it.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_templates');
    }
};
