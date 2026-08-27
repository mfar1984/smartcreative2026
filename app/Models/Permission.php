<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

class Permission extends Model
{
    /**
     * Action columns drawn on the Roles Management matrix, in display order.
     *
     * Any action not listed here falls into the trailing "Other" column, so a
     * new kind of permission never disappears from the screen.
     */
    public const ACTION_COLUMNS = [
        'create' => 'Create',
        'view' => 'View',
        'update' => 'Edit',
        'delete' => 'Delete',
        'restore' => 'Restore',
    ];

    protected $fillable = [
        'slug',
        'name',
        'group',
        'module',
        'action',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * Whether this permission gets its own column or lands in "Other".
     */
    public function hasActionColumn(): bool
    {
        return array_key_exists($this->action, self::ACTION_COLUMNS);
    }

    /**
     * Build the matrix structure the Roles screens render.
     *
     * Returns sections in seeded order, each holding its modules, each holding
     * a map of action => permission. Callers only need to walk the result.
     *
     * @return Collection<string, Collection<string, array{columns: array<string, self>, other: array<int, self>}>>
     */
    public static function matrix(): Collection
    {
        return static::query()
            ->orderBy('sort_order')
            ->get()
            ->groupBy('group')
            ->map(function (Collection $sectionPermissions) {
                return $sectionPermissions
                    ->groupBy('module')
                    ->map(function (Collection $modulePermissions) {
                        $columns = [];
                        $other = [];

                        foreach ($modulePermissions as $permission) {
                            if ($permission->hasActionColumn()) {
                                $columns[$permission->action] = $permission;
                            } else {
                                $other[] = $permission;
                            }
                        }

                        return ['columns' => $columns, 'other' => $other];
                    });
            });
    }
}
