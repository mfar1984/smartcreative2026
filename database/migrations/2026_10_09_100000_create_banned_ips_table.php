<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses barred from the admin sign in after repeated failed attempts.
 *
 * Its own table because the existing throttle state lives in the cache under
 * hashed keys, which cannot be listed or cleared one address at a time. The
 * Banned IPs list on the Security tab, its Remove and Clear all buttons, and
 * `php artisan security:unban` all work on these rows.
 *
 * Additive: a new table, nothing existing is touched or backfilled.
 *
 * A row bars sign in only while expires_at is in the future. Every check reads it
 * that way, so a ban lifts on its own without anything having to delete it.
 *
 * The two moments are nullable timestamps, like Laravel's own timestamps(). A
 * NOT NULL TIMESTAMP on MySQL or MariaDB with explicit_defaults_for_timestamp off
 * is silently given ON UPDATE CURRENT_TIMESTAMP, which would move banned_at on
 * every write. The code always fills both, and a NULL expires_at never bars
 * anybody, so nullable costs nothing. Portable across sqlite and MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banned_ips', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 45)->index();
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->string('reason');
            $table->timestamp('banned_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banned_ips');
    }
};
