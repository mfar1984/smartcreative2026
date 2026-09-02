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
     * A blank variant price means "same as the add-on", which keeps the common
     * case of one price across all sizes from having to be repeated.
     *
     * Zero is read the same way, not as free. The field is an override, and the
     * number input offers a spinner that lands on 0 at the first click, so a
     * shirt priced at RM50 was being given away by four sizes that nobody meant
     * to change. An add-on that costs money with every option at zero is a
     * typo, not an offer.
     *
     * To give something away, price the add-on itself at zero and leave the
     * options blank. That says it once instead of repeating it on every size.
     */
    public function unitPrice(): float
    {
        $override = $this->price === null ? null : (float) $this->price;

        return $override !== null && $override > 0
            ? $override
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
