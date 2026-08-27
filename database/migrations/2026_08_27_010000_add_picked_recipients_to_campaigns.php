<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record who was actually picked, not merely which segment was chosen.
 *
 * A segment is a rule evaluated at send time, and the answer it gives changes as
 * people register, pay or turn up. That is fine for describing intent but useless
 * as a record: a report has to be able to say who received the message, and
 * re-running the rule a month later gives a different set of people.
 *
 * Nullable, because a campaign may still be addressed by rule alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->json('audience_contact_ids')->nullable()->after('audience_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('audience_contact_ids');
        });
    }
};
