<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Photographs belonging to a portfolio project, shown in a lightbox when a
     * visitor presses the card.
     *
     * Separate from portfolio_projects.image_path, which is the single cover shot on
     * the card. Keeping the two apart means replacing the cover does not disturb the
     * gallery, and a project can have a gallery without changing what the grid looks
     * like.
     */
    public function up(): void
    {
        Schema::create('portfolio_images', function (Blueprint $table) {
            $table->id();

            /*
             | Every image belongs to a project. There is no loose library: an image
             | with no project could never be reached from the site, so the tag is
             | part of the record rather than something to remember to set.
             */
            $table->foreignId('portfolio_project_id')->constrained()->cascadeOnDelete();

            // Stored on the public disk, like event posters and product pictures.
            $table->string('path');

            /*
             | Shown under the large image in the lightbox, and used as the alt text.
             | Nullable because a third angle of the same thing adds nothing when
             | described again.
             */
            $table->string('caption')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['portfolio_project_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_images');
    }
};
