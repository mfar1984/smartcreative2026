<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Location and Address are two separate things on the form, so the
            // single `venue` column becomes `location` and gains a sibling.
            $table->renameColumn('venue', 'location');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->text('address')->nullable()->after('location');

            // Poster image, stored on the public disk.
            $table->string('poster_path')->nullable()->after('image');

            // individual = one person per registration
            // manager    = a manager registers a squad of players
            $table->string('registration_mode', 20)->default('individual')->after('status');

            // Only meaningful in manager mode. Null max means unlimited.
            $table->unsignedSmallInteger('min_players')->nullable()->after('registration_mode');
            $table->unsignedSmallInteger('max_players')->nullable()->after('min_players');

            // Without a closing date there is no way to stop entries.
            $table->date('registration_opens_at')->nullable()->after('max_players');
            $table->date('registration_closes_at')->nullable()->after('registration_opens_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn([
                'address',
                'poster_path',
                'registration_mode',
                'min_players',
                'max_players',
                'registration_opens_at',
                'registration_closes_at',
            ]);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->renameColumn('location', 'venue');
        });
    }
};
