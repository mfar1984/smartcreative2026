<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Security Log: every request the system REFUSED, and every probe it noticed.
 *
 * Its own table rather than another category in activity_logs, for one reason that
 * decides it: the Security tab can switch the activity and audit logs off, and a
 * refusal that can be silenced is worth nothing on the day it matters. Nothing
 * reads this table through those switches, so there is no switch to find.
 *
 * Additive: a new table, nothing existing is touched or backfilled. An empty table
 * is exactly what the system behaves like today.
 *
 * REPEATS COLLAPSE. One prober walking the admin URL space trips hundreds of 403s
 * in a minute; a row each would be a million rows and a useless screen. The same
 * address, refused the same way on the same path inside the collapse window, bumps
 * `hits` and `last_seen_at` on the row that is already there. See
 * App\Services\Security\SecurityEventRecorder::COLLAPSE_MINUTES.
 *
 * The two moments are nullable timestamps, for the same reason banned_ips' are: a
 * NOT NULL TIMESTAMP on MySQL or MariaDB with explicit_defaults_for_timestamp off
 * is silently given ON UPDATE CURRENT_TIMESTAMP, which would move first_seen_at
 * every time `hits` is bumped. The recorder always fills both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();

            // 'info' | 'warning' | 'critical' — see App\Models\SecurityEvent.
            $table->string('severity', 16)->index();

            // What was refused, as a stable slug the screen filters and groups on.
            $table->string('type', 64)->index();

            $table->string('description');

            $table->string('ip_address', 45)->nullable()->index();

            // Who was signed in, if anybody. actor_label is kept alongside the key
            // so the row stays readable after the account is deleted, exactly as
            // the activity log does it.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_label')->nullable();

            $table->string('method', 10)->nullable();
            $table->string('path', 512)->nullable();
            $table->string('user_agent', 512)->nullable();

            // Request data, redacted. Never a password, not even a wrong one.
            $table->json('context')->nullable();

            // How many times this same refusal has been collapsed into this row.
            $table->unsignedInteger('hits')->default(1);

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();

            $table->timestamps();

            // The collapse lookup, and the repetition ban's count per address.
            $table->index(['ip_address', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
