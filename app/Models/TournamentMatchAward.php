<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Star of the Match award, as it was given.
 *
 * The labels, the figures and the recipient's public name are copied here when the
 * result is saved. A card drawn from this row reads the same after the point rule is
 * edited, the player renames their account, or the tournament moves on, because it is
 * a record of one match rather than a view onto current data.
 */
class TournamentMatchAward extends Model
{
    protected $fillable = [
        'tournament_id',
        'tournament_match_id',
        'tournament_entrant_id',
        'event_participant_id',
        'award_key',
        'award_label',
        'award_position',
        'headline_key',
        'fields',
        'figures',
        'display_name',
        'ign',
        'entrant_name',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'award_position' => 'integer',
            'fields' => 'array',
            'figures' => 'array',
        ];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(TournamentMatch::class, 'tournament_match_id');
    }

    public function entrant(): BelongsTo
    {
        return $this->belongsTo(TournamentEntrant::class, 'tournament_entrant_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(EventParticipant::class, 'event_participant_id');
    }

    /**
     * A figure as recorded, or null where none was supplied.
     *
     * Null and zero are different answers. Zero means the result screen said zero;
     * null means nobody gave a number, and the card shows a dash rather than
     * inventing one.
     */
    public function figure(string $key): int|float|null
    {
        $value = data_get($this->figures, $key);

        if ($value === null || $value === '') {
            return null;
        }

        return is_float($value) || str_contains((string) $value, '.')
            ? (float) $value
            : (int) $value;
    }

    public function headlineLabel(): string
    {
        foreach ($this->fields ?? [] as $field) {
            if (($field['key'] ?? null) === $this->headline_key) {
                return (string) ($field['label'] ?? $this->headline_key);
            }
        }

        return $this->headline_key;
    }

    public function headlineValue(): int|float|null
    {
        return $this->figure($this->headline_key);
    }

    /**
     * A figure the way a scoreboard prints it: thousands grouped, trailing zeros off.
     */
    public static function format(int|float|null $value): string
    {
        if ($value === null) {
            return '—';
        }

        return rtrim(rtrim(number_format((float) $value, 3, '.', ','), '0'), '.');
    }
}
