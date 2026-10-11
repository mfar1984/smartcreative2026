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
    /** One option only, which is what every add-on did before the setting existed. */
    public const SELECTION_RADIO = 'radio';

    /** As many options as the buyer wants, each priced in its own right. */
    public const SELECTION_CHECKBOX = 'checkbox';

    /**
     * Input type => label shown in the admin dropdown.
     *
     * The event module's third type, quantity, is deliberately absent: a shop add-on
     * has no quantity of its own. It rides one-per-unit on the basket line, so "how
     * many" is already answered by the line's own Quantity box, and max_quantity caps
     * that line rather than counting boxes inside the add-on.
     */
    public const SELECTION_TYPES = [
        self::SELECTION_RADIO => 'Radio button — choose one option only',
        self::SELECTION_CHECKBOX => 'Checkbox — tick as many options as needed',
    ];

    protected $fillable = [
        'shop_product_id',
        'name',
        'description',
        'price',
        'is_required',
        'selection_type',
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

    /* ---------------------------------------------------------------------
     | How the buyer answers it
     |
     | Only asked once the add-on has options. Without them the storefront draws a
     | tick box because there is nothing to choose between, so the setting is left
     | out of that branch entirely rather than given a meaning it cannot have.
     * ------------------------------------------------------------------ */

    public function isCheckboxSelection(): bool
    {
        return $this->selection_type === self::SELECTION_CHECKBOX;
    }

    /**
     * Anything that is not explicitly a checkbox, so a row written before the column
     * existed, or carrying a value this release does not know, still behaves the way
     * it was bought: one option only.
     */
    public function isRadioSelection(): bool
    {
        return ! $this->isCheckboxSelection();
    }

    public function selectionTypeLabel(): string
    {
        return self::SELECTION_TYPES[$this->selection_type]
            ?? self::SELECTION_TYPES[self::SELECTION_RADIO];
    }

    /** Whether more than one of its options may be taken at once. */
    public function allowsManyOptions(): bool
    {
        return $this->hasVariants() && $this->isCheckboxSelection();
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

    /**
     * Whether taking this adds nothing to what is owed.
     *
     * Derived rather than a setting, as EventAddon::costsNothing() is, so it cannot
     * be misconfigured into hiding a charge that is really being made. An add-on
     * whose every option resolves to zero is one whose cost already sits in the
     * product price, and "+RM 0.00" beside it is noise that reads as though
     * something were still being charged.
     */
    public function costsNothing(): bool
    {
        if ($this->hasVariants()) {
            return $this->variants->every(
                fn (ShopProductAddonVariant $option) => abs($option->unitPrice()) < 0.01
            );
        }

        return abs($this->unitPrice()) < 0.01;
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
