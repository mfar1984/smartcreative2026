<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in an order's history.
 *
 * Separate from the audit log because this is shown to the operator as the story of
 * the order, and it includes things nobody did: a gateway callback, or the buyer
 * confirming a parcel arrived.
 */
class ShopOrderEvent extends Model
{
    protected $fillable = [
        'shop_order_id',
        'status',
        'note',
        'user_id',
        'actor_label',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Who did it. Falls back to the stored label, then to naming the system, so a
     * deleted staff account does not leave a blank line in the history.
     */
    public function actor(): string
    {
        if (filled($this->actor_label)) {
            return $this->actor_label;
        }

        return 'System';
    }

    public function statusLabel(): ?string
    {
        if ($this->status === null) {
            return null;
        }

        return ShopOrder::STATUSES[$this->status] ?? $this->status;
    }
}
