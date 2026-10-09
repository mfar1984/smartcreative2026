<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The IP addresses each account has already signed in from.
 *
 * One row per account per address, so "have we seen this before" is a single
 * indexed lookup and a sign in from a new address can be warned about.
 *
 * NOT derived from the activity log, on purpose. The Security tab now carries a
 * switch that stops activity rows being written at all, and a warning that
 * silently stopped working the moment somebody turned logging off would be a
 * trap: the one setting an attacker would reach for first is the one that would
 * disable the alarm. This table is written by LoginLocationService on every
 * successful sign in regardless of that switch, and regardless of whether the
 * warning email itself is switched on.
 *
 * No geolocation. "Somewhere new" means an address not recorded here. City names
 * would need an external lookup or a bundled database on shared cPanel hosting,
 * which is a dependency the owner does not have to carry for what it adds; if it
 * is ever wanted it can hang off these same rows.
 *
 * Additive, with one deliberate backfill. On an empty table every account's next
 * sign in would look new and the whole admin would be emailed at once, so the
 * addresses already known are seeded from users.last_login_ip. An account signing
 * in from the address it last used is therefore not warned on first deploy.
 *
 * The moments are nullable timestamps, like Laravel's own timestamps(), for the
 * reason the banned_ips migration spells out: a NOT NULL TIMESTAMP on MySQL with
 * explicit_defaults_for_timestamp off is silently given ON UPDATE
 * CURRENT_TIMESTAMP, which would move first_seen_at on every write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_known_ips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ip_address', 45);
            $table->unsignedInteger('hits')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            // The lookup every sign in makes, and the guarantee that one account
            // cannot end up with the same address twice.
            $table->unique(['user_id', 'ip_address']);
        });

        $this->seedKnownAddresses();
    }

    public function down(): void
    {
        Schema::dropIfExists('user_known_ips');
    }

    /**
     * Seed what is already known: every account's stored last sign-in address.
     *
     * last_login_at is used as both moments where it exists, because that is when
     * the address was actually seen; now() is the honest fallback for a row that
     * has an address but no timestamp. hits is 1: this is one sighting, not a
     * count we can reconstruct.
     */
    private function seedKnownAddresses(): void
    {
        $now = now();

        DB::table('users')
            ->whereNotNull('last_login_ip')
            ->where('last_login_ip', '<>', '')
            ->select('id', 'last_login_ip', 'last_login_at')
            ->orderBy('id')
            ->chunk(500, function ($users) use ($now) {
                $rows = [];

                foreach ($users as $user) {
                    $seen = $user->last_login_at ?? $now;

                    $rows[] = [
                        'user_id' => $user->id,
                        'ip_address' => substr((string) $user->last_login_ip, 0, 45),
                        'hits' => 1,
                        'first_seen_at' => $seen,
                        'last_seen_at' => $seen,
                        'user_agent' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('user_known_ips')->insert($rows);
                }
            });
    }
};
