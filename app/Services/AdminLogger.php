<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Support\SecuritySettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class AdminLogger
{
    /**
     * How much of a description the column holds.
     *
     * 252 rather than 255, so the three characters Str::limit appends to mark the cut
     * still fit inside varchar(255).
     */
    private const DESCRIPTION_LIMIT = 252;

    public const LEVEL_INFO = 'info';
    public const LEVEL_WARN = 'warn';
    public const LEVEL_ERROR = 'error';
    public const LEVEL_DEBUG = 'debug';

    public const LEVELS = [
        self::LEVEL_INFO => 'Info',
        self::LEVEL_WARN => 'Warn',
        self::LEVEL_ERROR => 'Error',
        self::LEVEL_DEBUG => 'Debug',
    ];

    /**
     * Human readable category per action prefix, used by the log filters.
     */
    private const CATEGORIES = [
        'auth' => 'Auth',
        'users' => 'Users',
        'roles' => 'Roles',
        'settings' => 'Settings',
        'logs' => 'Logging',
        'enquiries' => 'Enquiries',
    ];

    /**
     * The one named place that says what the logging switches may NOT silence.
     *
     * Prefix-matched against an activity action AND against an audit event, so a
     * single list governs both logs and the two cannot drift apart.
     *
     *   auth.              Every sign in, sign out, refusal, inactivity logout,
     *                      ban, super-admin ban bypass and new-location warning.
     *                      Who got into the admin area, and who was stopped at the
     *                      door, is where any investigation starts.
     *   settings.security. The Security tab itself: the save that flips these very
     *                      switches, and every unban. A switch that can erase the
     *                      record of its own use is worse than no switch — it lets
     *                      somebody turn logging off, act, and turn it back on with
     *                      nothing left to show that any of it happened. So the
     *                      Security save is recorded REGARDLESS of these switches.
     *
     *   security.          The Security Log's own actions, which today is the one
     *                      line saying the system started blocking an address. The
     *                      Security Log itself does not come through here at all —
     *                      it has its own table, which neither switch is wired to,
     *                      so there is no switch to find — but the activity line
     *                      that accompanies a ban does, and a log somebody can
     *                      silence is worth nothing on the day it matters.
     *
     * Deliberately short. Everything else — users, roles, payments, registrations,
     * backups, the other settings tabs — obeys the switches, which is the point of
     * having them.
     */
    public const ALWAYS_RECORDED = [
        'auth.',
        'settings.security.',
        'security.',
    ];

    /**
     * Key fragments whose value is never written to a log.
     *
     * A named constant rather than a local array because the Security Log redacts
     * against this same list plus its own additions, and two lists that are meant to
     * agree are two lists that will not. Matched as a substring of the lowercased
     * key, so 'password_confirmation' and 'api_key' are both caught by one entry.
     *
     * @var array<int, string>
     */
    public const SENSITIVE_KEYS = [
        'password',
        'remember_token',
        'secret',
        'token',
        'api_key',
    ];

    /**
     * Record something a person did.
     *
     * actor_label is stored alongside the foreign key so history stays
     * readable after a user is deleted.
     *
     * Returns the row that was written, or an UNSAVED ActivityLog when the switch
     * suppressed it. Never null: the return type has always been a model, callers
     * are free to read it, and handing back null here would turn a disabled log
     * into a fatal error somewhere unrelated. An unsaved instance carries the same
     * attributes, answers exists === false and has no key.
     */
    public static function activity(
        string $action,
        string $description,
        ?int $userId = null,
        ?string $actorLabel = null,
        string $level = self::LEVEL_INFO,
    ): ActivityLog {
        $user = Auth::user();

        $attributes = [
            'user_id' => $userId ?? $user?->id,
            'actor_label' => $actorLabel ?? $user?->logLabel(),
            'action' => $action,
            'level' => array_key_exists($level, self::LEVELS) ? $level : self::LEVEL_INFO,
            'category' => self::categoryFor($action),

            /*
             | Trimmed to the column width, the same way the user agent above already
             | is. A description assembled from a variable number of parts can run past
             | 255 characters, and MySQL answers that with an exception rather than a
             | truncation, which turns a log line into a 500 on the action it was
             | describing. Recording the action matters more than recording every word
             | about it, so the sentence is cut and the work stands.
             */
            'description' => Str::limit($description, self::DESCRIPTION_LIMIT, '...'),

            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 512),
        ];

        // The switch is read here and nowhere else. There are over two hundred
        // call sites; a check at each of them is a check one of them would be
        // missing.
        if (! self::isAlwaysRecorded($action) && ! SecuritySettings::activityLogEnabled()) {
            return new ActivityLog($attributes);
        }

        return ActivityLog::create($attributes);
    }

    /**
     * Record a change to a record, keeping the before and after values.
     *
     * Same return contract as activity(): the written row, or an unsaved AuditLog
     * when the switch suppressed it, never null.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public static function audit(Model $model, string $event, ?array $oldValues = null, ?array $newValues = null): AuditLog
    {
        $user = Auth::user();

        $attributes = [
            'user_id' => $user?->id,
            'actor_label' => $user?->logLabel(),
            'actor_role' => $user?->role?->name,
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'event' => $event,
            'old_values' => self::redact($oldValues),
            'new_values' => self::redact($newValues),
            'ip_address' => Request::ip(),
        ];

        // As above: one place reads the switch, and ALWAYS_RECORDED overrides it.
        if (! self::isAlwaysRecorded($event) && ! SecuritySettings::auditLogEnabled()) {
            return new AuditLog($attributes);
        }

        return AuditLog::create($attributes);
    }

    /**
     * Whether this action or audit event is one the switches cannot silence.
     */
    public static function isAlwaysRecorded(string $actionOrEvent): bool
    {
        return Str::startsWith($actionOrEvent, self::ALWAYS_RECORDED);
    }

    /**
     * First segment of the action name, mapped to a display category.
     */
    private static function categoryFor(string $action): string
    {
        $prefix = Str::before($action, '.');

        return self::CATEGORIES[$prefix] ?? Str::headline($prefix ?: 'general');
    }

    /**
     * Never write credentials or secrets into the audit trail.
     *
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private static function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach ($values as $key => $value) {
            foreach (self::SENSITIVE_KEYS as $needle) {
                if (str_contains(strtolower((string) $key), $needle)) {
                    $values[$key] = '[redacted]';
                    break;
                }
            }
        }

        return $values;
    }
}
