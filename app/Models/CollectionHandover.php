<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Who physically took the goods.
 *
 * The answer to "I never received my shirt", months later. Structured rather than a
 * free-text note, because a note proves nothing: it is optional, it is whatever
 * somebody felt like typing, and it cannot be queried.
 *
 * Polymorphic. A shop order now, one participant's shirt later; nothing here knows
 * which.
 */
class CollectionHandover extends Model
{
    /** The buyer, or whoever the order belongs to, collecting in person. */
    public const KIND_BUYER = 'buyer';

    /** Somebody collecting on their behalf. The case the SMS code exists for. */
    public const KIND_OTHER = 'other';

    /** @var array<string, string> */
    public const KINDS = [
        self::KIND_BUYER => 'The buyer',
        self::KIND_OTHER => 'Somebody else',
    ];

    protected $fillable = [
        'collectable_type',
        'collectable_id',
        'collector_kind',
        'collector_name',
        'collector_ic',
        'collector_phone',
        'collection_verification_id',
        'verified_at',
        'override_reason',
        'payment_override_reason',
        'confirmed_by',
        'confirmed_by_label',
        'collected_at',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'collected_at' => 'datetime',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     * ------------------------------------------------------------------ */

    public function collectable(): MorphTo
    {
        return $this->morphTo();
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(CollectionVerification::class, 'collection_verification_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /* ---------------------------------------------------------------------
     | Reading it back
     * ------------------------------------------------------------------ */

    public function byBuyer(): bool
    {
        return $this->collector_kind === self::KIND_BUYER;
    }

    public function byThirdParty(): bool
    {
        return $this->collector_kind === self::KIND_OTHER;
    }

    /** Whether a texted code was actually entered and accepted. */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /** Whether staff completed the handover without a working code. */
    public function wasOverridden(): bool
    {
        return filled($this->override_reason);
    }

    /**
     * Whether the goods went out while money was still owed, and why.
     *
     * Its own column rather than folded into override_reason, because they answer
     * different questions and get asked of the record by different people: one is
     * "was this person who they said they were", the other is "why did we hand over
     * something that had not been paid for". A single text box holding either would
     * make both unqueryable.
     *
     * A shop handover never sets it. Nothing reads it there.
     */
    public function wasHandedOverUnpaid(): bool
    {
        return filled($this->payment_override_reason);
    }

    /**
     * Whether somebody checked who this was, one way or the other.
     *
     * False only for a third party nobody verified — the record that needs reading
     * with suspicion. A buyer collecting their own goods was checked against the
     * identity card on the order, which is the check this counter has always run and
     * the reason an SMS code adds nothing there.
     */
    public function isAssured(): bool
    {
        return $this->byBuyer() || $this->isVerified();
    }

    /** Who took it, in words, for a history line. */
    public function collectorLabel(): string
    {
        if ($this->byBuyer()) {
            return 'The buyer in person';
        }

        return collect([
            $this->collector_name,
            filled($this->collector_ic) ? 'IC ' . $this->collector_ic : null,
            $this->collector_phone,
        ])->filter()->join(', ');
    }

    /** How the person was proved to be who they said, in words. */
    public function assuranceLabel(): string
    {
        if ($this->byBuyer()) {
            return 'Identity card checked against the order';
        }

        if ($this->isVerified()) {
            return 'SMS code verified';
        }

        return 'Handed over without SMS verification';
    }

    /** green, amber or red, for the badge beside it. */
    public function assuranceTone(): string
    {
        if ($this->isVerified()) {
            return 'green';
        }

        return $this->byBuyer() ? 'blue' : 'red';
    }
}
