<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A frozen podium place.
 *
 * The name and the totals are copied here at the moment of publishing. Nothing
 * reads them back through standings, so correcting a match months later cannot
 * change a result that has already been announced and had prizes given for it.
 *
 * To change a published podium the operator has to withdraw it first, which leaves
 * a trail.
 */
class TournamentChampion extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_id',
        'tournament_entrant_id',
        'rank',
        'display_name',
        'total_points',
        'component_totals',
        'published_at',
        'published_by',
    ];

    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'total_points' => 'float',
            'component_totals' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function entrant(): BelongsTo
    {
        return $this->belongsTo(TournamentEntrant::class, 'tournament_entrant_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * Gold, silver, bronze, said in words rather than only by position number.
     */
    public function medalLabel(): string
    {
        return match ($this->rank) {
            1 => 'Champion',
            2 => 'Runner-up',
            3 => 'Third place',
            default => sprintf('%d place', $this->rank),
        };
    }
}
