<?php

namespace App\Models;

use App\Support\PaymentFigures;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An extra a buyer may add to a product, such as laser engraving.
 *
 * Separate from ShopProductVariant on purpose. A variant answers "which one of this
 * product", and exactly one is bought; an add-on answers "what else goes with it",
 * and it sits alongside whichever variant was chosen.
 *
 * Mirrors EventAddon as far as the shop needs. What the event module carries for
 * registration forms — per_participant, handover, the default-ticked third state —
 * has no counterpart here, so it is left out rather than copied and ignored.
 */
class ShopProductAddon extends Model
{
    protected $fillable = [
        'shop_product_id',
        'name',
        'description',
        'price',
        'is_required',
        'max_quantity',
        'stock',
        'stock_taken',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'max_quantity' => 'integer',
            'stock' => 'integer',
            'stock_taken' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     * ------------------------------------------------------------------ */

    public function product(): BelongsTo
    {
        return $this->belongsTo(ShopProduct::class, 'shop_product_id');
    }

    /** Ordered inside the relation so every caller gets the same sequence. */
    public function variants(): HasMany
    {
        return $this->hasMany(ShopProductAddonVariant::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** Order lines that referenced this add-on. */
    public function orderLines(): HasMany
    {
        return $this->hasMany(ShopOrderItemAddon::class);
    }

    /* ---------------------------------------------------------------------
     | Shape of the add-on
     * ------------------------------------------------------------------ */

    /**
     * Whether the buyer has to pick between options, such as which side to engrave.
     *
     * An add-on with no options is bought straight off the name: a tick box when it
     * is optional, and simply charged when it is compulsory.
     */
    public function hasVariants(): bool
    {
        return $this->variants->isNotEmpty();
    }

    public function unitPrice(): float
    {
        return (float) $this->price;
    }

    /**
     * Price as shown at the head of the add-on.
     *
     * The add-on's own figure, because that is what taking it costs. Options are
     * choices rather than separate products, so a range across them would read as
     * though the engraving had three prices when it has one.
     */
    public function priceSummaryLabel(): string
    {
        return PaymentFigures::money($this->unitPrice());
    }

    /* ---------------------------------------------------------------------
     | Availability
     * ------------------------------------------------------------------ */

    /**
     * Units one order may take, or null when the only limit is stock.
     */
    public function perOrderCap(): ?int
    {
        return $this->max_quantity !== null && $this->max_quantity > 0
            ? $this->max_quantity
            : null;
    }

    public function hasStockLimit(): bool
    {
        return $this->stock !== null;
    }

    /**
     * How many are left, or null when there is no limit.
     *
     * Its own count only. With options the option's stock is what governs, which is
     * why isSoldOut() asks them instead — the same split ShopProduct::stockLeft()
     * makes between a product and its variants.
     */
    public function stockLeft(): ?int
    {
        if (! $this->hasStockLimit()) {
            return null;
        }

        return max(0, $this->stock - $this->stock_taken);
    }

    /**
     * Nothing left to sell.
     *
     * With options, only true when every one of them is gone: Front being sold out
     * does not stop Front & Back being sold.
     */
    public function isSoldOut(): bool
    {
        if ($this->hasVariants()) {
            return $this->variants->every(fn (ShopProductAddonVariant $v) => $v->isSoldOut());
        }

        return $this->hasStockLimit() && $this->stockLeft() === 0;
    }

    public function isPurchasable(): bool
    {
        return $this->is_active && ! $this->isSoldOut();
    }

    /* ---------------------------------------------------------------------
     | Scopes
     * ------------------------------------------------------------------ */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
