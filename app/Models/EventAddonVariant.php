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
     * Price actually charged for this variant.
     *
     * Blank means "same as the add-on", which keeps the common case of one price
     * across every size from having to be repeated. Zero means zero.
     *
     * The two are deliberately different, and an earlier version of this method
     * collapsed them by treating zero as blank. That was wrong: a shirt whose cost
     * is already inside the event fee is priced at zero on purpose, and the add-on
     * exists only to collect a size. Reading that as "charge the add-on price"
     * billed people twice for the same shirt.
     *
     * What actually caused the original confusion was not the zero, it was that
     * nothing on the form said what would be charged. The form now prints the
     * resolved figure beside every option, so blank and zero can be told apart at
     * a glance.
     */
    public function unitPrice(): float
    {
        return $this->price !== null
            ? (float) $this->price
            : (float) ($this->addon?->price ?? 0);
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
