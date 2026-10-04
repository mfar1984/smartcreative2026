<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One one-time code issued for a collection.
 *
 * Holds the hash and never the digits. Nothing on this model can return the code,
 * deliberately: the only object that ever sees it is the gateway call that texts it,
 * and that happens inside CollectionVerifier::issue() and nowhere else.
 */
class CollectionVerification extends Model
{
    protected $fillable = [
        'verifiable_type',
        'verifiable_id',
        'phone',
        'code_hash',
        'attempts',
        'max_attempts',
        'expires_at',
        'sent_at',
        'verified_at',
        'burned_at',
        'issued_by',
        'verified_by',
        'issued_ip',
        'gateway_message_id',
        'gateway_status',
    ];

    /**
     * Kept out of arrays and JSON as well as out of the logs.
     *
     * A hash is not a code, but it is the one column on this table whose presence in
     * a dumped response or a debug payload has no upside at all.
     */
    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'verified_at' => 'datetime',
            'burned_at' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     * ------------------------------------------------------------------ */

    /** Whatever is being collected: a shop order, a participant's shirt, anything. */
    public function verifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Every entry of this code, right or wrong, oldest first. */
    public function attemptLog(): HasMany
    {
        return $this->hasMany(CollectionVerificationAttempt::class)->orderBy('id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /* ---------------------------------------------------------------------
     | State
     * ------------------------------------------------------------------ */

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isBurned(): bool
    {
        return $this->burned_at !== null;
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function wasSent(): bool
    {
        return $this->sent_at !== null;
    }

    /** Whether this code could still be entered successfully. */
    public function isLive(): bool
    {
        return $this->wasSent()
            && ! $this->isBurned()
            && ! $this->isVerified()
            && ! $this->hasExpired();
    }

    /** How many wrong entries this code has left before it burns. */
    public function attemptsLeft(): int
    {
        return max(0, $this->max_attempts - $this->attempts);
    }
}
