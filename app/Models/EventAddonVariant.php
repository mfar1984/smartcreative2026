<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventAddonVariant extends Model
{
    protected $fillable = [
        'event_addon_id',
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
        return $this->belongsTo(EventAddon::class, 'event_addon_id');
    }

    /**
     * What this option adds per unit, on top of the add-on's own price.
     *
     * The add-on carries the price of the thing and is charged once. A size is only
     * a choice, so it costs nothing unless that size genuinely costs more, such as
     * a 5XL. Blank and zero therefore mean the same thing, which is what finally
     * removes the ambiguity: there is no longer any way to write a figure here that
     * disagrees with the price shown above it.
     */
    public function unitPrice(): float
    {
        return (float) ($this->price ?? 0);
    }

    /** Whether choosing this option adds nothing to the amount due. */
    public function isFree(): bool
    {
        return abs($this->unitPrice()) < 0.01;
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
