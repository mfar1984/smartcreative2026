<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One choice within a product add-on, such as which side gets engraved.
 *
 * The null price rule here is the shop's: blank means "charge the add-on's price",
 * exactly as a blank ShopProductVariant price means "charge the product's". It is
 * deliberately NOT EventAddonVariant's rule, where a blank price means the option
 * adds nothing on top of the add-on. Both conventions are defensible; having the two
 * sitting side by side in one codebase is what confuses the next reader, so this one
 * follows the shop it belongs to and says so here rather than leaving it to be
 * discovered from a total that came out wrong.
 */
class ShopProductAddonVariant extends Model
{
    protected $fillable = [
        'shop_product_addon_id',
        'label',
        'price',
        'stock',
        'stock_taken',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'stock' => 'integer',
            'stock_taken' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(ShopProductAddon::class, 'shop_product_addon_id');
    }

    /**
     * What this option costs, falling back to the add-on's price when it carries
     * none of its own.
     */
    public function unitPrice(): float
    {
        if ($this->price !== null) {
            return (float) $this->price;
        }

        return (float) ($this->addon?->price ?? 0);
    }

    public function hasStockLimit(): bool
    {
        return $this->stock !== null;
    }

    /** How many are left, or null when the option is unlimited. */
    public function stockLeft(): ?int
    {
        if (! $this->hasStockLimit()) {
            return null;
        }

        return max(0, $this->stock - $this->stock_taken);
    }

    public function isSoldOut(): bool
    {
        return $this->hasStockLimit() && $this->stockLeft() === 0;
    }
}
