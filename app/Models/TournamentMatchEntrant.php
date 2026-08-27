<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One competitor's line in one match.
 *
 * The raw numbers live in `inputs` as JSON rather than as columns. Eleven sports
 * with fixed columns would mean a dozen of them with nine always null, and a
 * twelfth sport would mean a migration. What the operator typed goes in `inputs`;
 * what it was worth goes in `points` and `component_points`, worked out by the
 * scoring engine and never typed.
 */
class TournamentMatchEntrant extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_match_id',
        'tournament_entrant_id',
        'slot',
        'inputs',
        'points',
        'component_points',
        'component_counts',
        'is_disqualified',
    ];

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'inputs' => 'array',
            'points' => 'float',
            'component_points' => 'array',
            'component_counts' => 'array',
            'is_disqualified' => 'boolean',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(TournamentMatch::class, 'tournament_match_id');
    }

    public function entrant(): BelongsTo
    {
        return $this->belongsTo(TournamentEntrant::class, 'tournament_entrant_id');
    }

    /**
     * The personal lines belonging to this team line.
     *
     * A separate ledger that happens to hang here for cascade and query reasons.
     * Nothing summed from these rows is ever added to this row's `points`.
     */
    public function players(): HasMany
    {
        return $this->hasMany(TournamentMatchPlayer::class, 'tournament_match_entrant_id');
    }

    /**
     * The sum of one player input across this team's players, shown beside the
     * team's own figure as information only.
     *
     * A mismatch is not an error. The two ledgers are independent, so the operator
     * is left to notice a typo rather than being blocked by a rule that does not
     * actually exist.
     */
    public function playerInputSum(string $key): int
    {
        return (int) $this->players
            ->filter(fn (TournamentMatchPlayer $player) => $player->took_part)
            ->sum(fn (TournamentMatchPlayer $player) => (int) $player->input($key, 0));
    }

    /**
     * One raw input by key, so a view can read a placement or a kill count without
     * knowing the shape of the whole array.
     */
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
     * Whether a score has been entered for this line at all.
     *
     * A generated fixture has rows with empty inputs, so the presence of the row
     * says nothing about whether anybody has played.
     */
    public function isScored(): bool
    {
        return filled($this->inputs);
    }
}
