<?php

namespace App\Models;

use App\Support\LocalTime;
use App\Support\PaymentFigures;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One batch of coupons.
 *
 * The name is the batch, not a code somebody holds. What a person types depends on how
 * many uses the batch was created with, and the whole model follows from that:
 *
 *   quantity > 0  N unique codes minted up front, one each, one use each.
 *   quantity = 0  nothing minted, the batch name is the shared code, unlimited uses.
 *
 * Everything money-shaped is deliberately NOT here. What a batch takes off a charge is
 * CouponDiscount's job, and claiming a use of it is CouponRedeemer's, because that one
 * has to be race-safe and a model method invites being called outside a transaction.
 */
class Coupon extends Model
{
    public const KIND_EVENT = 'event';
    public const KIND_SHOP = 'shop';

    /** Kind slug => label, as the radio on the form reads. */
    public const KINDS = [
        self::KIND_EVENT => 'Event Registration',
        self::KIND_SHOP => 'Shop',
    ];

    public const DISCOUNT_PERCENTAGE = 'percentage';
    public const DISCOUNT_FIXED = 'fixed';

    public const DISCOUNT_TYPES = [
        self::DISCOUNT_PERCENTAGE => 'Percentage (%)',
        self::DISCOUNT_FIXED => 'Fixed amount (RM)',
    ];

    /**
     * How a coupon is drawn where the public can see it.
     *
     * A key here is the name of an anonymous Blade component under
     * resources/views/components/coupon/designs. Nothing switches on these values:
     * CouponTicket resolves the component from the key, so adding a design is one
     * line here plus one file, and no second list anywhere has to be kept in step.
     *
     * Order is the order they are offered in on the form. Custom stays last because
     * it is the one that asks for a file rather than drawing anything itself.
     */
    public const DESIGN_CUSTOM = 'custom';

    public const DESIGNS = [
        'classic' => 'Classic — plain ticket',
        'bold' => 'Bold — the discount is the hero',
        'minimal' => 'Minimal — clean and spacious',
        'stamp' => 'Stamp — rubber stamp on paper',
        'gradient' => 'Gradient — modern, phone friendly',
        self::DESIGN_CUSTOM => 'Custom — upload your own artwork',
    ];

    /** Where uploaded coupon artwork lives on the public disk. */
    public const DESIGN_DIRECTORY = 'coupon-designs';

    /** How long a generated code is, and the alphabet it is drawn from. */
    public const CODE_LENGTH = 6;

    public const CODE_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    protected $fillable = [
        'kind',
        'name',
        'quantity',
        'expires_at',
        'discount_type',
        'discount_value',
        'design',
        'design_path',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'expires_at' => 'date',
            'discount_value' => 'decimal:2',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     * ------------------------------------------------------------------ */

    public function codes(): HasMany
    {
        return $this->hasMany(CouponCode::class)->orderBy('id');
    }

    /** Only the rows that record a use, which is what Tracking reads. */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponCode::class)->whereNotNull('redeemed_at');
    }

    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(ShopProduct::class, 'coupon_shop_product');
    }

    /* ---------------------------------------------------------------------
     | What kind of thing it discounts
     * ------------------------------------------------------------------ */

    public function isForEvents(): bool
    {
        return $this->kind === self::KIND_EVENT;
    }

    public function isForShop(): bool
    {
        return $this->kind === self::KIND_SHOP;
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    /* ---------------------------------------------------------------------
     | How many uses are left
     * ------------------------------------------------------------------ */

    public function isUnlimited(): bool
    {
        return (int) $this->quantity <= 0;
    }

    /**
     * Unused minted codes, or null when the batch is unlimited.
     *
     * Null rather than a large number, so a caller has to decide what unlimited means
     * for it rather than comparing against a sentinel that could be exceeded.
     */
    public function remaining(): ?int
    {
        if ($this->isUnlimited()) {
            return null;
        }

        return $this->codes()->whereNull('redeemed_at')->count();
    }

    public function redeemedCount(): int
    {
        return $this->redemptions()->count();
    }

    /**
     * Whether every minted code has been used.
     *
     * An unlimited batch is never exhausted, which is the point of it.
     */
    public function isExhausted(): bool
    {
        return ! $this->isUnlimited() && $this->remaining() === 0;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->endOfDay()->isPast();
    }

    /**
     * Whether a redemption could succeed right now.
     *
     * Advisory only. CouponRedeemer re-checks both of these inside the transaction
     * that claims the code, because an answer worked out beforehand is worth nothing
     * against the last remaining code and two simultaneous submissions.
     */
    public function isRedeemable(): bool
    {
        return ! $this->isExpired() && ! $this->isExhausted();
    }

    /** Where the batch stands, in one word, for a badge. */
    public function stateLabel(): string
    {
        if ($this->isExpired()) {
            return 'Expired';
        }

        if ($this->isExhausted()) {
            return 'Used up';
        }

        return 'Active';
    }

    public function stateTone(): string
    {
        if ($this->isExpired()) {
            return 'red';
        }

        return $this->isExhausted() ? 'amber' : 'green';
    }

    /* ---------------------------------------------------------------------
     | Presentation
     * ------------------------------------------------------------------ */

    public function isPercentage(): bool
    {
        return $this->discount_type === self::DISCOUNT_PERCENTAGE;
    }

    public function discountLabel(): string
    {
        return $this->isPercentage()
            ? rtrim(rtrim(number_format((float) $this->discount_value, 2), '0'), '.') . '%'
            : PaymentFigures::money((float) $this->discount_value);
    }

    public function quantityLabel(): string
    {
        return $this->isUnlimited()
            ? 'Unlimited'
            : number_format((int) $this->quantity);
    }

    /**
     * Expiry as a date, without the display timezone shift.
     *
     * A wall-clock value: the operator typed a day and that day is what it means, so
     * shifting it eight hours would move a coupon's last day to the one before.
     */
    public function expiresLabel(): string
    {
        return LocalTime::dateWallClock($this->expires_at);
    }

    public function designLabel(): string
    {
        return self::DESIGNS[$this->design] ?? $this->design;
    }

    public function hasCustomDesign(): bool
    {
        return filled($this->design_path);
    }

    public function designUrl(): ?string
    {
        return $this->hasCustomDesign()
            ? Storage::disk('public')->url($this->design_path)
            : null;
    }

    /* ---------------------------------------------------------------------
     | Codes
     * ------------------------------------------------------------------ */

    /**
     * A random code nothing else is using.
     *
     * Checked against both namespaces, because a buyer types into one box: a batch
     * name and a minted code are the same kind of string as far as they are
     * concerned. Attempts are bounded so a saturated alphabet cannot spin for ever —
     * 36^6 is 2.1 billion, so reaching the ceiling means something else is wrong.
     */
    public static function generateCode(): string
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $code = '';

            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }

            if (! self::codeTaken($code)) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not generate a coupon code that is not already in use.');
    }

    /** Whether a string is already a batch name or a minted code. */
    public static function codeTaken(string $code, ?int $ignoreCouponId = null): bool
    {
        $code = Str::upper(trim($code));

        $asBatch = self::query()
            ->where('name', $code)
            ->when($ignoreCouponId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreCouponId))
            ->exists();

        if ($asBatch) {
            return true;
        }

        return CouponCode::query()
            ->where('code', $code)
            ->when($ignoreCouponId !== null, fn (Builder $query) => $query->where('coupon_id', '!=', $ignoreCouponId))
            ->exists();
    }

    /* ---------------------------------------------------------------------
     | Scopes
     * ------------------------------------------------------------------ */

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    /**
     * Batches worth offering on a form.
     *
     * Expired ones are left out because ticking one would promise a discount that
     * refuses itself at redeem time. An exhausted batch is deliberately still
     * offered: the owner's intent is that a used-up batch falls back to the normal
     * price while a fresh batch can be created and ticked alongside it.
     */
    public function scopeOffered(Builder $query): Builder
    {
        return $query->whereDate('expires_at', '>=', now()->toDateString());
    }
}
