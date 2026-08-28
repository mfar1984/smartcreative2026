<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One photograph in a project's gallery.
 */
class PortfolioImage extends Model
{
    protected $fillable = [
        'portfolio_project_id',
        'path',
        'caption',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(PortfolioProject::class, 'portfolio_project_id');
    }

    /**
     * Null when the row points at a file that is no longer on disk, so a deleted
     * upload is skipped rather than rendering as a broken image.
     */
    public function url(): ?string
    {
        if (blank($this->path) || ! Storage::disk('public')->exists($this->path)) {
            return null;
        }

        return Storage::disk('public')->url($this->path);
    }

    /**
     * Something for a screen reader to read. Falls back to naming the project
     * rather than leaving the attribute empty, because an empty alt on a
     * meaningful image tells assistive software nothing.
     */
    public function altText(): string
    {
        if (filled($this->caption)) {
            return $this->caption;
        }

        return trim('Photograph from ' . ($this->project?->title ?? 'this project'));
    }

    /**
     * Just the stored file name, for the list view in the admin.
     */
    public function fileName(): string
    {
        return basename((string) $this->path);
    }

    /**
     * File size in KB, or null when the file has gone missing.
     */
    public function sizeKb(): ?int
    {
        if ($this->url() === null) {
            return null;
        }

        return (int) round(Storage::disk('public')->size($this->path) / 1024);
    }
}
