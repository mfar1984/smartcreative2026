<?php

namespace App\Models;

use App\Support\CouponHolderIdentity;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One minted code in a unique-mode batch: a single unit of allocation.
 *
 * A code is worth exactly one USE, and a use is one participant. So a thousand codes
 * fund a thousand participants, which at RM7.50 a head is RM7,500 — the figure the
 * sponsor committed, with no blow-out. That is the whole arithmetic of unique mode.
 *
 * Every code belongs to the allocation it was issued in, and so to that allocation's
 * holder. It is never retagged on its own: a block was handed to one representative
 * together, so the holder is edited on the block. See CouponAllocation.
 *
 * `used_at` is the authoritative "spent" marker and is what an allocation's remaining
 * balance is counted from inside CouponRedeemer's locked transaction. It is set in the
 * same transaction as the ledger row it paid for, so the two cannot disagree.
 *
 * `coupon_code_id` pairs the code to that ledger row, one to one, which is what makes
 * "who used this code, and when" a single hop rather than a guess.
 *
 * Shared-mode batches have none of these rows at all. There the batch name is the code
 * and `quantity` is the cap, exactly as before.
 */
class CouponIssuedCode extends Model
{
    protected $fillable = [
        'coupon_id',
        'coupon_allocation_id',
        'code',
        'used_at',
        'coupon_code_id',
    ];

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     * ------------------------------------------------------------------ */

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(CouponAllocation::class, 'coupon_allocation_id');
    }

    /** The ledger row this code paid for, or null while it is unused. */
    public function redemption(): BelongsTo
    {
        return $this->belongsTo(CouponCode::class, 'coupon_code_id');
    }

    /* ---------------------------------------------------------------------
     | Reading
     * ------------------------------------------------------------------ */

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    /** Who handles it, read through its block. Unassigned blocks get a clear label. */
    public function holderLabel(): string
    {
        return $this->allocation?->holder?->label() ?? CouponHolderIdentity::UNASSIGNED;
    }

    public function usedAtLabel(): string
    {
        return $this->used_at === null ? '—' : LocalTime::format($this->used_at);
    }

    /**
     * The participant this code covered, by NAME ONLY.
     *
     * Deliberately not their IC, phone or anything else on the registration. The
     * redeemer is a member of the public; the holder on the allocation is the
     * representative who handed the code out. A sponsor-facing view reads this, so
     * what it can reach is what it can leak.
     */
    public function redeemerName(): ?string
    {
        return $this->redemption?->participant_name;
    }

    public function stateLabel(): string
    {
        return $this->isUsed() ? 'Used' : 'Unused';
    }

    public function stateTone(): string
    {
        return $this->isUsed() ? 'gray' : 'green';
    }

    /* ---------------------------------------------------------------------
     | Scopes
     * ------------------------------------------------------------------ */

    public function scopeUnused(Builder $query): Builder
    {
        return $query->whereNull('used_at');
    }

    public function scopeUsed(Builder $query): Builder
    {
        return $query->whereNotNull('used_at');
    }
}
