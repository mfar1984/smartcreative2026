<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ShopProductImage extends Model
{
    protected $fillable = [
        'shop_product_id',
        'path',
        'alt_text',
        'is_featured',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ShopProduct::class, 'shop_product_id');
    }

    /**
     * Null when the row points at a file that is no longer on disk, so a deleted
     * upload falls back to the placeholder instead of rendering as broken.
     */
    public function url(): ?string
    {
        if (blank($this->path) || ! Storage::disk('public')->exists($this->path)) {
            return null;
        }

        return Storage::disk('public')->url($this->path);
    }

    /**
     * Something for a screen reader to read. Falls back to the product name
     * rather than leaving the attribute empty.
     */
    public function altText(): string
    {
        return filled($this->alt_text) ? $this->alt_text : (string) ($this->product?->name ?? '');
    }
}
