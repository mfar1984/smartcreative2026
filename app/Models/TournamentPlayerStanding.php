<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's running personal total, per stage or across the whole tournament.
 *
 * Always rebuilt by counting `tournament_match_players`, never incremented. The
 * separate team table `tournament_standings` is untouched by anything here.
 */
class TournamentPlayerStanding extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_id',
        'tournament_stage_id',
        'tournament_entrant_id',
        'event_participant_id',
        'display_name',
        'ign',
        'matches_played',
        'component_totals',
        'component_counts',
        'total_points',
        'rank',
        'entrant_is_disqualified',
    ];

    protected function casts(): array
    {
        return [
            'matches_played' => 'integer',
            'component_totals' => 'array',
            'component_counts' => 'array',
            'total_points' => 'float',
            'rank' => 'integer',
            'entrant_is_disqualified' => 'boolean',
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

    public function entrant(): BelongsTo
    {
        return $this->belongsTo(TournamentEntrant::class, 'tournament_entrant_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(EventParticipant::class, 'event_participant_id');
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
