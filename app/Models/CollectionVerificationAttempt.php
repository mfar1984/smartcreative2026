<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of a collection code.
 *
 * Written for every attempt including the successful one, so the record of a
 * handover shows how many goes it took rather than only that it worked. The
 * submitted digits are never stored: a near miss on a live code is still worth
 * guarding.
 */
class CollectionVerificationAttempt extends Model
{
    public const OUTCOME_VERIFIED = 'verified';
    public const OUTCOME_WRONG = 'wrong';
    public const OUTCOME_EXPIRED = 'expired';
    public const OUTCOME_BURNED = 'burned';
    public const OUTCOME_NONE = 'none';

    protected $fillable = [
        'collection_verification_id',
        'outcome',
        'user_id',
        'actor_label',
        'ip_address',
    ];

    public function verification(): BelongsTo
    {
        return $this->belongsTo(CollectionVerification::class, 'collection_verification_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
