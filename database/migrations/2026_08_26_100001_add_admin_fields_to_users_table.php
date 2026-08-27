<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable so the column can be added to a table that already has
            // rows. Uniqueness is still enforced for the values that are set.
            $table->string('username')->nullable()->unique()->after('name');
            $table->foreignId('role_id')->nullable()->after('username')->constrained('roles')->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('role_id');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropUnique(['username']);
            $table->dropColumn([
                'username',
                'role_id',
                'is_active',
                'last_login_at',
                'last_login_ip',
            ]);
        });
    }
};
