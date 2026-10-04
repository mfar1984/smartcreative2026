<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a grouping registration pays for each participant's add-ons separately.
 *
 * False by default, because an add-on's own price has been one charge for the whole
 * registration for as long as add-ons have existed. Every stored event therefore keeps
 * pricing exactly as it does today and no data migration is needed.
 *
 * What it adds is the other reading, which is the right one for merchandise: a shirt is
 * bought by a person, so ten participants choosing a RM40 shirt owe RM400 rather than
 * RM40. The event fee is untouched either way — that stays one flat charge per
 * registration, whatever the party size.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('charges_addons_per_participant')
                ->default(false)
                ->after('requires_group_name');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('charges_addons_per_participant');
        });
    }
};
