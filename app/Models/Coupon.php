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
 * THE NAME IS THE CODE, and `quantity` is how many times that one code may be used:
 *
 *   quantity > 0  the shared code may be used that many times, then it is spent.
 *   quantity = 0  no limit at all.
 *
 * Nothing is minted up front, and that is a deliberate reversal. Handing out N unique
 * codes only buys a per-person audit trail if there is a membership database to issue
 * them against, and this system has none: a unique bearer code is used by whoever
 * reads it, so the uniqueness bought nothing while being far harder to distribute
 * than one shared code. If membership ever arrives, per-person codes can be added
 * then — coupon_codes already records which registration or order each use belongs
 * to, and those carry the person's details.
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

    /**
     * The groups the picker tabs between, slug => label, in the order it offers them.
     *
     * NAMED BY APPEARANCE, NOT BY THEME. An operator looking for a design is looking
     * for the shape he wants, so "Stamp" and "Bold" are the questions he can answer.
     * "Festive" or "Corporate" would be names we invented and he would have to guess
     * at.
     *
     * The picker renders ONE group at a time, which is the whole point of grouping:
     * at two hundred designs, rendering them all and hiding the rest behind a tab
     * makes the form heavy whether anybody looks at them or not.
     */
    public const DESIGN_GROUPS = [
        'ticket' => 'Ticket',
        'bold' => 'Bold',
        'minimal' => 'Minimal',
        'stamp' => 'Stamp',
        'modern' => 'Modern',
    ];

    /**
     * Design key => its label and the group it is offered under.
     *
     * Adding a design is this one entry plus its component file. Nothing else: the
     * form reads the groups from here, the picker reads the designs of a group from
     * here, and the validation rule reads the keys from here.
     *
     * Custom carries a null group on purpose. It is "bring your own artwork" rather
     * than a design, so it is not something to find among shapes — it sits on its own
     * beside the groups, with the upload field.
     */
    public const DESIGNS = [
        'classic' => ['label' => 'Classic — plain ticket', 'group' => 'ticket'],
        'bold' => ['label' => 'Bold — the discount is the hero', 'group' => 'bold'],
        'minimal' => ['label' => 'Minimal — clean and spacious', 'group' => 'minimal'],
        'stamp' => ['label' => 'Stamp — rubber stamp on paper', 'group' => 'stamp'],
        'gradient' => ['label' => 'Gradient — modern, phone friendly', 'group' => 'modern'],
        self::DESIGN_CUSTOM => ['label' => 'Custom — upload your own artwork', 'group' => null],
    ];

    /** Where uploaded coupon artwork lives on the public disk. */
    public const DESIGN_DIRECTORY = 'coupon-designs';

    /** How long a generated code is. */
    public const CODE_LENGTH = 6;

    /**
     * Characters the generator will never emit, because they are misread off a screen.
     *
     * Not a theory. The owner read NG68BJ off his own screen and typed NG6883: the B
     * became an 8 and the J became a 3. The batch name IS the code people type off a
     * poster or a phone, so a legible alphabet is the difference between a coupon that
     * works and a visitor told their code does not exist.
     *
     * Each confusable pair, and which half of it is dropped:
     *
     *   0 / O   both dropped — neither is safe next to the other
     *   1 / I   both dropped
     *   1 / L   L dropped with it, for the same reason
     *   8 / B   both dropped — the pair that caused this
     *   5 / S   both dropped
     *   2 / Z   both dropped
     *   J / 3   J dropped, 3 kept — 3 is the commoner character and reads cleanly
     *
     * This narrows ONLY what Generate produces. A name typed by hand may still use any
     * capital letter or digit: a human-chosen SUKAN50 is clearer than anything random,
     * and existing names such as NG68BJ must keep redeeming.
     */
    public const CODE_EXCLUDED = '0O1IL8B5S2ZJ';

    /**
     * The alphabet a generated code is drawn from: A-Z0-9 less CODE_EXCLUDED.
     *
     * 24 characters over 6 places is 191,102,976 combinations, which is ample for a
     * giveaway and leaves nothing guessable.
     */
    public const CODE_ALPHABET = 'ACDEFGHKMNPQRTUVWXY34679';

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

    /**
     * The redemption ledger: one row per use, written at the moment of use.
     *
     * Every row here is a use that happened. Nothing is pre-created, so this and
     * redemptions() answer the same question — the second one is kept because
     * Tracking and Report both read the stamped column explicitly and a row with no
     * redeemed_at would be a bug worth seeing rather than silently counting.
     */
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
     * Uses still allowed, or null when the batch is unlimited.
     *
     * Null rather than a large number, so a caller has to decide what unlimited means
     * for it rather than comparing against a sentinel that could be exceeded.
     *
     * Floored at zero: a cap lowered after the fact must read as nothing left rather
     * than as a negative, and CouponRedeemer refuses on the same comparison.
     */
    public function remaining(): ?int
    {
        if ($this->isUnlimited()) {
            return null;
        }

        return max(0, (int) $this->quantity - $this->redeemedCount());
    }

    public function redeemedCount(): int
    {
        return $this->redemptions()->count();
    }

    /**
     * Whether the code has been used as many times as it was allowed.
     *
     * An unlimited batch is never exhausted, which is the point of it. Compared with
     * >= rather than == so a cap lowered below what has already gone out still reads
     * as spent instead of looping back round to usable.
     */
    public function isExhausted(): bool
    {
        return ! $this->isUnlimited() && $this->redeemedCount() >= (int) $this->quantity;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->endOfDay()->isPast();
    }

    /**
     * Whether a redemption could succeed right now.
     *
     * Advisory only. CouponRedeemer re-checks both of these inside the transaction
     * that claims the use, because an answer worked out beforehand is worth nothing
     * against the last remaining use and two simultaneous submissions.
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
            ? rtrim(rtrim(number_format((float) $this->discount_value, 2), '0'), '.').'%'
            : PaymentFigures::money((float) $this->discount_value);
    }

    /** How many uses it allows, in words. */
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
        return self::designLabelFor((string) $this->design);
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
     | Designs and their groups
     |
     | Read from DESIGNS rather than matched against a second list, so a design
     | added there is offered, searchable and validated without another edit.
     * ------------------------------------------------------------------ */

    /** A stored key that no longer exists reads as itself rather than as nothing. */
    public static function designLabelFor(string $design): string
    {
        return self::DESIGNS[$design]['label'] ?? $design;
    }

    /** The group a design is offered under, or null for custom and for strays. */
    public static function designGroup(string $design): ?string
    {
        return self::DESIGNS[$design]['group'] ?? null;
    }

    public static function designGroupLabel(?string $group): ?string
    {
        return $group === null ? null : (self::DESIGN_GROUPS[$group] ?? $group);
    }

    /** The tab the picker opens on when the chosen design belongs to no group. */
    public static function defaultDesignGroup(): string
    {
        return (string) array_key_first(self::DESIGN_GROUPS);
    }

    /** Which tab to open on for a given choice. Custom falls back to the first. */
    public static function designGroupFor(string $design): string
    {
        return self::designGroup($design) ?? self::defaultDesignGroup();
    }

    /**
     * The designs of one group, key => label.
     *
     * @return array<string, string>
     */
    public static function designsInGroup(string $group): array
    {
        $designs = [];

        foreach (self::DESIGNS as $key => $design) {
            if ($design['group'] === $group) {
                $designs[$key] = $design['label'];
            }
        }

        return $designs;
    }

    /**
     * Everything the picker offers among the groups, key => label. Custom excluded.
     *
     * @return array<string, string>
     */
    public static function groupedDesigns(): array
    {
        $designs = [];

        foreach (self::DESIGNS as $key => $design) {
            if ($design['group'] !== null) {
                $designs[$key] = $design['label'];
            }
        }

        return $designs;
    }

    /* ---------------------------------------------------------------------
     | Codes
     * ------------------------------------------------------------------ */

    /**
     * A random code nothing else is using, drawn from the legible alphabet.
     *
     * Attempts are bounded so a saturated alphabet cannot spin for ever — 24^6 is 191
     * million, so reaching the ceiling means something else is wrong.
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

    /**
     * Whether a string is already some other batch's name, which is its code.
     *
     * One namespace now, because there is only one kind of code. The ledger's own
     * `code` column is history rather than a namespace — it holds a copy of the name
     * as it was typed — so it is deliberately not searched: a batch renamed after a
     * use would otherwise block its old name for ever.
     */
    public static function codeTaken(string $code, ?int $ignoreCouponId = null): bool
    {
        $code = Str::upper(trim($code));

        return self::query()
            ->where('name', $code)
            ->when($ignoreCouponId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreCouponId))
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
