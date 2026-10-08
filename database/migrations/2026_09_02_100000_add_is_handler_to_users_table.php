<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Additive and defaulted false, so every existing row is a non-handler
            // and nothing about current users changes. A handler is a users row
            // whose role is "handler" and whose is_handler is true; the flag lets a
            // later Handler Management screen list handlers apart from the existing
            // User Management without reading role logic into that screen.
            $table->boolean('is_handler')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_handler');
        });
    }
};
