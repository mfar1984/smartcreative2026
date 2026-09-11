<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One poster belonging to an event.
 *
 * An event usually has several: the announcement, the fixture list, the rules
 * sheet. They are shown as a gallery on the public page, and the first image
 * among them is the picture at the top of the event card.
 *
 * A poster may be a PDF, which is why isImage() exists and why nothing here
 * assumes a file can be put in an img tag.
 */
class EventPoster extends Model
{
    /** What the upload form accepts, and what the gallery knows how to render. */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const PDF_MIME = 'application/pdf';

    protected $fillable = [
        'event_id',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    /**
     * Whether this can be rendered as a picture.
     *
     * Decided on the stored mime rather than the extension, because the mime was
     * taken from the upload itself and a file can be renamed.
     */
    public function isImage(): bool
    {
        return in_array((string) $this->mime_type, self::IMAGE_MIMES, true);
    }

    public function isPdf(): bool
    {
        return (string) $this->mime_type === self::PDF_MIME;
    }

    /**
     * What to call this poster on screen.
     *
     * Falls back to the stored filename, then to a generic label, so a row from
     * before original_name was recorded still reads as something.
     */
    public function label(): string
    {
        return filled($this->original_name)
            ? (string) $this->original_name
            : basename((string) $this->path);
    }

    /**
     * Human readable size, or null when it was never recorded.
     *
     * Shown beside a PDF because the person deciding whether to open it on mobile
     * data is entitled to know what it will cost them.
     */
    public function sizeLabel(): ?string
    {
        if (! $this->size_bytes) {
            return null;
        }

        return $this->size_bytes >= 1048576
            ? round($this->size_bytes / 1048576, 1) . ' MB'
            : max(1, (int) round($this->size_bytes / 1024)) . ' KB';
    }

    /** A short word for the kind of file, for a badge. */
    public function kindLabel(): string
    {
        return $this->isPdf() ? 'PDF' : 'Image';
    }
}
