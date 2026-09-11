<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record which person an extra was chosen for.
 *
 * A squad ordering shirts picked three larges, one XL and two 2XLs, and nothing
 * anywhere said who wore what. The order was correct and useless: the shirts
 * arrive as a pile of sizes with no names against them, and somebody has to
 * guess on the day.
 *
 * per_participant turns an add-on from a bulk quantity into one choice per
 * person, and event_participant_id is where that choice is recorded.
 *
 * Nothing about pricing changes. The add-on's own price is still charged once per
 * registration and size surcharges are still per unit, so switching an existing
 * add-on to per_participant does not alter what anybody is charged. Whether a
 * shirt should instead be priced per head is a separate decision and a separate
 * change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_addons', function (Blueprint $table) {
            /*
             | False keeps every existing add-on exactly as it behaves today: a
             | quantity per option, chosen once for the whole entry.
             */
            $table->boolean('per_participant')->default(false)->after('is_required');
        });

        Schema::table('event_registration_addons', function (Blueprint $table) {
            /*
             | Null for a bulk line, which is most of them. Cascades with the person:
             | if a participant row is deleted the line describing their shirt has
             | nothing left to describe, unlike a consent record which stands alone.
             */
            $table->foreignId('event_participant_id')
                ->nullable()
                ->after('event_registration_id')
                ->constrained()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('event_registration_addons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_participant_id');
        });

        Schema::table('event_addons', function (Blueprint $table) {
            $table->dropColumn('per_participant');
        });
    }
};
