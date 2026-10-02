<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a multi-person entry has to be given a group name.
 *
 * True by default, because the public form has asked for one on every multi-person
 * registration for as long as the mode has existed. Every stored event therefore keeps
 * behaving exactly as it does today and no data migration is needed.
 *
 * What it adds is the ability to switch the question off for a grouping event that has no
 * group to name — a family or a few colleagues signing up together, where the only honest
 * answer would be the first person's own name. With it off the public form skips the field
 * and the first person is simply Participant 1 rather than the group contact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('requires_group_name')
                ->default(true)
                ->after('registration_mode');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('requires_group_name');
        });
    }
};
