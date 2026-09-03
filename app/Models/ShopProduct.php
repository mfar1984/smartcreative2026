<?php

namespace App\Models;

use App\Support\PaymentFigures;
use App\Support\PaymentSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something the shop sells: a medal, a shirt, a lanyard.
 */
class ShopProduct extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    /**
     * Status slug => label shown in the admin.
     *
     * Archived is kept apart from draft because they answer different questions:
     * a draft has never been sold, an archived product has and its order history
     * still has to make sense.
     */
    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_ARCHIVED => 'Archived',
    ];

    protected $fillable = [
        'slug',
        'name',
        'sku',
        'barcode',
        'short_description',
        'description',
        'price',
        'compare_at_price',
        'cost_price',
        'track_inventory',
        'stock_quantity',
        'stock_taken',
        'low_stock_threshold',
        'weight_grams',
        'length_mm',
        'width_mm',
        'height_mm',
        'option_name',
        'highlights',
        'included_items',
        'specifications',
        'vendor',
        'brand',
        'status',
        'payment_methods',
        'is_featured',
        'sort_order',
        'seo_title',
        'seo_description',
        'seo_keywords',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'track_inventory' => 'boolean',
            'stock_quantity' => 'integer',
            'stock_taken' => 'integer',
            'low_stock_threshold' => 'integer',
            'weight_grams' => 'integer',
            'length_mm' => 'integer',
            'width_mm' => 'integer',
            'height_mm' => 'integer',
            'payment_methods' => 'array',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     * ------------------------------------------------------------------ */

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ShopCategory::class, 'shop_category_product');
    }

    /** Ordered inside the relation so every caller gets the same sequence. */
    public function images(): HasMany
    {
        return $this->hasMany(ShopProductImage::class)
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ShopProductVariant::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /* ---------------------------------------------------------------------
     | Images
     * ------------------------------------------------------------------ */

    /**
     * The picture for the listing card: the one marked featured, otherwise the
     * first that still has a file behind it.
     */
    public function featuredImage(): ?ShopProductImage
    {
        return $this->images->first(fn (ShopProductImage $image) => $image->url() !== null);
    }

    public function featuredImageUrl(): ?string
    {
        return $this->featuredImage()?->url();
    }

    /* ---------------------------------------------------------------------
     | Pricing
     * ------------------------------------------------------------------ */

    public function hasVariants(): bool
    {
        return $this->variants->isNotEmpty();
    }

    /**
     * Lowest and highest price a buyer could pay.
     *
     * @return array{0: float, 1: float}
     */
    public function priceRange(): array
    {
        if (! $this->hasVariants()) {
            $price = (float) $this->price;

            return [$price, $price];
        }

        $prices = $this->variants->map(fn (ShopProductVariant $variant) => $variant->unitPrice());

        return [(float) $prices->min(), (float) $prices->max()];
    }

    /**
     * One line for a card. Says "From RM 25.00" only when the options actually
     * differ, so a shirt priced the same in every size does not look uncertain.
     */
    public function priceSummaryLabel(): string
    {
        [$low, $high] = $this->priceRange();

        if ($low === $high) {
            return PaymentFigures::money($low);
        }

        return 'From ' . PaymentFigures::money($low);
    }

    /**
     * Whether there is a genuine saving to advertise. A compare-at price at or
     * below the real price is not an offer, it is a mistake, and showing it would
     * claim a discount that does not exist.
     */
    public function isOnOffer(): bool
    {
        return $this->compare_at_price !== null
            && (float) $this->compare_at_price > (float) $this->price;
    }

    /**
     * Rounded percentage off, or null when there is no offer.
     */
    public function discountPercent(): ?int
    {
        if (! $this->isOnOffer()) {
            return null;
        }

        $was = (float) $this->compare_at_price;
        $now = (float) $this->price;

        return (int) round((($was - $now) / $was) * 100);
    }

    /* ---------------------------------------------------------------------
     | Stock
     * ------------------------------------------------------------------ */

    /**
     * How many are available, or null when the answer is "as many as you like".
     *
     * With variants the product level count is ignored, because "3 left" is not a
     * useful answer when all three are size S. A single unlimited variant makes
     * the whole product unlimited.
     */
    public function stockLeft(): ?int
    {
        if (! $this->track_inventory) {
            return null;
        }

        if ($this->hasVariants()) {
            if ($this->variants->contains(fn (ShopProductVariant $v) => ! $v->hasStockLimit())) {
                return null;
            }

            return (int) $this->variants->sum(fn (ShopProductVariant $v) => $v->stockLeft());
        }

        return max(0, $this->stock_quantity - $this->stock_taken);
    }

    /**
     * With variants, sold out only when every option is. One size left is still
     * something to sell.
     */
    public function isSoldOut(): bool
    {
        if (! $this->track_inventory) {
            return false;
        }

        if ($this->hasVariants()) {
            return $this->variants->every(fn (ShopProductVariant $v) => $v->isSoldOut());
        }

        return $this->stockLeft() === 0;
    }

    /**
     * Running low but not gone. False when unlimited or already sold out, so the
     * warning and the sold out badge never appear together.
     */
    public function isLowStock(): bool
    {
        $left = $this->stockLeft();

        return $left !== null && $left > 0 && $left <= $this->low_stock_threshold;
    }

    /* ---------------------------------------------------------------------
     | How it may be paid for
     |
     | Two lists decide this, and both have to agree. The switches on Settings >
     | Integration > Payments say what the shop can take at all; the list here
     | narrows that for one product. The narrower answer wins, because a method
     | the shop is not configured for cannot collect the money whatever a product
     | claims, and a method the seller refused for this item must not be offered
     | just because the shop supports it.
     * ------------------------------------------------------------------ */

    /**
     * The methods this product accepts, before the shop's own switches are applied.
     *
     * Unknown slugs are dropped so a value left behind by an older release, or
     * written straight into the database, cannot reach a radio button.
     *
     * A blank column falls back to every method. Not a feature and not reachable
     * through the form, which insists on at least one: it is the safe direction
     * for a row written by hand, since the alternative is a product silently
     * becoming impossible to buy.
     *
     * @return array<int, string>
     */
    public function allowedPaymentMethods(): array
    {
        $stored = array_values(array_intersect(
            (array) ($this->payment_methods ?? []),
            array_keys(ShopOrder::METHODS),
        ));

        return $stored === [] ? array_keys(ShopOrder::METHODS) : $stored;
    }

    public function allowsPaymentMethod(string $method): bool
    {
        return in_array($method, $this->allowedPaymentMethods(), true);
    }

    /**
     * What a buyer could actually choose for this product on its own, slug => label.
     *
     * Empty means nobody can buy it right now: either the shop takes nothing, or
     * everything it does take is refused by this product.
     *
     * @return array<string, string>
     */
    public function payablePaymentMethods(): array
    {
        return array_intersect_key(
            PaymentSettings::enabledMethods(),
            array_flip($this->allowedPaymentMethods()),
        );
    }

    /**
     * Labels for the methods this product accepts, for reading on screen.
     *
     * @return array<int, string>
     */
    public function paymentMethodLabels(): array
    {
        return array_map(
            fn (string $method) => ShopOrder::METHODS[$method],
            $this->allowedPaymentMethods(),
        );
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Live, and there is something to sell. */
    public function isPurchasable(): bool
    {
        return $this->isActive() && ! $this->isSoldOut();
    }

    /* ---------------------------------------------------------------------
     | Shipping, in the units people think in
     * ------------------------------------------------------------------ */

    public function weightKg(): ?float
    {
        return $this->weight_grams === null ? null : round($this->weight_grams / 1000, 3);
    }

    /**
     * "30 x 20 x 10 cm", or null unless all three are known. Two out of three
     * dimensions describes nothing.
     */
    public function dimensionsLabel(): ?string
    {
        if ($this->length_mm === null || $this->width_mm === null || $this->height_mm === null) {
            return null;
        }

        $cm = fn (int $mm) => rtrim(rtrim(number_format($mm / 10, 1, '.', ''), '0'), '.');

        return sprintf('%s x %s x %s cm', $cm($this->length_mm), $cm($this->width_mm), $cm($this->height_mm));
    }

    /* ---------------------------------------------------------------------
     | Copy stored one item per line
     * ------------------------------------------------------------------ */

    /**
     * @return array<int, string>
     */
    public function highlightLines(): array
    {
        return self::lines($this->highlights);
    }

    /**
     * @return array<int, string>
     */
    public function includedLines(): array
    {
        return self::lines($this->included_items);
    }

    /**
     * Specifications as label and value pairs.
     *
     * A line without a colon becomes a label with no value rather than being
     * dropped, so a typo shows up on screen instead of silently disappearing.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function specificationRows(): array
    {
        return array_map(function (string $line) {
            $parts = explode(':', $line, 2);

            return [
                'label' => trim($parts[0]),
                'value' => isset($parts[1]) ? trim($parts[1]) : '',
            ];
        }, self::lines($this->specifications));
    }

    /**
     * @return array<int, string>
     */
    private static function lines(?string $text): array
    {
        if (blank($text)) {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n/', $text))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->values()
            ->all();
    }

    /* ---------------------------------------------------------------------
     | Scopes
     * ------------------------------------------------------------------ */

    /** What a visitor is allowed to see. Drafts and archived products are not. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Featured first, then the manual order, then newest. The id breaks the final
     * tie so pagination cannot repeat or skip a row.
     */
    public function scopeInDisplayOrder(Builder $query): Builder
    {
        return $query
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('id');
    }
}
