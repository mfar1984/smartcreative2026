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
     * Price charged for this option.
     *
     * Blank means "same as the add-on", so one price does not have to be repeated
     * across four sizes. Zero means zero: a size costing nothing is a shirt whose
     * cost already sits in the event fee, and nothing about it should be charged or
     * shown as money.
     *
     * This is the figure itself, not a difference from the add-on. A 5XL that costs
     * more carries its own full figure. Typing it out once is worth more than the
     * convenience of a surcharge, because a surcharge made zero mean "no extra" and
     * quietly charged the add-on price for a size that was meant to be free.
     */
    public function unitPrice(): float
    {
        return $this->price !== null
            ? (float) $this->price
            : (float) ($this->addon?->price ?? 0);
    }

    /** Whether this option adds nothing to the amount due. */
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
