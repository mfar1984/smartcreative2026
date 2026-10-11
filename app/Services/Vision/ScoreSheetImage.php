<?php

namespace App\Services\Vision;

/**
 * One uploaded image, in memory.
 *
 * Bytes rather than a path or an UploadedFile: a screenshot of a results table is
 * read and thrown away in the same request, and a reader that took a path would be
 * a reader that invites somebody to store one.
 */
class ScoreSheetImage
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mime,
    ) {
    }
}
