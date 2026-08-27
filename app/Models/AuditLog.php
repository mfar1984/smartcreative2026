<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    /**
     * Only created_at is tracked; an audit entry is never updated.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'actor_label',
        'actor_role',
        'auditable_type',
        'auditable_id',
        'event',
        'old_values',
        'new_values',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Short class name of the audited record, for display in the log table.
     */
    public function auditableLabel(): string
    {
        return class_basename($this->auditable_type);
    }
}
