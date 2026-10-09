<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records when a password was last set, so the password-expiry setting has a
 * timestamp to measure against.
 *
 * Additive and nullable on purpose. Every existing row keeps a NULL here, which
 * reads as "never recorded"; the expiry check treats that as not-expired, so
 * turning the column on changes nothing until a password is next set and until
 * the owner raises the expiry days above zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('password_changed_at')->nullable()->after('last_login_ip');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('password_changed_at');
        });
    }
};
