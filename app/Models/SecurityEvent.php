<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One refusal, or one noticed probe. See SecurityEventRecorder for who writes them.
 *
 * A row is append-mostly rather than append-only: a repeat of the same refusal from
 * the same address on the same path bumps `hits` and `last_seen_at` instead of
 * inserting again, which is what keeps one prober from filling the table.
 */
class SecurityEvent extends Model
{
    /** Intelligence. Noticed, never acted on. */
    public const SEVERITY_INFO = 'info';

    /** A refusal: somebody was stopped. */
    public const SEVERITY_WARNING = 'warning';

    /** A refusal that says somebody is probing, or that an address was barred. */
    public const SEVERITY_CRITICAL = 'critical';

    /** @var array<string, string> */
    public const SEVERITIES = [
        self::SEVERITY_INFO => 'Info',
        self::SEVERITY_WARNING => 'Warning',
        self::SEVERITY_CRITICAL => 'Critical',
    ];

    /*
     | What was refused. One slug per refusal site, so the screen can group them and
     | so a later release can add a site without the filters having to change.
     */

    /** A 403: the permission middleware, the handler scope, a cross-tab or sponsor id. */
    public const TYPE_ACCESS_DENIED = 'access_denied';

    /** A 419: the CSRF token was missing, stale or wrong. */
    public const TYPE_CSRF_FAILURE = 'csrf_failure';

    /** A signed link whose signature did not check out, or had expired. */
    public const TYPE_INVALID_SIGNATURE = 'invalid_signature';

    /** A 429 from one of the throttles. */
    public const TYPE_RATE_LIMITED = 'rate_limited';

    /** A sign in refused because the address is banned. */
    public const TYPE_LOGIN_BANNED = 'login_banned';

    /** A sign in refused because the address is not on the IP allowlist. */
    public const TYPE_LOGIN_NOT_ALLOWLISTED = 'login_not_allowlisted';

    /** A signed-in session ended because its address left the IP allowlist. */
    public const TYPE_SESSION_NOT_ALLOWLISTED = 'session_not_allowlisted';

    /** A file name refused by the backup store rather than repaired. */
    public const TYPE_PATH_REFUSED = 'path_refused';

    /** A probing pattern seen in request data. OBSERVED ONLY — never refused. */
    public const TYPE_SUSPICIOUS_INPUT = 'suspicious_input';

    /** The repetition ban fired: too many refusals from one address. */
    public const TYPE_IP_BANNED = 'ip_banned';

    /** Human readable label per type, for the screen. */
    public const TYPES = [
        self::TYPE_ACCESS_DENIED => 'Access denied',
        self::TYPE_CSRF_FAILURE => 'CSRF failure',
        self::TYPE_INVALID_SIGNATURE => 'Invalid signature',
        self::TYPE_RATE_LIMITED => 'Rate limited',
        self::TYPE_LOGIN_BANNED => 'Sign in from banned address',
        self::TYPE_LOGIN_NOT_ALLOWLISTED => 'Sign in off the allowlist',
        self::TYPE_SESSION_NOT_ALLOWLISTED => 'Session off the allowlist',
        self::TYPE_PATH_REFUSED => 'File name refused',
        self::TYPE_SUSPICIOUS_INPUT => 'Suspicious input (observed)',
        self::TYPE_IP_BANNED => 'Address banned',
    ];

    protected $fillable = [
        'severity',
        'type',
        'description',
        'ip_address',
        'user_id',
        'actor_label',
        'method',
        'path',
        'user_agent',
        'context',
        'hits',
        'first_seen_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'hits' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The events the repetition ban counts: refusals, and only refusals.
     *
     * TYPE_SUSPICIOUS_INPUT is deliberately left out. It is an observation, not a
     * refusal — the application stored the text harmlessly and answered normally —
     * so letting it drive a ban would make the pattern set a gate by the back door,
     * which is exactly what it must never become. TYPE_IP_BANNED is left out too,
     * so a ban cannot count towards the next one.
     */
    public function scopeCountsTowardsBan(Builder $query): Builder
    {
        return $query->whereNotIn('type', [
            self::TYPE_SUSPICIOUS_INPUT,
            self::TYPE_IP_BANNED,
        ]);
    }

    /** The label for this row's type, or the raw slug if it is unknown. */
    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
