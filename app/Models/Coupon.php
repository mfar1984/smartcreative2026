<?php

namespace App\Models;

use App\Support\LocalTime;
use App\Support\PaymentFigures;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One batch of coupons, in one of two modes.
 *
 * SHARED — the original, and still the default.
 *
 *   THE NAME IS THE CODE, and `quantity` is how many times that one code may be used:
 *
 *     quantity > 0  the shared code may be used that many times, then it is spent.
 *     quantity = 0  no limit at all.
 *
 *   Nothing is minted. One code on a poster, typed by everybody who sees it.
 *
 * UNIQUE — N codes minted up front, each tagged to a holder.
 *
 *   `quantity` is the total number of codes issued, maintained by the system as blocks
 *   are issued, so remaining() and isExhausted() read exactly as they always did. The
 *   batch name is NOT a code in this mode: only an issued code redeems.
 *
 * WHY BOTH, WHEN MINTING WAS DELIBERATELY REMOVED
 *
 * It was removed because a unique bearer code bought no per-person audit: with no
 * membership database, whoever read the code used it, and nobody could say whose code
 * was whose. The missing half was IDENTITY. The owner now supplies it by hand — each
 * code is tagged to a holder (an NGO, a company or a person, with optional email, IC
 * and phone) — so a unique code finally means something: which representative's
 * allocation ran out, and whose is untouched.
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

    /** One code on a poster, with a use cap. Today's behaviour and the default. */
    public const MODE_SHARED = 'shared';

    /** N minted codes, each optionally tagged to the holder it was issued to. */
    public const MODE_UNIQUE = 'unique';

    /**
     * Mode slug => label and what it is for, as the radio on the form reads.
     *
     * Named by what the operator is deciding — one code or many — rather than by the
     * mechanism, because the mechanism is not the question he is answering.
     */
    public const MODES = [
        self::MODE_SHARED => [
            'label' => 'One shared code',
            'help' => 'The coupon name is the code. Everybody types the same thing, up to the limit.',
        ],
        self::MODE_UNIQUE => [
            'label' => 'Individual codes',
            'help' => 'Codes are generated and handed out, each one optionally tagged to the representative holding it.',
        ],
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
        'mode',
        'name',
        'quantity',
        'expires_at',
        'discount_type',
        'discount_value',
        'committed_amount',
        'sponsor_user_id',
        'design',
        'design_path',
    ];

    /**
     * Shared unless told otherwise, in PHP as well as in the column default.
     *
     * Without this a freshly created batch reads `mode` as null until it is reloaded,
     * so isUnique() would be answered off an absent value rather than off the one the
     * database actually stored. Same answer either way here, but a mode decided by
     * whether the model has been refreshed is the sort of thing that is only wrong
     * once.
     */
    protected $attributes = [
        'mode' => self::MODE_SHARED,
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'expires_at' => 'date',
            'discount_value' => 'decimal:2',
            'committed_amount' => 'decimal:2',
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

    /**
     * The blocks of codes that have been issued, in unique mode.
     *
     * Each one is a separate issue to a separate holder. Empty for a shared batch, and
     * that is normal.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(CouponAllocation::class)->orderBy('id');
    }

    /**
     * Every minted code in the batch, across all its allocations.
     *
     * Not to be confused with codes() above. These are the STOCK — units of allocation
     * handed out to representatives. That one is the LEDGER, written at the moment of
     * use. Unique mode has both, shared mode has only the ledger.
     */
    public function issuedCodes(): HasMany
    {
        return $this->hasMany(CouponIssuedCode::class)->orderBy('id');
    }

    /** Whoever this batch's blocks were issued to. */
    public function holders(): HasMany
    {
        return $this->hasMany(CouponHolder::class)->orderBy('full_name');
    }

    /**
     * Whoever FUNDED this batch: a sponsorship account, or null.
     *
     * Set on the coupon form, under THE CODE, because that is where the operator is
     * already deciding what the batch is and who it is for. It is the BATCH-level
     * half of the rule stated in full on CouponAllocation::effectiveSponsorId():
     *
     *   this sponsorship applies to every block in the batch, unless that block
     *   names its own, which overrides it for that block only.
     *
     * It is also the ONLY way a shared-code batch can be sponsored at all, since a
     * shared batch has no blocks for a tag to sit on. See the 2026_10_16 migration.
     */
    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sponsor_user_id');
    }

    public function hasSponsor(): bool
    {
        return $this->sponsor_user_id !== null;
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
     | Which of the two models this batch follows
     * ------------------------------------------------------------------ */

    /**
     * Whether this batch mints codes.
     *
     * Compared against the unique slug rather than away from the shared one, so a
     * value that is neither — an older row, or something written by hand — reads as
     * shared and keeps today's behaviour instead of silently needing minted codes it
     * does not have.
     */
    public function isUnique(): bool
    {
        return $this->mode === self::MODE_UNIQUE;
    }

    public function isShared(): bool
    {
        return ! $this->isUnique();
    }

    public function modeLabel(): string
    {
        return self::MODES[$this->isUnique() ? self::MODE_UNIQUE : self::MODE_SHARED]['label'];
    }

    /** How many codes have been minted across every block. Zero for a shared batch. */
    public function issuedCount(): int
    {
        return $this->isUnique() ? $this->issuedCodes()->count() : 0;
    }

    /**
     * How a unique batch's stock reads: codes used of codes issued.
     *
     * Said in codes rather than in uses because that is the thing the operator handed
     * out, even though a code and a use are the same quantity.
     */
    public function unusedCodeCount(): int
    {
        return $this->isUnique() ? $this->issuedCodes()->unused()->count() : 0;
    }

    /* ---------------------------------------------------------------------
     | How many uses are left
     * ------------------------------------------------------------------ */

    /**
     * Whether the code may be used without limit.
     *
     * Only ever true of a SHARED batch. In unique mode `quantity` is the number of
     * codes in existence, so zero means there is no stock rather than no limit — and
     * reading it as unlimited would offer a batch with nothing to give.
     */
    public function isUnlimited(): bool
    {
        return ! $this->isUnique() && (int) $this->quantity <= 0;
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

    /**
     * Whether the expiry date has passed, on the office clock.
     *
     * This is the one CouponRedeemer re-reads under its lock and the one
     * CouponAvailability answers with, so it decides whether an expired code can
     * still be CLAIMED rather than merely offered. It had the same eight-hour hole
     * as scopeOffered(): expires_at is a typed date, cast to midnight UTC, and
     * ->endOfDay()->isPast() asked whether 23:59 UTC on that date had passed — which
     * it has not until 07:59 the next morning in Kuala Lumpur. A coupon that died
     * last night was still being honoured at breakfast.
     *
     * Compared as dates rather than instants because that is what the column means:
     * the batch is dead once the office is on a later day than the date that was
     * typed. Y-m-d strings compare correctly in that order.
     *
     * endOfDay() is also gone for a second reason. Carbon 3 is mutable, so it was
     * shifting the attribute instance on the model to 23:59:59 as a side effect of
     * being asked a question.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->toDateString() < LocalTime::today();
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
     * Whether a string is already a code somebody could type.
     *
     * TWO NAMESPACES, ONE BOX. A shared batch's name and a unique batch's minted codes
     * both go into the same Voucher Code field on the public form, so a name that
     * collides with an issued code would make one of the two unreachable. Both are
     * searched.
     *
     * The LEDGER's own `code` column is deliberately not searched. It is history — a
     * copy of the string as it was typed — so a batch renamed after a use would
     * otherwise block its own old name for ever.
     */
    public static function codeTaken(string $code, ?int $ignoreCouponId = null): bool
    {
        $code = Str::upper(trim($code));

        $nameTaken = self::query()
            ->where('name', $code)
            ->when($ignoreCouponId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreCouponId))
            ->exists();

        if ($nameTaken) {
            return true;
        }

        /*
         | An issued code is not ignored for the batch being edited: renaming a unique
         | batch to one of its own codes would give one string two meanings.
         */
        return CouponIssuedCode::query()->where('code', $code)->exists();
    }

    /* ---------------------------------------------------------------------
     | Scopes
     * ------------------------------------------------------------------ */

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    /**
     * Batches whose name IS the code, in SQL.
     *
     * Written the same way round as isShared(): anything that is not the unique slug
     * reads as shared, so an older row or one written by hand keeps today's
     * behaviour rather than silently needing minted codes it does not have.
     */
    public function scopeShared(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->whereNull('mode')
            ->orWhere('mode', '!=', self::MODE_UNIQUE));
    }

    /**
     * Batches worth offering on a form.
     *
     * Expired ones are left out because ticking one would promise a discount that
     * refuses itself at redeem time. An exhausted batch is deliberately still
     * offered: the owner's intent is that a used-up batch falls back to the normal
     * price while a fresh batch can be created and ticked alongside it.
     *
     * Compared against today on the OFFICE clock, which is the same date
     * isExpired() reads, so the list and the claim cannot disagree. Against
     * now()->toDateString() — the UTC date — a batch that expired yesterday stayed
     * on offer until eight the next morning, because UTC had not reached the new day
     * yet. expires_at itself is a typed date and is not shifted.
     */
    public function scopeOffered(Builder $query): Builder
    {
        return $query->whereDate('expires_at', '>=', LocalTime::today());
    }
}
