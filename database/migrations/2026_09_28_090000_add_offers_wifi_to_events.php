<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether this event hands out Wi-Fi logins.
 *
 * On the event rather than in a global setting, because it is a fact about one day at
 * one venue. A tournament in a hall with a captive portal offers logins; a course run
 * from an office does not, and a conference at a hotel that provides its own Wi-Fi
 * should not be issuing a second set of credentials nobody asked for.
 *
 * Off by default, so nothing changes for the events that already exist. Turning it on
 * is what makes credentials appear, and the credentials themselves are only ever issued
 * against a registration that has been paid.
 *
 * Deliberately a plain flag and not a set of network settings. Where the portal lives,
 * what the router's address is, how long a session may run — none of that belongs in
 * this application. The router pulls a list; it is not configured from here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('offers_wifi')
                ->default(false)
                ->after('requires_logo');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('offers_wifi');
        });
    }
};
