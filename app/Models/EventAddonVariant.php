<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventAddonVariant extends Model
{
    protected $fillable = [
        'event_addon_id',
        'label',
        'price_extra',
        'stock',
        'stock_taken',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_extra' => 'decimal:2',
            'stock' => 'integer',
            'stock_taken' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(EventAddon::class, 'event_addon_id');
    }

    /**
     * What this option adds to the add-on price, never a price of its own.
     *
     * Blank and zero mean the same thing, and that is the point: an option only
     * needs a figure when it genuinely costs more, such as a 5XL taking more
     * cloth. Everything else inherits the add-on price by saying nothing.
     */
    public function priceExtra(): float
    {
        return (float) ($this->price_extra ?? 0);
    }

    /**
     * Price actually charged for this option.
     *
     * The add-on carries the price of the thing; this adds the difference. So a
     * RM50 shirt with a RM5 extra on 5XL charges RM55, and every other size
     * charges RM50 without repeating it.
     */
    public function unitPrice(): float
    {
        return round((float) ($this->addon?->price ?? 0) + $this->priceExtra(), 2);
    }

    public function hasStockLimit(): bool
    {
        return $this->stock !== null;
    }

    public function stockLeft(): ?int
    {
        return $this->hasStockLimit()
            ? max(0, $this->stock - $this->stock_taken)
            : null;
    }

    public function isSoldOut(): bool
    {
        return $this->hasStockLimit() && $this->stockLeft() <= 0;
    }
}
