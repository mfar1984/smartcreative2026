<?php

namespace App\Models;

use App\Support\PaymentFigures;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One coupon code, and the use it was put to.
 *
 * Two shapes live in this table, and the difference is which end of its life the row
 * is written at:
 *
 *   a minted code   exists from the moment the batch is created, carries a code, and
 *                   is stamped with redeemed_at when somebody claims it.
 *   a shared use    exists only once somebody has used an unlimited batch, carries no
 *                   code of its own, and reads its label off the batch name.
 *
 * See the migration for why `code` is nullable rather than repeating the batch name.
 */
class CouponCode extends Model
{
    protected $fillable = [
        'coupon_id',
        'code',
        'redeemed_at',
        'discount_amount',
        'event_registration_id',
        'shop_order_id',
    ];

    protected function casts(): array
    {
        return [
            'redeemed_at' => 'datetime',
            'discount_amount' => 'decimal:2',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     * ------------------------------------------------------------------ */

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'event_registration_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopOrder::class, 'shop_order_id');
    }

    /* ---------------------------------------------------------------------
     | Reading
     * ------------------------------------------------------------------ */

    public function isRedeemed(): bool
    {
        return $this->redeemed_at !== null;
    }

    /**
     * What somebody typed, or would type, to use this.
     *
     * Falls back to the batch name, which is the shared code for an unlimited batch
     * and the only sensible label for a row that has none of its own.
     */
    public function codeLabel(): string
    {
        return $this->code ?? ($this->coupon?->name ?? '—');
    }

    public function discountLabel(): string
    {
        return PaymentFigures::money((float) $this->discount_amount);
    }

    /**
     * Our own reference for whatever this discounted, or null when nothing yet.
     */
    public function usedOnReference(): ?string
    {
        return $this->registration?->reference ?? $this->order?->reference;
    }

    /**
     * What the discount was applied to, in words: the event or the order.
     */
    public function usedOnLabel(): string
    {
        if ($this->registration !== null) {
            return $this->registration->event?->title ?? 'Event registration';
        }

        if ($this->order !== null) {
            return 'Shop order';
        }

        return '—';
    }

    /* ---------------------------------------------------------------------
     | Minting
     * ------------------------------------------------------------------ */

    /**
     * Mint the batch's unique codes, one per use it was created with.
     *
     * Nothing is minted for an unlimited batch: there is no number of codes to make,
     * and the batch name is what people type. Codes are generated one at a time
     * through Coupon::generateCode(), which checks both namespaces, and inserted in
     * chunks so a batch of five hundred is a handful of writes rather than five
     * hundred.
     *
     * @param  int|null  $count  how many to mint, when it is not the whole batch.
     *         Used when a batch's quantity is raised: only the shortfall is minted,
     *         so codes already printed and handed out keep working.
     * @return int how many were minted
     */
    public static function mintFor(Coupon $coupon, ?int $count = null): int
    {
        if ($coupon->isUnlimited() && $count === null) {
            return 0;
        }

        $wanted = max(0, $count ?? (int) $coupon->quantity);
        $rows = [];
        $seen = [];
        $now = now();

        for ($i = 0; $i < $wanted; $i++) {
            do {
                $code = Coupon::generateCode();
            } while (isset($seen[$code]));

            $seen[$code] = true;

            $rows[] = [
                'coupon_id' => $coupon->id,
                'code' => $code,
                'discount_amount' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            // Chunked because a large batch would otherwise build one statement with
            // thousands of bound parameters, which SQLite refuses outright.
            foreach (array_chunk($rows, 200) as $chunk) {
                static::query()->insert($chunk);
            }
        }

        return count($rows);
    }

    /* ---------------------------------------------------------------------
     | Scopes
     * ------------------------------------------------------------------ */

    public function scopeRedeemed(Builder $query): Builder
    {
        return $query->whereNotNull('redeemed_at');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->whereNull('redeemed_at');
    }
}
