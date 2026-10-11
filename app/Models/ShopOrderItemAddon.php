<?php

namespace App\Models;

use App\Support\PaymentFigures;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One extra taken on an order line.
 *
 * The name, option label and unit price are copied in at purchase time rather than
 * read back through the relation, the same rule ShopOrderItem and
 * EventRegistrationAddon follow: an invoice has to keep saying what was bought and
 * what was charged even after the add-on is renamed, repriced or deleted.
 */
class ShopOrderItemAddon extends Model
{
    protected $fillable = [
        'shop_order_item_id',
        'shop_product_addon_id',
        'shop_product_addon_variant_id',
        'name',
        'variant_label',
        'unit_price',
        'quantity',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'quantity' => 'integer',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(ShopOrderItem::class, 'shop_order_item_id');
    }

    /** Null once the catalogue entry has been deleted. */
    public function addon(): BelongsTo
    {
        return $this->belongsTo(ShopProductAddon::class, 'shop_product_addon_id');
    }

    /** Null once the option has been deleted. */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ShopProductAddonVariant::class, 'shop_product_addon_variant_id');
    }

    /**
     * How the line reads on an invoice, for example "Engraving (Front)".
     */
    public function describe(): string
    {
        return filled($this->variant_label)
            ? "{$this->name} ({$this->variant_label})"
            : (string) $this->name;
    }

    public function unitPriceLabel(): string
    {
        return PaymentFigures::money((float) $this->unit_price);
    }

    public function lineTotalLabel(): string
    {
        return PaymentFigures::money((float) $this->line_total);
    }
}
