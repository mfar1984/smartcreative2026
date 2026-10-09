<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One address barred from the admin sign in. See LoginBanService.
 *
 * A row bars sign in only while expires_at is in the future; an expired row is
 * history and every check ignores it.
 */
class BannedIp extends Model
{
    protected $fillable = [
        'ip_address',
        'failed_attempts',
        'reason',
        'banned_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'failed_attempts' => 'integer',
            'banned_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** Bans still in force. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }
}
