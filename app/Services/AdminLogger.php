<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class AdminLogger
{
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
     * Record something a person did.
     *
     * actor_label is stored alongside the foreign key so history stays
     * readable after a user is deleted.
     */
    public static function activity(
        string $action,
        string $description,
        ?int $userId = null,
        ?string $actorLabel = null,
        string $level = self::LEVEL_INFO,
    ): ActivityLog {
        $user = Auth::user();

        return ActivityLog::create([
            'user_id' => $userId ?? $user?->id,
            'actor_label' => $actorLabel ?? $user?->logLabel(),
            'action' => $action,
            'level' => array_key_exists($level, self::LEVELS) ? $level : self::LEVEL_INFO,
            'category' => self::categoryFor($action),
            'description' => $description,
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 512),
        ]);
    }

    /**
     * Record a change to a record, keeping the before and after values.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public static function audit(Model $model, string $event, ?array $oldValues = null, ?array $newValues = null): AuditLog
    {
        $user = Auth::user();

        return AuditLog::create([
            'user_id' => $user?->id,
            'actor_label' => $user?->logLabel(),
            'actor_role' => $user?->role?->name,
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'event' => $event,
            'old_values' => self::redact($oldValues),
            'new_values' => self::redact($newValues),
            'ip_address' => Request::ip(),
        ]);
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

        $sensitive = ['password', 'password_confirmation', 'remember_token', 'secret', 'token', 'api_key'];

        foreach ($values as $key => $value) {
            foreach ($sensitive as $needle) {
                if (str_contains(strtolower((string) $key), $needle)) {
                    $values[$key] = '[redacted]';
                    break;
                }
            }
        }

        return $values;
    }
}
