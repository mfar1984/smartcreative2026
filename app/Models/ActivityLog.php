<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    /**
     * Only created_at is tracked; an activity entry is never updated.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'actor_label',
        'action',
        'level',
        'category',
        'description',
        'ip_address',
        'user_agent',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
