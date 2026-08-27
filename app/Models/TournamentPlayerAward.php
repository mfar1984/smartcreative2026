<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A published individual award: MVP, Top Fragger, and whatever else a profile names.
 *
 * Every meaningful field is copied at publish, not read live. Correcting a match
 * months later must not silently change an award that has already been announced.
 * The same rule as `tournament_champions`, for the same reason.
 *
 * Published independently of the team podium, so an organiser may announce the MVP
 * without having published the champions, or the other way round.
 */
class TournamentPlayerAward extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_id',
        'event_participant_id',
        'award_key',
        'award_label',
        'rank',
        'display_name',
        'ign',
        'entrant_name',
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

    public function participant(): BelongsTo
    {
        return $this->belongsTo(EventParticipant::class, 'event_participant_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function componentTotal(string $key): float
    {
        return (float) data_get($this->component_totals, $key, 0);
    }
}
