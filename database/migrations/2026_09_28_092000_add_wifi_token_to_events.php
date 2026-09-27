<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The secret in the URL the router fetches.
 *
 * Per event rather than one for the whole site, and that is the point of it. The address
 * it guards returns a script containing every competitor's login in the clear, so if it
 * is ever forwarded, pasted into a group chat, or left in a router's command history,
 * the damage should stop at one event that is nearly over rather than reaching every
 * event this site will ever run.
 *
 * It also means rotating is cheap. A new token for one event breaks one scheduled fetch
 * that has to be repasted anyway; a site-wide one could not be rotated during an event
 * without silently cutting off the router.
 *
 * Nullable, because only an event that offers Wi-Fi needs one and it is minted on first
 * use rather than for every event that has ever existed.
 *
 * The real protection is still not this column. It is that the credentials it exposes
 * stop working at the end of the event, which holds even if the token leaks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('wifi_token', 64)
                ->nullable()
                ->unique()
                ->after('offers_wifi');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropUnique(['wifi_token']);
            $table->dropColumn('wifi_token');
        });
    }
};
