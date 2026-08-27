<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group in a group stage, or a lobby in a battle royale.
 *
 * The same table for both because they are the same idea: a subset of the entrants
 * who play among themselves and produce their own table.
 */
class TournamentGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_stage_id',
        'name',
        'sequence',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(TournamentStage::class, 'tournament_stage_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(TournamentMatch::class, 'tournament_group_id');
    }
}
