<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventAddon extends Model
{
    protected $fillable = [
        'event_id',
        'name',
        'description',
        'price',
        'is_required',
        'is_checked_by_default',
        'uncheck_reminder',
        'max_quantity',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_required' => 'boolean',
            'is_checked_by_default' => 'boolean',
            'is_active' => 'boolean',
            'max_quantity' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /* ---------------------------------------------------------------------
     | Offered ticked
     * ------------------------------------------------------------------ */

    /**
     * Whether the registration form should start with this add-on chosen.
     *
     * Only meaningful when the add-on can be declined. A compulsory add-on is
     * always taken, so answering true here as well would put two readings of the
     * same fact on screen, and the buyer would be offered a tick box they are not
     * allowed to clear.
     */
    public function isCheckedByDefault(): bool
    {
        return ! $this->is_required
            && $this->is_checked_by_default
            && $this->isPurchasable();
    }

    /**
     * The note shown to somebody who unticks it, or null when there is none.
     */
    public function uncheckReminder(): ?string
    {
        return $this->isCheckedByDefault() && filled($this->uncheck_reminder)
            ? (string) $this->uncheck_reminder
            : null;
    }

    /* ---------------------------------------------------------------------
     | Relations
     * ------------------------------------------------------------------ */

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(EventAddonVariant::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** Order lines that referenced this add-on. */
    public function orderLines(): HasMany
    {
        return $this->hasMany(EventRegistrationAddon::class);
    }

    /* ---------------------------------------------------------------------
     | Shape of the add-on
     * ------------------------------------------------------------------ */

    /**
     * Whether buyers must pick a variant, such as a shirt size.
     *
     * An add-on with no variants is bought straight off the name, which suits
     * something like a banquet seat that has no options to choose from.
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
     * The lowest and highest a buyer could pay per unit, accounting for
     * variants that override the add-on price.
     *
     * @return array{0: float, 1: float}
     */
    public function priceRange(): array
    {
        if (! $this->hasVariants()) {
            $price = $this->unitPrice();

            return [$price, $price];
        }

        $prices = $this->variants->map(fn (EventAddonVariant $v) => $v->unitPrice())->all();

        return [min($prices), max($prices)];
    }

    /**
     * Price as shown at the head of the add-on.
     *
     * One figure when every option costs the same, otherwise the cheapest
     * prefixed with "From". Deliberately not a full range: this sits in a
     * narrow column above the option prices, and each option prints its own
     * exact figure directly below, so the upper bound adds width without
     * adding information.
     */
    public function priceSummaryLabel(): string
    {
        [$low, $high] = $this->priceRange();

        if (abs($high - $low) < 0.01) {
            return 'RM ' . number_format($low, 2);
        }

        return 'From RM ' . number_format($low, 2);
    }

    /* ---------------------------------------------------------------------
     | Availability
     * ------------------------------------------------------------------ */

    /**
     * Units a single registration may take, or null when the only limit is
     * stock.
     */
    public function perOrderCap(): ?int
    {
        return $this->max_quantity !== null && $this->max_quantity > 0
            ? $this->max_quantity
            : null;
    }

    /**
     * Nothing left to sell.
     *
     * Only true when every variant is sold out; a shirt with M gone but L in
     * stock is still on sale. An add-on without variants has no stock figure
     * to run out of.
     */
    public function isSoldOut(): bool
    {
        if (! $this->hasVariants()) {
            return false;
        }

        return $this->variants->every(fn (EventAddonVariant $v) => $v->isSoldOut());
    }

    /**
     * Whether a buyer can be offered this add-on at all.
     */
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
