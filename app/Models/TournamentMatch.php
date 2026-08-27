<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One fixture. Two sides in a bracket, up to sixteen in a lobby, everybody in a
 * heat.
 *
 * Where the winner and loser go next is written on the row when the draw is
 * generated, so advancing somebody is following a pointer rather than working the
 * tree out again from a round number.
 */
class TournamentMatch extends Model
{
    use HasFactory;

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_AWAITING = 'awaiting_result';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_WALKOVER = 'walkover';

    /** @var array<string, string> */
    public const STATUSES = [
        self::STATUS_SCHEDULED => 'Scheduled',
        self::STATUS_AWAITING => 'Awaiting Result',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_WALKOVER => 'Walkover',
    ];

    public const SIDE_UPPER = 'upper';
    public const SIDE_LOWER = 'lower';
    public const SIDE_FINAL = 'final';

    public const RESOLUTION_WALKOVER = 'walkover';
    public const RESOLUTION_FORFEIT = 'forfeit';
    public const RESOLUTION_DISQUALIFICATION = 'disqualification';
    public const RESOLUTION_WITHDRAWAL = 'withdrawal';

    protected $fillable = [
        'tournament_id',
        'tournament_stage_id',
        'tournament_group_id',
        'round',
        'position',
        'bracket_side',
        'winner_to_match_id',
        'winner_to_slot',
        'loser_to_match_id',
        'loser_to_slot',
        'best_of',
        'map',
        'scheduled_at',
        'status',
        'winner_entrant_id',
        'resolution',
        'reason',
        'scored_by',
        'scored_at',
    ];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'position' => 'integer',
            'best_of' => 'integer',
            'scheduled_at' => 'datetime',
            'scored_at' => 'datetime',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     * ------------------------------------------------------------------ */

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

    public function entrants(): HasMany
    {
        return $this->hasMany(TournamentMatchEntrant::class, 'tournament_match_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(TournamentEntrant::class, 'winner_entrant_id');
    }

    public function scorer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scored_by');
    }

    public function proofs(): HasMany
    {
        return $this->hasMany(TournamentProof::class, 'tournament_match_id');
    }

    /* ---------------------------------------------------------------------
     | Reading
     * ------------------------------------------------------------------ */

    public function isSettled(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_WALKOVER], true);
    }

    public function isOpen(): bool
    {
        return ! $this->isSettled();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /**
     * A short name for the fixture, used in tables and confirmations.
     *
     * Rounds are numbered rather than named because "quarter final" only means
     * something in a bracket of a particular size, and the same table shows lobbies
     * and heats too.
     */
    public function label(): string
    {
        if ($this->round !== null) {
            $side = match ($this->bracket_side) {
                self::SIDE_LOWER => 'LB ',
                self::SIDE_FINAL => 'GF ',
                default => '',
            };

            return sprintf('%sR%d-%d', $side, $this->round, $this->position ?? 1);
        }

        return sprintf('M%d', $this->position ?? $this->id);
    }

    /**
     * Whether both sides of a bracket fixture are known.
     *
     * A match waiting on an earlier result cannot be scored, and saying so is
     * better than offering a form with one empty side.
     */
    public function isReady(): bool
    {
        if ($this->relationLoaded('entrants')) {
            $filled = $this->entrants->whereNotNull('tournament_entrant_id')->count();
        } else {
            $filled = $this->entrants()->whereNotNull('tournament_entrant_id')->count();
        }

        return $this->round === null ? $filled > 0 : $filled >= 2;
    }
}
