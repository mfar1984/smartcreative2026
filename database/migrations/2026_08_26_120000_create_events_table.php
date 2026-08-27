<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columns mirror the shape the public registration page and the event card
     * component already rely on, so nothing about the visitor facing output
     * changes when the hardcoded array is replaced by this table.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('category');
            $table->text('description')->nullable();

            $table->date('starts_at');
            $table->date('ends_at');
            $table->string('time')->nullable();
            $table->string('venue')->nullable();

            // Null fee means free entry.
            $table->decimal('fee', 10, 2)->nullable();

            $table->unsignedInteger('seats_total')->default(0);
            $table->unsignedInteger('seats_taken')->default(0);

            // draft | open | closing_soon | full | closed | cancelled
            $table->string('status', 20)->default('draft')->index();

            $table->string('image')->nullable();
            $table->timestamps();

            $table->index('starts_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
