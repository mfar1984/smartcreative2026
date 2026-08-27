<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rules shown to whoever is registering.
     *
     * Plain text, not markup. It is rendered on the public site, so accepting
     * HTML here would hand an administrator a way to inject script into a page
     * visitors fill their personal details into. Line breaks are preserved on
     * display, which covers the formatting a rule list actually needs.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->text('rules')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('rules');
        });
    }
};
