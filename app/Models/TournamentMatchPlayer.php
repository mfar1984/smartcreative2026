<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One player's personal line in one match.
 *
 * This is the second ledger. Its `points` are worked out from the profile's
 * `player_components` and are never added to the team's total, and the team's are
 * never added here. Keeping them apart is what lets the whole feature stay
 * optional: a tournament must be able to reach a published podium without a single
 * row in this table.
 *
 * Hung off the team's match line rather than off the match, so that discarding a
 * draw removes these rows with it in one cascade.
 */
class TournamentMatchPlayer extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_match_entrant_id',
        'event_participant_id',
        'took_part',
        'inputs',
        'points',
        'component_points',
        'component_counts',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'took_part' => 'boolean',
            'inputs' => 'array',
            'points' => 'float',
            'component_points' => 'array',
            'component_counts' => 'array',
        ];
    }

    public function matchEntrant(): BelongsTo
    {
        return $this->belongsTo(TournamentMatchEntrant::class, 'tournament_match_entrant_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(EventParticipant::class, 'event_participant_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return data_get($this->inputs, $key, $default);
    }

    public function componentPoints(string $key): float
    {
        return (float) data_get($this->component_points, $key, 0);
    }

    public function componentCount(string $key): int
    {
        return (int) data_get($this->component_counts, $key, 0);
    }

    /**
     * Whether any figure has been entered for this player.
     *
     * Attendance alone does not count. A player can be marked as having taken part
     * while nobody has yet typed their kills.
     */
    public function isScored(): bool
    {
        return filled($this->inputs);
    }
}
