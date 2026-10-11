<?php

namespace App\Services\Vision;

use App\Contracts\MatchAwardReader;

/**
 * Google's Gemini, reading the game's Star of the Match screen.
 *
 * The screen shows a few cards side by side. Each one has a nickname at the top, the
 * name of the award, a subtitle and then a short list of stat rows.
 *
 * THREE THINGS THAT SCREEN DOES, which the prompt and the parsing below exist for.
 *
 * The subtitle is not a figure. Two cards read "Elims 7" and "Rescue 2", and the
 * third reads "Final Elimination" with no number in it at all. So the figures are
 * taken from the stat rows and the subtitle is ignored, rather than being treated as
 * a number that happens to be missing sometimes.
 *
 * Every card shows a different set of stats. There is no fixed list to ask for, and
 * assuming one would mean inventing a damage figure for the card that never showed
 * damage. What is asked for instead is the profile's own field names per award, and
 * what the card did not show is left blank.
 *
 * And the award names are not ours. They come off the scoring profile an operator
 * typed, so they are sent with the image and the reader is asked to put each card
 * against one of them. A card that matches none is handed back as it was read.
 */
class GeminiMatchAwardReader implements MatchAwardReader
{
    /**
     * The shape the answer has to arrive in.
     *
     * Values are STRINGS for the same reason the results table asks for strings: the
     * figures are grouped with commas on this screen and the font draws 1 as a serif
     * capital I, and both are repaired in VisionNumber where a value that is still
     * not a number can be refused instead of guessed at.
     *
     * Figures are a LIST of field-and-value pairs rather than an object, because the
     * field names belong to the profile and a schema cannot declare properties it
     * will only learn at the time of asking.
     */
    private const SCHEMA = [
        'type' => 'OBJECT',
        'properties' => [
            'cards' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'award' => ['type' => 'STRING'],
                        'label' => ['type' => 'STRING'],
                        'player' => ['type' => 'STRING'],
                        'figures' => [
                            'type' => 'ARRAY',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'field' => ['type' => 'STRING'],
                                    'value' => ['type' => 'STRING'],
                                ],
                                'required' => ['field', 'value'],
                                'propertyOrdering' => ['field', 'value'],
                            ],
                        ],
                    ],
                    'required' => ['award', 'label', 'player', 'figures'],
                    'propertyOrdering' => ['award', 'label', 'player', 'figures'],
                ],
            ],
        ],
        'required' => ['cards'],
    ];

    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    public function read(array $images, array $awards, array $nicknames): MatchAwardReading
    {
        if ($awards === []) {
            throw ScoreSheetException::noAwards();
        }

        return $this->parse(
            $this->gemini->ask($this->prompt($awards, $nicknames), $images, self::SCHEMA),
            $awards,
        );
    }

    /* ---------------------------------------------------------------------
     | What is asked for
     * ------------------------------------------------------------------ */

    /**
     * @param  array<int, array<string, mixed>>  $awards
     * @param  array<int, string>  $nicknames
     */
    private function prompt(array $awards, array $nicknames): string
    {
        $lines = [
            'The attached image is the "Star of the Match" screen from a PUBG match: a row of cards, each with a player nickname at the top, the name of an award, a subtitle, and then a short list of stat rows.',
            'If the image is not that screen, return an empty list of cards. Do not try to make one out of something else.',
            'Return one entry per card printed on the screen, in the order they are printed.',
            'award: the key from the list below whose name matches the award printed on the card. If no name in the list matches it, return an empty string for award.',
            'label: the award name exactly as printed on the card.',
            'player: the player nickname printed at the top of the card, exactly as printed. It is the only name on the card; there is no real name and no account number.',
            'figures: one entry per STAT ROW on the card, using the field key from the list below whose name matches the row. Skip a stat row that matches none of that award\'s fields, and skip a field the card does not show. Do not repeat a field.',
            'Take every figure from the stat rows ONLY. The subtitle under the award name is not a stat row: it is sometimes a figure such as "Elims 7" and sometimes words such as "Final Elimination", so it is not to be read as a value for anything.',
            'value: the characters printed for that stat, exactly as printed, including any comma such as 9,617. Do not round, total, convert or correct a figure, and do not work one out from another.',
            'The awards this tournament hands out, with the fields each card may carry:',
        ];

        foreach ($awards as $award) {
            $fields = [];

            foreach ($award['fields'] ?? [] as $field) {
                $fields[] = sprintf('%s = "%s"', $field['key'], $field['label'] ?? $field['key']);
            }

            $lines[] = sprintf(
                '- key "%s", named "%s", fields: %s',
                $award['key'],
                $award['label'] ?? $award['key'],
                implode('; ', $fields),
            );
        }

        if ($nicknames !== []) {
            $lines[] = 'The in-game names of the players in this match are: ' . implode(', ', $nicknames)
                . '. Where a printed nickname is plainly one of these, spell it the way this list spells it. If it is not one of these, return what is printed.';
        }

        return implode("\n", $lines);
    }

    /* ---------------------------------------------------------------------
     | What comes back
     * ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $answer
     * @param  array<int, array<string, mixed>>  $awards
     */
    private function parse(array $answer, array $awards): MatchAwardReading
    {
        if (! is_array($answer['cards'] ?? null)) {
            throw ScoreSheetException::unusableAnswer();
        }

        // Keyed so a card is checked against the profile rather than trusted. The
        // award key arrives from a model, and a key that is not in this list is a key
        // this fixture has nothing to do with.
        $defined = [];

        foreach ($awards as $award) {
            $defined[(string) $award['key']] = $award;
        }

        $cards = [];
        $unknown = [];

        foreach ($answer['cards'] as $card) {
            if (! is_array($card)) {
                continue;
            }

            $player = trim(preg_replace('/\s+/u', ' ', (string) ($card['player'] ?? '')));
            $label = trim((string) ($card['label'] ?? ''));
            $key = trim((string) ($card['award'] ?? ''));

            if ($player === '' && $label === '') {
                continue;
            }

            if (! isset($defined[$key])) {
                $unknown[] = [
                    'label' => $label !== '' ? $label : 'an award with no name on it',
                    'player' => $player,
                    'reason' => 'this scoring profile hands out no award by that name',
                ];

                continue;
            }

            [$figures, $unreadable] = $this->figures($card['figures'] ?? [], $defined[$key]);

            $cards[] = new MatchAwardCard($key, $label, $player, $figures, $unreadable);
        }

        return new MatchAwardReading($cards, $unknown);
    }

    /**
     * The stat rows off one card, against the fields its award actually carries.
     *
     * A stat the award was not configured for is dropped without a word: the screen
     * shows four rows and a profile may ask for two of them, and the two it did not
     * ask for have nowhere on the form to go.
     *
     * @param  mixed  $rows
     * @param  array<string, mixed>  $award
     * @return array{0: array<string, int|float>, 1: array<int, string>}
     */
    private function figures($rows, array $award): array
    {
        $decimal = [];

        foreach ($award['fields'] ?? [] as $field) {
            $decimal[(string) $field['key']] = (bool) ($field['decimal'] ?? false);
        }

        $figures = [];
        $unreadable = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $field = trim((string) ($row['field'] ?? ''));

            if (! array_key_exists($field, $decimal) || array_key_exists($field, $figures)) {
                continue;
            }

            $value = VisionNumber::figure((string) ($row['value'] ?? ''), $decimal[$field]);

            if ($value === null) {
                $unreadable[] = $field;

                continue;
            }

            $figures[$field] = $value;
        }

        return [$figures, $unreadable];
    }
}
