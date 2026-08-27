<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Work delivered, shown on the public Portfolio page.
     *
     * Its own table rather than reading the events table, for three reasons. Design
     * and creative jobs are never an event row, so half the work would be invisible.
     * A portfolio entry is a piece of writing about a job, chosen and worded for
     * display, which is not what an event record holds. And an event exists from the
     * moment somebody starts planning it, whereas it only belongs in a portfolio once
     * it is finished and worth showing.
     */
    public function up(): void
    {
        Schema::create('portfolio_projects', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');

            // Null where the work was our own event rather than for a client, and
            // where a client would rather not be named.
            $table->string('client')->nullable();

            // Which of the three services this belongs to, so a service page can
            // link straight to the relevant work.
            // event-management | online-registration | digital-creative
            $table->string('service', 40)->index();

            // Free text, matching how events.category works, so the same wording can
            // be used in both places.
            $table->string('category');

            // Shown on the card. Kept short by validation rather than by hope.
            $table->text('summary');

            // The longer write up. Optional: a card with a good summary is better
            // than a thin detail page.
            $table->text('description')->nullable();

            $table->string('location')->nullable();

            /*
             | When the work was delivered. A date rather than a year, so ordering is
             | correct within a year, but only the month and year are displayed.
             */
            $table->date('delivered_on')->index();

            // One highlight per line, the same convention events.rules uses.
            $table->text('highlights')->nullable();

            // Stored on the public disk, like event posters.
            $table->string('image_path')->nullable();

            // draft | published
            $table->string('status', 20)->default('draft')->index();

            /*
             | Featured entries lead the grid. Separate from sort_order because
             | "show this first" and "where it sits among its peers" are different
             | decisions, and collapsing them means renumbering everything to promote
             | one project.
             */
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_projects');
    }
};
