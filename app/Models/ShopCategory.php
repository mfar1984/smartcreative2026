<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A grouping shown as a filter on the storefront.
 */
class ShopCategory extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'icon',
        'description',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     * ------------------------------------------------------------------ */

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(ShopProduct::class, 'shop_category_product');
    }

    /* ---------------------------------------------------------------------
     | Presentation
     * ------------------------------------------------------------------ */

    /**
     * The icon component case to render, falling back to a generic one so a
     * category saved without an icon still lines up with the others.
     */
    public function iconName(): string
    {
        return filled($this->icon) ? $this->icon : 'grid';
    }

    /* ---------------------------------------------------------------------
     | Scopes
     * ------------------------------------------------------------------ */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Manual order first, then alphabetical. The name breaks the tie so two
     * categories sharing a sort_order do not swap places between page loads.
     */
    public function scopeInDisplayOrder(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
