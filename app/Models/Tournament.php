<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One competition run on top of an event's entries.
 *
 * Several tournaments may be ongoing at the same time, including several on the
 * same event. Nothing on this model reads shared state, so two of them cannot
 * interfere with one another.
 */
class Tournament extends Model
{
    use HasFactory;

    public const STATUS_SETUP = 'setup';
    public const STATUS_ONGOING = 'ongoing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PUBLISHED = 'published';

    /** @var array<string, string> */
    public const STATUSES = [
        self::STATUS_SETUP => 'Setup',
        self::STATUS_ONGOING => 'Ongoing',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_PUBLISHED => 'Published',
    ];

    public const FORMAT_SINGLE_ELIM = 'single_elim';
    public const FORMAT_GROUP_SINGLE_ELIM = 'group_single_elim';
    public const FORMAT_DOUBLE_ELIM = 'double_elim';
    public const FORMAT_BATTLE_ROYALE = 'battle_royale';
    public const FORMAT_RACE = 'race';
    public const FORMAT_JUDGED = 'judged';

    /** @var array<string, string> */
    public const FORMATS = [
        self::FORMAT_SINGLE_ELIM => 'Single Elimination',
        self::FORMAT_GROUP_SINGLE_ELIM => 'Group Stage + Single Elimination',
        self::FORMAT_DOUBLE_ELIM => 'Double Elimination',
        self::FORMAT_BATTLE_ROYALE => 'Battle Royale',
        self::FORMAT_RACE => 'Race',
        self::FORMAT_JUDGED => 'Judged',
    ];

    /**
     * A sentence for each format, so the operator picking one is told what it means
     * rather than being left to recognise the name.
     *
     * @var array<string, string>
     */
    public const FORMAT_NOTES = [
        self::FORMAT_SINGLE_ELIM => 'One loss and you are out. Quickest to run, and the usual choice for a first tournament.',
        self::FORMAT_GROUP_SINGLE_ELIM => 'Round robin groups first, then a knockout. Every team gets at least three matches.',
        self::FORMAT_DOUBLE_ELIM => 'Two lives each. Best kept for a Top 4 playoff, because it doubles the matches.',
        self::FORMAT_BATTLE_ROYALE => 'Everybody plays at once in lobbies. Points from placement and kills add up.',
        self::FORMAT_RACE => 'One start, one finish. Placement worked out from times.',
        self::FORMAT_JUDGED => 'A panel awards marks. No head to head.',
    ];

    public const SEEDING_MANUAL = 'manual';
    public const SEEDING_RANDOM = 'random';
    public const SEEDING_REGISTRATION = 'registration';

    /** @var array<string, string> */
    public const SEEDING_METHODS = [
        self::SEEDING_MANUAL => 'Arrange by hand',
        self::SEEDING_RANDOM => 'Random draw',
        self::SEEDING_REGISTRATION => 'Order they registered',
    ];

    protected $fillable = [
        'event_id',
        'name',
        'format',
        'point_rule_id',
        'status',
        'seeding_method',
        'settings',
        'draw_generated_at',
        'seeded_at',
        'completed_at',
        'published_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'draw_generated_at' => 'datetime',
            'seeded_at' => 'datetime',
            'completed_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     * ------------------------------------------------------------------ */

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function pointRule(): BelongsTo
    {
        return $this->belongsTo(PointRule::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function entrants(): HasMany
    {
        return $this->hasMany(TournamentEntrant::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(TournamentStage::class)->orderBy('sequence');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(TournamentMatch::class);
    }

    public function standings(): HasMany
    {
        return $this->hasMany(TournamentStanding::class);
    }

    public function champions(): HasMany
    {
        return $this->hasMany(TournamentChampion::class)->orderBy('rank');
    }

    /**
     * The player ledger, entirely separate from `standings()` and `champions()`.
     *
     * Nothing here contributes to who wins the tournament, and nothing from the
     * team side contributes to who wins MVP.
     */
    public function playerStandings(): HasMany
    {
        return $this->hasMany(TournamentPlayerStanding::class);
    }

    public function playerAwards(): HasMany
    {
        return $this->hasMany(TournamentPlayerAward::class)->orderBy('award_key')->orderBy('rank');
    }

    /**
     * Whether this tournament's profile records personal player figures.
     */
    public function tracksPlayers(): bool
    {
        return (bool) $this->pointRule?->tracksPlayers();
    }

    /* ---------------------------------------------------------------------
     | Reading state
     * ------------------------------------------------------------------ */

    public function isSetup(): bool
    {
        return $this->status === self::STATUS_SETUP;
    }

    public function isOngoing(): bool
    {
        return $this->status === self::STATUS_ONGOING;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * Whether entrants and seeds may still be changed.
     *
     * Once a draw exists, changing who is in it would leave fixtures pointing at
     * competitors who are no longer there.
     */
    public function isEditable(): bool
    {
        return $this->isSetup() && $this->draw_generated_at === null;
    }

    public function hasDraw(): bool
    {
        return $this->draw_generated_at !== null;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function formatLabel(): string
    {
        return self::FORMATS[$this->format] ?? $this->format;
    }

    public function formatNote(): string
    {
        return self::FORMAT_NOTES[$this->format] ?? '';
    }

    public function seedingLabel(): string
    {
        return self::SEEDING_METHODS[$this->seeding_method] ?? $this->seeding_method;
    }

    /**
     * A setting from this tournament's own copy, falling back to a given default.
     *
     * Read from the copy rather than the shared defaults on purpose: a tournament
     * under way keeps the rules it started with.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /**
     * Whether this format needs a bracket, which decides what the draw looks like
     * and whether an entrant can be knocked out.
     */
    public function isBracketFormat(): bool
    {
        return in_array($this->format, [
            self::FORMAT_SINGLE_ELIM,
            self::FORMAT_GROUP_SINGLE_ELIM,
            self::FORMAT_DOUBLE_ELIM,
        ], true);
    }

    public function activeEntrantCount(): int
    {
        return $this->entrants()->where('status', TournamentEntrant::STATUS_ACTIVE)->count();
    }
}
