<?php

namespace App\Support\Tournament;

use App\Models\PointRule;

/**
 * Whether a fixture has Star of the Match cards a screenshot could fill in, and what
 * those cards are.
 *
 * The one gate the award reader hangs off, the way ScoreSheetFields is the gate for
 * the results table: the control on the score screen and the endpoint's refusal both
 * come from this answer, so there is no way for one to be drawn while the other
 * refuses.
 *
 * Two conditions, and neither of them is a name. The fixture has to be a battle
 * royale lobby, because that is the screen the reader has been taught. And the
 * profile has to actually hand out awards — a profile with none is not a profile
 * with empty cards, it is a profile where this screen does not exist.
 */
class MatchAwardSheet
{
    /**
     * @param  array<int, array<string, mixed>>  $awards  as the profile defines them
     */
    private function __construct(public readonly array $awards)
    {
    }

    public static function forRule(?PointRule $rule): ?self
    {
        if ($rule?->kind !== PointRule::KIND_BATTLE_ROYALE) {
            return null;
        }

        $awards = $rule->matchAwards();

        return $awards === [] ? null : new self($awards);
    }

    /**
     * What the reader is told about the awards: the keys to answer with and the names
     * printed on the cards it is matching them against.
     *
     * Deliberately not the whole definition. The headline field is a property of how
     * the card is drawn on the website and has nothing to do with reading an image.
     *
     * @return array<int, array{key: string, label: string, fields: array<int, array{key: string, label: string, decimal: bool}>}>
     */
    public function definitions(): array
    {
        return array_map(fn (array $award) => [
            'key' => (string) $award['key'],
            'label' => (string) ($award['label'] ?? $award['key']),
            'fields' => array_map(fn (array $field) => [
                'key' => (string) $field['key'],
                'label' => (string) ($field['label'] ?? $field['key']),
                'decimal' => (bool) ($field['decimal'] ?? false),
            ], array_values($award['fields'] ?? [])),
        ], $this->awards);
    }
}
