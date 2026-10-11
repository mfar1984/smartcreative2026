<?php

namespace App\Services\Vision;

use Illuminate\Http\UploadedFile;

/**
 * One uploaded image, in memory.
 *
 * Bytes rather than a path or an UploadedFile: a screenshot of a results table is
 * read and thrown away in the same request, and a reader that took a path would be
 * a reader that invites somebody to store one.
 *
 * The upload rules live here too, so both readers check an image the same way. They
 * are a property of what a reader can be given, not of the endpoint that happens to
 * be receiving it.
 */
class ScoreSheetImage
{
    /** What Gemini will take, and what every other image upload here allows. */
    public const MIME_TYPES = 'image/jpeg,image/png,image/webp';

    /** Kilobytes. A game screenshot off a phone is well inside this. */
    public const MAX_KILOBYTES = 4096;

    public function __construct(
        public readonly string $bytes,
        public readonly string $mime,
    ) {
    }

    /**
     * Validation rules for an images[] upload of at most $max files.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(int $max): array
    {
        return [
            'images' => ['required', 'array', 'max:' . $max],

            // mimetypes rather than mimes: it is checked against the file's own
            // bytes, the way every other image upload in this application is.
            'images.*' => ['file', 'mimetypes:' . self::MIME_TYPES, 'max:' . self::MAX_KILOBYTES],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(int $max): array
    {
        return [
            'images.required' => 'Pick at least one screenshot.',
            'images.max' => $max === 1
                ? 'One screenshot at a time is all this will read.'
                : sprintf('%d screenshots at a time is the most this will read.', $max),
            'images.*.mimetypes' => 'A screenshot must be a JPG, PNG or WebP image.',
            'images.*.max' => 'Each screenshot must be no larger than 4 MB.',
        ];
    }

    /**
     * The uploads, read into memory and never written anywhere.
     *
     * A screenshot of a scoreboard is working material, not evidence; the Proof
     * panel on the score form is where evidence is kept, deliberately and by
     * somebody's choice.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, self>
     */
    public static function fromUploads(array $files): array
    {
        return array_map(
            fn (UploadedFile $file) => new self(
                (string) file_get_contents($file->getRealPath()),
                (string) $file->getMimeType(),
            ),
            array_values($files),
        );
    }
}
