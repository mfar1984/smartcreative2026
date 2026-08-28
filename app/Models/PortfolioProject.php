<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A piece of work shown on the public Portfolio page.
 */
class PortfolioProject extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    /**
     * Status slug => label shown in the admin.
     */
    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_PUBLISHED => 'Published',
    ];

    public const SERVICE_EVENTS = 'event-management';
    public const SERVICE_REGISTRATION = 'online-registration';
    public const SERVICE_CREATIVE = 'digital-creative';

    /**
     * Service slug => label. The slugs match the three service route names so a
     * service page can link to its own work without a translation table.
     */
    public const SERVICES = [
        self::SERVICE_EVENTS => 'Event Management',
        self::SERVICE_REGISTRATION => 'Online Registration',
        self::SERVICE_CREATIVE => 'Digital Creative',
    ];

    protected $fillable = [
        'slug',
        'title',
        'client',
        'service',
        'category',
        'summary',
        'description',
        'location',
        'delivered_on',
        'highlights',
        'image_path',
        'status',
        'is_featured',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'delivered_on' => 'date',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     * ------------------------------------------------------------------ */

    /**
     * The gallery shown in the lightbox. Ordered inside the relation so every
     * caller gets the same sequence.
     */
    public function images(): HasMany
    {
        return $this->hasMany(PortfolioImage::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /* ---------------------------------------------------------------------
     | Gallery
     * ------------------------------------------------------------------ */

    /**
     * Only the images whose file is still on disk.
     *
     * A row pointing at a deleted file would otherwise put a broken frame in the
     * middle of the lightbox, and the count on the card would promise more than
     * the popup delivers.
     *
     * @return \Illuminate\Support\Collection<int, PortfolioImage>
     */
    public function galleryImages()
    {
        return $this->images->filter(fn (PortfolioImage $image) => $image->url() !== null)->values();
    }

    /**
     * Whether pressing the card should open anything. A popup with nothing in it
     * is worse than a card that does not react.
     */
    public function hasGallery(): bool
    {
        return $this->galleryImages()->isNotEmpty();
    }

    /* ---------------------------------------------------------------------
     | Presentation
     * ------------------------------------------------------------------ */

    /**
     * Null when there is no image, or when the row points at a file that is no
     * longer on disk. Returning a URL for a missing file would render a broken
     * image, which looks worse than the designed fallback.
     */
    public function imageUrl(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        if (! Storage::disk('public')->exists($this->image_path)) {
            return null;
        }

        return Storage::disk('public')->url($this->image_path);
    }

    public function serviceLabel(): string
    {
        return self::SERVICES[$this->service] ?? $this->service;
    }

    /**
     * Month and year. The day is stored so that ordering within a month is
     * correct, but showing it would imply a precision the record does not have.
     */
    public function deliveredLabel(): string
    {
        return $this->delivered_on->format('M Y');
    }

    /**
     * Who the work was for. Falls back to our own name rather than leaving a gap,
     * because a card that names nobody reads as unfinished.
     */
    public function clientLabel(): string
    {
        return filled($this->client) ? $this->client : 'Smart Digital Creative';
    }

    /**
     * Highlights as a list, blank lines dropped.
     *
     * @return array<int, string>
     */
    public function highlightLines(): array
    {
        if (blank($this->highlights)) {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n/', $this->highlights))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->values()
            ->all();
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /* ---------------------------------------------------------------------
     | Scopes
     * ------------------------------------------------------------------ */

    /** What a visitor is allowed to see. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    /**
     * Display order: featured first, then the manual sort, then most recently
     * delivered. The id breaks the final tie so pagination cannot repeat or skip
     * a row when several share a delivery date.
     */
    public function scopeInDisplayOrder(Builder $query): Builder
    {
        return $query
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('delivered_on')
            ->orderByDesc('id');
    }
}
