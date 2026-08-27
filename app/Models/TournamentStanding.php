<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a standings table.
 *
 * Always written by StandingsCalculator from the match results, never typed and
 * never incremented. If a figure here disagrees with the matches, the matches are
 * right and this is stale.
 */
class TournamentStanding extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_id',
        'tournament_stage_id',
        'tournament_group_id',
        'tournament_entrant_id',
        'played',
        'won',
        'lost',
        'component_totals',
        'component_counts',
        'total_points',
        'rank',
        'is_disqualified',
        'advances',
        'is_tied',
    ];

    protected function casts(): array
    {
        return [
            'played' => 'integer',
            'won' => 'integer',
            'lost' => 'integer',
            'component_totals' => 'array',
            'component_counts' => 'array',
            'total_points' => 'float',
            'rank' => 'integer',
            'is_disqualified' => 'boolean',
            'advances' => 'boolean',
            'is_tied' => 'boolean',
        ];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(TournamentStage::class, 'tournament_stage_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(TournamentGroup::class, 'tournament_group_id');
    }

    public function entrant(): BelongsTo
    {
        return $this->belongsTo(TournamentEntrant::class, 'tournament_entrant_id');
    }

    public function componentTotal(string $key): float
    {
        return (float) data_get($this->component_totals, $key, 0);
    }

    public function componentCount(string $key): int
    {
        return (int) data_get($this->component_counts, $key, 0);
    }
}
