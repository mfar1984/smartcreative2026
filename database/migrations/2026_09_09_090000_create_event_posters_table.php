<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let an event carry several posters instead of one.
 *
 * events.poster_path held a single image and the public card cropped it to a
 * 160px strip, so a poster with anything written on it arrived unreadable and
 * there was no way to see the whole thing. Organisers also have more than one:
 * a fixture list, a rules sheet, a schedule.
 *
 * Shaped like portfolio_images and shop_product_images, which already solve this
 * for their own parents, so the ordering and cascade behave the way the rest of
 * the application already does.
 *
 * The mime type is stored rather than derived on read, because a PDF cannot be
 * shown in an img tag and the difference decides how each row is rendered. The
 * original name is stored for the same reason the rulebook keeps one: a download
 * called "8f3a9c.pdf" tells the person who receives it nothing.
 */
return new class extends Migration
{
    private const DIRECTORY = 'event-posters';

    public function up(): void
    {
        Schema::create('event_posters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            // Stored on the public disk, like event posters always were.
            $table->string('path');

            // What the operator's file was called before it was given a hashed name.
            $table->string('original_name', 190)->nullable();

            /*
             | Whether this is an image or a PDF. Kept as the mime rather than a
             | boolean so a third kind can be accepted later without a migration
             | that has to reinterpret what "is_image = false" once meant.
             */
            $table->string('mime_type', 100)->nullable();

            $table->unsignedInteger('size_bytes')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // The list is walked in order on both the admin form and the public
            // gallery, and the first image decides the card picture.
            $table->index(['event_id', 'sort_order']);
        });

        /*
         | Carry the existing poster across before the column goes, so the two
         | events already set up do not lose their card picture. The mime is
         | inferred from the extension because it is the only thing left to infer
         | it from; a wrong guess here only affects how the row is rendered, and
         | every stored value was validated as an image when it was uploaded.
         */
        foreach (DB::table('events')->whereNotNull('poster_path')->get(['id', 'poster_path']) as $event) {
            if (blank($event->poster_path)) {
                continue;
            }

            DB::table('event_posters')->insert([
                'event_id' => $event->id,
                'path' => $event->poster_path,
                'original_name' => basename($event->poster_path),
                'mime_type' => $this->mimeFromPath($event->poster_path),
                'size_bytes' => null,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('poster_path');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('poster_path')->nullable()->after('image');
        });

        /*
         | Only the first poster can go back, because the column only ever held
         | one. Anything uploaded after this migration ran is lost on the way
         | down, which is the honest outcome rather than a silent one.
         */
        $first = DB::table('event_posters')
            ->orderBy('event_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['event_id', 'path'])
            ->unique('event_id');

        foreach ($first as $poster) {
            DB::table('events')->where('id', $poster->event_id)->update([
                'poster_path' => $poster->path,
            ]);
        }

        Schema::dropIfExists('event_posters');
    }

    private function mimeFromPath(string $path): ?string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => null,
        };
    }
};
