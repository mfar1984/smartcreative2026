<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rules may now come with a PDF attachment beside the typed lines.
 *
 * Typed rules stay as they are. A full rulebook is usually a document that
 * already exists, and pasting thirty pages into a textarea is not a rule list,
 * so the document is attached instead and offered for download next to the
 * registration form.
 *
 * Two columns rather than one. The stored path carries a hashed filename so
 * uploads cannot collide or overwrite each other, which means the name the
 * organiser uploaded would otherwise be lost and the public link would read as
 * a random string. The original name is kept only for display and for the
 * download filename, never for building the path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('rules_file_path')->nullable()->after('rules');
            $table->string('rules_file_name')->nullable()->after('rules_file_path');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['rules_file_path', 'rules_file_name']);
        });
    }
};
