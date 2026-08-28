<?php

namespace App\Models;

use App\Support\PaymentFigures;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on an order.
 *
 * Everything shown to a buyer or an accountant comes from this row rather than from
 * the product, because the product may since have been renamed, repriced or deleted.
 * The relations exist only so the admin can link back to a catalogue entry that is
 * still there.
 */
class ShopOrderItem extends Model
{
    protected $fillable = [
        'shop_order_id',
        'shop_product_id',
        'shop_product_variant_id',
        'name',
        'variant_label',
        'sku',
        'unit_price',
        'quantity',
        'line_total',
        'weight_grams',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'quantity' => 'integer',
            'weight_grams' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopOrder::class);
    }

    /** Null once the catalogue entry has been deleted. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(ShopProduct::class, 'shop_product_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ShopProductVariant::class, 'shop_product_variant_id');
    }

    /**
     * What was bought, including the option where there was one.
     */
    public function label(): string
    {
        return filled($this->variant_label)
            ? $this->name . ' — ' . $this->variant_label
            : $this->name;
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
