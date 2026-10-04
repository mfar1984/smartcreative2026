<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One checkout opened at the gateway for a shop order.
 *
 * An attempt, not a payment. Most of these come to nothing: a buyer who opened the
 * page and closed it, or one who let a QR code time out. What they are for is
 * answering "which purchases at the gateway belong to this order", which is the
 * question a second attempt overwriting the first would otherwise destroy.
 */
class ShopOrderCheckout extends Model
{
    protected $fillable = [
        'shop_order_id',
        'purchase_id',
        'checkout_url',
        'gateway',
        'opened_at',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopOrder::class, 'shop_order_id');
    }
}
