<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One IP address an account has signed in from. See LoginLocationService.
 *
 * The absence of a row for (account, address) is what "somewhere new" means, so
 * these rows are written on every successful sign in whether or not the warning
 * email is switched on and whether or not activity logging is switched on.
 */
class UserKnownIp extends Model
{
    protected $fillable = [
        'user_id',
        'ip_address',
        'hits',
        'first_seen_at',
        'last_seen_at',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'hits' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
