<?php

namespace App\Support\Tournament;

use App\Models\PointRule;

/**
 * Which three inputs on the score form a results table fills in.
 *
 * Read off the profile rather than named here, the same way the form itself is:
 * nothing in this file knows the word "placement" means PUBG. A placement is the
 * one figure no two competitors in a fixture may share, a chicken dinner is the one
 * mark only one of them may hold, and kills is the countable that is neither of
 * those and is not measured against the squad size — the head count, which is not
 * on the image.
 *
 * Null from forRule() means this fixture has no results table to read, and it is
 * the one gate the whole feature hangs off: the button on the score screen, and the
 * endpoint's refusal, both come from this answer.
 */
class ScoreSheetFields
{
    /**
     * @param  array{key: string, label: string}  $placement
     * @param  array{key: string, label: string}  $kills
     * @param  array{key: string, label: string}|null  $winner
     */
    private function __construct(
        public readonly array $placement,
        public readonly array $kills,
        public readonly ?array $winner,
    ) {
    }

    public static function forRule(?PointRule $rule): ?self
    {
        /*
         | Battle royale only, for now.
         |
         | What the reader has been taught is one shape of table: a lobby of squads
         | with a finishing position and a kill count each. A bracket, a race and a
         | judged panel are different screens with different columns, and offering
         | this on them would be offering to read something nobody has checked it
         | can read.
         |
         | On the kind column and never on the profile's name. The seeded profiles
         | are examples — every value on them is editable on screen, and nothing in
         | this application reads a profile by name. A new battle royale profile, or
         | a renamed one, must work here on the day it is made.
         */
        if ($rule?->kind !== PointRule::KIND_BATTLE_ROYALE) {
            return null;
        }

        $inputs = collect($rule->inputs ?? [])->filter(fn ($input) => is_array($input));

        $placement = $inputs->first(fn (array $input) => ! empty($input['unique_in_match']));

        $kills = $inputs->first(fn (array $input) => ($input['type'] ?? null) === 'integer'
            && empty($input['unique_in_match'])
            && ! array_key_exists('max_from', $input));

        if ($placement === null || $kills === null) {
            return null;
        }

        $winner = $inputs->first(fn (array $input) => ($input['type'] ?? null) === 'toggle'
            && ! empty($input['single_in_match']));

        return new self(
            self::field($placement),
            self::field($kills),
            $winner === null ? null : self::field($winner),
        );
    }

    /**
     * @return array{placement: array{key: string, label: string}, kills: array{key: string, label: string}, winner: array{key: string, label: string}|null}
     */
    public function toArray(): array
    {
        return [
            'placement' => $this->placement,
            'kills' => $this->kills,
            'winner' => $this->winner,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{key: string, label: string}
     */
    private static function field(array $input): array
    {
        $key = (string) $input['key'];

        return ['key' => $key, 'label' => (string) ($input['label'] ?? $key)];
    }
}
