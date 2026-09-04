<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One choice of a product, such as a shirt size.
 *
 * Mirrors EventAddonVariant on purpose: a null price means charge the product
 * price, a null weight means the product's weight, and a null stock means
 * unlimited, so a blank box in the form keeps the meaning the operator intended.
 */
class ShopProductVariant extends Model
{
    protected $fillable = [
        'shop_product_id',
        'label',
        'sku',
        'price',
        'weight_grams',
        'stock',
        'stock_taken',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'weight_grams' => 'integer',
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

    /**
     * What one of these weighs, falling back to the product when the option
     * carries no weight of its own.
     *
     * Shaped like unitPrice() on purpose: same fallback, same reason. Most
     * products weigh the same in every option, and a courier prices mainly on
     * weight, so the two that differ are worth stating and the rest are not.
     *
     * Zero rather than null when neither knows, because a quotation cannot be
     * asked for an unknown weight and the caller has to be able to see that
     * nothing usable is here.
     */
    public function unitWeightGrams(): int
    {
        if ($this->weight_grams !== null) {
            return (int) $this->weight_grams;
        }

        return (int) ($this->product?->weight_grams ?? 0);
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
