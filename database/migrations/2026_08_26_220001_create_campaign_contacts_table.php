<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per person who could be sent a campaign.
     *
     * Participants are the wrong unit to send from: somebody who has entered
     * three events is three participant rows, and a campaign must reach them
     * once. This table is that person, keyed by how they are reached.
     *
     * It is the single place a campaign asks "may we send to this address". The
     * participant rows are the evidence of what was agreed; this is the working
     * answer, and it is the only thing an unsubscribe has to update.
     */
    public function up(): void
    {
        Schema::create('campaign_contacts', function (Blueprint $table) {
            $table->id();

            // Either can stand alone: somebody entered at a counter has a phone
            // and no address. Unique so the same person cannot be reached twice
            // by one campaign, which is the whole reason this table exists.
            $table->string('email')->nullable()->unique();

            // Stored in international digits rather than as typed, because
            // 017-859 1411 and 0178591411 are one handset and a uniqueness rule
            // on the typed form would not see that.
            $table->string('phone', 20)->nullable()->unique();

            $table->string('name')->nullable();

            // Per channel, because agreeing to email is not agreeing to be texted.
            $table->boolean('consent_email')->default(false);
            $table->boolean('consent_sms')->default(false);

            $table->timestamp('consented_at')->nullable();

            // registration | enquiry | admin | import
            $table->string('consent_source', 20)->nullable();
            $table->string('consent_ip', 45)->nullable();

            /*
            | Suppression. Any of these three means never send again, and they
            | outrank a later consent tick: somebody who asked to be left alone
            | has said something more deliberate than a manager ticking a box on
            | their behalf. Only an explicit resubscribe clears an unsubscribe.
            */
            $table->timestamp('unsubscribed_at')->nullable()->index();
            $table->string('unsubscribe_reason')->nullable();

            // A hard bounce means the address does not exist. Continuing to send
            // to it is what turns a sending reputation bad.
            $table->timestamp('bounced_at')->nullable();
            $table->string('bounce_reason')->nullable();

            // Marked by hand when somebody complains directly.
            $table->timestamp('complained_at')->nullable();

            /*
            | Identifies this contact in an unsubscribe link without exposing an
            | id that could be counted up through. Random and unique rather than
            | derived, so one leaked link cannot be used to work out another.
            */
            $table->string('token', 64)->unique();

            // Where they came from, for the audience segments. Nulled rather than
            // cascaded: losing the event must not lose the contact.
            $table->foreignId('first_event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->index(['consent_email', 'unsubscribed_at']);
            $table->index(['consent_sms', 'unsubscribed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_contacts');
    }
};
