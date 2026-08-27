<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The Roles Management matrix puts modules on the rows and actions on
        // the columns, so those two facts need to be stored rather than parsed
        // out of the slug at render time.
        Schema::table('permissions', function (Blueprint $table) {
            $table->string('module')->default('')->after('group');
            $table->string('action')->default('view')->after('module');

            $table->index(['group', 'module']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('is_protected');
        });

        // Activity log gains a severity and a category so the log screen can
        // offer the same filter chips as the rest of the admin.
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('level', 20)->default('info')->after('action')->index();
            $table->string('category')->default('General')->after('level')->index();
        });

        // Stored rather than joined, because the actor's role at the time of
        // the change is what an audit trail needs to show.
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('actor_role')->nullable()->after('actor_label');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('actor_role');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['level']);
            $table->dropIndex(['category']);
            $table->dropColumn(['level', 'category']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->dropIndex(['group', 'module']);
            $table->dropColumn(['module', 'action']);
        });
    }
};
