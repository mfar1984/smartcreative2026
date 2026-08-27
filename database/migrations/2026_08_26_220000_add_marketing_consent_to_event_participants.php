<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record whether each person agreed to be contacted about anything else.
     *
     * Registration email is transactional: the person asked for it by submitting
     * the form. News and invitations are not, and sending them to the same list
     * without agreement is what gets a sending domain marked as spam. Once that
     * happens the registration email stops arriving too, so this column protects
     * the transactional mail as much as it protects the recipient.
     *
     * Kept on the participant row rather than only on the deduplicated contact
     * list because this is the evidence: it says what was ticked, on which entry,
     * and when. The contact list is derived from it and can be rebuilt; this
     * cannot.
     *
     * Defaults to false. An unticked box is a no, and a column that defaulted to
     * true would turn every existing registration into a marketing list nobody
     * agreed to join.
     */
    public function up(): void
    {
        Schema::table('event_participants', function (Blueprint $table) {
            $table->boolean('marketing_consent')->default(false)->after('email');

            // When it was given, so the age of an agreement can be judged.
            $table->timestamp('consent_recorded_at')->nullable()->after('marketing_consent');

            // Who was at the keyboard. A manager ticks on behalf of their squad,
            // which is weak evidence on its own, so the address it came from is
            // kept alongside it.
            $table->string('consent_ip', 45)->nullable()->after('consent_recorded_at');
        });
    }

    public function down(): void
    {
        Schema::table('event_participants', function (Blueprint $table) {
            $table->dropColumn(['marketing_consent', 'consent_recorded_at', 'consent_ip']);
        });
    }
};
