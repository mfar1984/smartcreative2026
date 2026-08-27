<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One phase of a tournament: a group stage, a bracket, a set of lobbies, or heats.
 *
 * Several of these in sequence is what lets a tournament change shape partway
 * through, which the organiser specifically asked for: knockout down to the last
 * four, then a double elimination playoff.
 */
class TournamentStage extends Model
{
    use HasFactory;

    public const TYPE_GROUP = 'group';
    public const TYPE_BRACKET = 'bracket';
    public const TYPE_LOBBY = 'lobby';
    public const TYPE_HEAT = 'heat';

    /** @var array<string, string> */
    public const TYPES = [
        self::TYPE_GROUP => 'Group Stage',
        self::TYPE_BRACKET => 'Bracket',
        self::TYPE_LOBBY => 'Lobbies',
        self::TYPE_HEAT => 'Heats',
    ];

    /** @var array<string, string> */
    public const TYPE_NOTES = [
        self::TYPE_GROUP => 'Round robin inside each group. Everybody plays everybody in their group.',
        self::TYPE_BRACKET => 'Knockout tree. The winner of each match advances.',
        self::TYPE_LOBBY => 'Up to sixteen entrants play at once, several matches each.',
        self::TYPE_HEAT => 'One start, one result per entrant.',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_ONGOING = 'ongoing';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'tournament_id',
        'name',
        'type',
        'sequence',
        'advance_count',
        'match_count',
        'best_of',
        'status',
        'drawn_at',
        'drawn_by',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'advance_count' => 'integer',
            'match_count' => 'integer',
            'best_of' => 'array',
            'drawn_at' => 'datetime',
        ];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(TournamentGroup::class)->orderBy('sequence');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(TournamentMatch::class)
            ->orderBy('round')
            ->orderBy('position');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function hasDraw(): bool
    {
        return $this->drawn_at !== null;
    }

    /**
     * Best-of for a given round, falling back to one game.
     *
     * Stored per round because early rounds are usually a single game to save time
     * while a final is longer.
     */
    public function bestOfForRound(int $round): int
    {
        return (int) ($this->best_of[(string) $round] ?? $this->best_of[$round] ?? 1);
    }

    /**
     * Whether every match in this stage has a result.
     */
    public function isPlayedOut(): bool
    {
        return ! $this->matches()
            ->whereIn('status', [TournamentMatch::STATUS_SCHEDULED, TournamentMatch::STATUS_AWAITING])
            ->exists();
    }
}
