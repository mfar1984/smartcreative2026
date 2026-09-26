<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named scoring profile a tournament can pick up.
 *
 * Holds no logic for working out points. That belongs to ScoringEngine, which
 * reads this and can be tested without touching a database.
 */
class PointRule extends Model
{
    use HasFactory;

    public const KIND_BATTLE_ROYALE = 'battle_royale';
    public const KIND_BRACKET = 'bracket';
    public const KIND_RACE = 'race';
    public const KIND_JUDGED = 'judged';

    /**
     * The four families, and what each one means on screen.
     *
     * @var array<string, string>
     */
    public const KINDS = [
        self::KIND_BATTLE_ROYALE => 'Battle Royale',
        self::KIND_BRACKET => 'Bracket',
        self::KIND_RACE => 'Race',
        self::KIND_JUDGED => 'Judged',
    ];

    /**
     * The component types the engine understands.
     */
    public const TYPE_TABLE = 'table';
    public const TYPE_PER_UNIT = 'per_unit';
    public const TYPE_BONUS = 'bonus';
    public const TYPE_PENALTY_TABLE = 'penalty_table';
    public const TYPE_AGGREGATE = 'aggregate';

    /**
     * How an aggregate component combines a panel's marks.
     *
     * @var array<string, string>
     */
    public const AGGREGATE_METHODS = [
        'sum' => 'Sum of every mark',
        'mean' => 'Average of every mark',
        'trimmed_mean' => 'Average after dropping the highest and lowest',
    ];

    /**
     * Whether a profile records personal figures for individual players.
     *
     * `off` is the default and the safe one: player rows never appear. `optional`
     * shows them but a match closes without them. `required` refuses to close a
     * match until every player who took part has a figure.
     */
    public const TRACK_OFF = 'off';
    public const TRACK_OPTIONAL = 'optional';
    public const TRACK_REQUIRED = 'required';

    /**
     * @var array<string, string>
     */
    public const TRACK_MODES = [
        self::TRACK_OFF => 'Off — no player rows at all',
        self::TRACK_OPTIONAL => 'Optional — rows shown, match closes without them',
        self::TRACK_REQUIRED => 'Required — every player who took part needs a figure',
    ];

    protected $fillable = [
        'name',
        'kind',
        'squad_size',
        'track_players',
        'components',
        'inputs',
        'tiebreak',
        'player_components',
        'player_inputs',
        'player_tiebreak',
        'player_slots',
        'match_awards',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'squad_size' => 'integer',
            'components' => 'array',
            'inputs' => 'array',
            'tiebreak' => 'array',
            'player_components' => 'array',
            'player_inputs' => 'array',
            'player_tiebreak' => 'array',
            'player_slots' => 'integer',
            'match_awards' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tournaments(): HasMany
    {
        return $this->hasMany(Tournament::class);
    }

    /* ---------------------------------------------------------------------
     | Reading the profile
     * ------------------------------------------------------------------ */

    /**
     * One component by its key, or null when the profile has no such component.
     *
     * @return array<string, mixed>|null
     */
    public function component(string $key): ?array
    {
        foreach ($this->components ?? [] as $component) {
            if (($component['key'] ?? null) === $key) {
                return $component;
            }
        }

        return null;
    }

    /**
     * One input definition by its key.
     *
     * @return array<string, mixed>|null
     */
    public function input(string $key): ?array
    {
        foreach ($this->inputs ?? [] as $input) {
            if (($input['key'] ?? null) === $key) {
                return $input;
            }
        }

        return null;
    }

    /**
     * Component keys in the order they should be displayed as columns.
     *
     * @return array<int, string>
     */
    public function componentKeys(): array
    {
        return array_values(array_filter(array_map(
            fn (array $component) => $component['key'] ?? null,
            $this->components ?? [],
        )));
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    /* ---------------------------------------------------------------------
     | Player ledger
     |
     | Kept apart from the team methods above on purpose. Nothing here reads
     | `components` and nothing above reads `player_components`.
     * ------------------------------------------------------------------ */

    /**
     * Whether player rows should be shown at all.
     */
    public function tracksPlayers(): bool
    {
        return ($this->track_players ?? self::TRACK_OFF) !== self::TRACK_OFF;
    }

    /**
     * Whether a match may not be closed until players have figures.
     */
    public function requiresPlayers(): bool
    {
        return ($this->track_players ?? self::TRACK_OFF) === self::TRACK_REQUIRED;
    }

    /**
     * How many players are named per match, or null to take whole rosters.
     *
     * A number here changes the way figures are entered: instead of a panel per squad
     * listing everybody on it, the operator picks that many players out of the whole
     * fixture. Twenty squads of four is eighty rows nobody will type; the eight best is
     * a leaderboard somebody will actually keep.
     *
     * Only meaningful while tracking is on, and only once there is something to record,
     * so both are checked here rather than at each call site.
     */
    public function playerSlots(): ?int
    {
        if (! $this->tracksPlayers() || ($this->player_inputs ?? []) === []) {
            return null;
        }

        $slots = (int) ($this->player_slots ?? 0);

        return $slots > 0 ? $slots : null;
    }

    /**
     * Whether personal figures are entered by naming a few players.
     */
    public function picksPlayers(): bool
    {
        return $this->playerSlots() !== null;
    }

    /**
     * The Star of the Match awards this profile hands out, in the order set.
     *
     * Each one names the figures its card carries and which of them is the headline:
     * Going All Out leads on eliminations, Best Companion on rescues. Only meaningful
     * where individual players are tracked, which is the same switch that keeps
     * personal figures off racing and badminton, so it is checked here once.
     *
     * @return array<int, array{key: string, label: string, headline: string, fields: array<int, array{key: string, label: string, decimal: bool}>}>
     */
    public function matchAwards(): array
    {
        if (! $this->tracksPlayers()) {
            return [];
        }

        return array_values(array_filter(
            $this->match_awards ?? [],
            fn ($award) => is_array($award) && filled($award['key'] ?? null) && ($award['fields'] ?? []) !== [],
        ));
    }

    /**
     * One player component by its key.
     *
     * @return array<string, mixed>|null
     */
    public function playerComponent(string $key): ?array
    {
        foreach ($this->player_components ?? [] as $component) {
            if (($component['key'] ?? null) === $key) {
                return $component;
            }
        }

        return null;
    }

    /**
     * One player input definition by its key.
     *
     * @return array<string, mixed>|null
     */
    public function playerInput(string $key): ?array
    {
        foreach ($this->player_inputs ?? [] as $input) {
            if (($input['key'] ?? null) === $key) {
                return $input;
            }
        }

        return null;
    }

    /**
     * Player component keys in display order.
     *
     * @return array<int, string>
     */
    public function playerComponentKeys(): array
    {
        return array_values(array_filter(array_map(
            fn (array $component) => $component['key'] ?? null,
            $this->player_components ?? [],
        )));
    }

    /**
     * Whether this profile can score a tournament running the given format.
     *
     * The formats are narrower than the kinds: three different bracket formats all
     * score the same way, so they all accept a bracket profile.
     */
    public function supportsFormat(string $format): bool
    {
        return $this->kind === self::kindForFormat($format);
    }

    /**
     * Which scoring kind a tournament format needs.
     */
    public static function kindForFormat(string $format): string
    {
        return match ($format) {
            'single_elim', 'group_single_elim', 'double_elim' => self::KIND_BRACKET,
            'battle_royale' => self::KIND_BATTLE_ROYALE,
            'race' => self::KIND_RACE,
            'judged' => self::KIND_JUDGED,
            default => self::KIND_BRACKET,
        };
    }
}
