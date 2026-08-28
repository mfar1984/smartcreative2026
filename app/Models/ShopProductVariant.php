<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One choice of a product, such as a shirt size.
 *
 * Mirrors EventAddonVariant on purpose: a null price means charge the product
 * price and a null stock means unlimited, so a blank box in the form keeps the
 * meaning the operator intended.
 */
class ShopProductVariant extends Model
{
    protected $fillable = [
        'shop_product_id',
        'label',
        'sku',
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(ShopProduct::class, 'shop_product_id');
    }

    /**
     * What this option costs, falling back to the product price when it carries
     * no price of its own.
     */
    public function unitPrice(): float
    {
        if ($this->price !== null) {
            return (float) $this->price;
        }

        return (float) ($this->product?->price ?? 0);
    }

    public function hasStockLimit(): bool
    {
        return $this->stock !== null;
    }

    /**
     * How many are left, or null when the option is unlimited.
     */
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
