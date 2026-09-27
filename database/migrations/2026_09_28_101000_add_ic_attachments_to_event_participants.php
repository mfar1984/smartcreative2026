<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a competitor's identity card photographs are kept.
 *
 * Paths only, and paths on a private disk. This is deliberately not how the event logo is
 * stored: a logo lives on the public disk because it is meant to be seen, and anything on
 * that disk is served straight off the filesystem by the web server to anybody who can
 * work out the URL. A photograph of an identity card must never be reachable that way, so
 * these point into storage the web server does not publish, and reading one goes through a
 * route that checks who is asking.
 *
 * Nullable, because the requirement belongs to the event. Most events will never ask, and
 * the entries that were taken before an event switched it on keep their empty columns
 * rather than being treated as incomplete.
 *
 * Front and back as separate columns rather than a JSON list. There are exactly two, they
 * are not interchangeable, and a missing back is a different thing from a missing front —
 * which a list of however many files cannot express without the reader knowing the order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_participants', function (Blueprint $table) {
            $table->string('ic_front_path', 255)->nullable()->after('ic_number');
            $table->string('ic_back_path', 255)->nullable()->after('ic_front_path');
        });
    }

    public function down(): void
    {
        Schema::table('event_participants', function (Blueprint $table) {
            $table->dropColumn(['ic_front_path', 'ic_back_path']);
        });
    }
};
