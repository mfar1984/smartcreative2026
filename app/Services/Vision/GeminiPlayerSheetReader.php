<?php

namespace App\Services\Vision;

use App\Contracts\PlayerSheetReader;

/**
 * Google's Gemini, reading the game's per-player result screen.
 *
 * A dark list where each squad is a block: a rank number, then its players, each
 * with their own elimination count. Four answers come off it — the block's rank is
 * the squad's placement, its eliminations added up are the squad's kills, each line
 * is one player's figure, and rank 1 took the chicken dinner.
 *
 * THREE THINGS THAT SCREEN DOES, which the prompt below exists for.
 *
 * It names no teams. Not one. Only handles with clan prefixes, and the prefixes
 * disagree with each other — one squad prints D2W and another D2WGx — so the reader
 * is never asked for a team name and is told not to invent one. Who the block
 * belongs to is settled afterwards, by its players.
 *
 * It shows about three blocks at a time, and a fixed panel for ranks 1 and 2 is
 * printed on EVERY screenshot. So several images are expected and the repeat is
 * expected with them: the reader returns each block as printed and the duplicates
 * are merged in PlayerSheetMatcher, where two readings that disagree can be
 * reported instead of one of them quietly winning.
 *
 * And the column is not called what we would call it. The figure has to land on a
 * field off a scoring profile somebody typed — the owner's live profile declares
 * nine of them — so the field names are sent with the image and the reader answers
 * with the key the elimination column belongs to. That key is checked against the
 * profile here, because a key we were not offered is a key with nowhere to go.
 *
 * Every figure comes back as a STRING, as in both other readers: this is the font
 * that draws 1 as a serif capital I, and the repair belongs in VisionNumber where a
 * value that is still not a number can be refused rather than invented.
 */
class GeminiPlayerSheetReader implements PlayerSheetReader
{
    /**
     * The shape the answer has to arrive in.
     *
     * Uppercase type names: this is the OpenAPI subset Gemini declares, and the
     * lowercase spelling is refused by the enum.
     */
    private const SCHEMA = [
        'type' => 'OBJECT',
        'properties' => [
            'field' => ['type' => 'STRING'],
            'blocks' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'rank' => ['type' => 'STRING'],
                        'players' => [
                            'type' => 'ARRAY',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'name' => ['type' => 'STRING'],
                                    'eliminations' => ['type' => 'STRING'],
                                ],
                                'required' => ['name', 'eliminations'],
                                'propertyOrdering' => ['name', 'eliminations'],
                            ],
                        ],
                    ],
                    'required' => ['rank', 'players'],
                    'propertyOrdering' => ['rank', 'players'],
                ],
            ],
        ],
        'required' => ['field', 'blocks'],
    ];

    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    public function read(array $images, array $fields, array $nicknames): PlayerSheetReading
    {
        if ($fields === []) {
            throw ScoreSheetException::noPlayerFields();
        }

        return $this->parse($this->gemini->ask($this->prompt($fields, $nicknames), $images, self::SCHEMA), $fields);
    }

    /* ---------------------------------------------------------------------
     | What is asked for
     * ------------------------------------------------------------------ */

    /**
     * @param  array<int, array{key: string, label: string}>  $fields
     * @param  array<int, string>  $nicknames
     */
    private function prompt(array $fields, array $nicknames): string
    {
        $lines = [
            'The attached image or images are the per-player result screen from a PUBG Mobile match: a list where each TEAM is a block carrying a rank number, and the block lists that team\'s players with an elimination count each.',
            'If what you are given is not that screen, return an empty list of blocks and an empty field. Do not try to make one out of something else. In particular this is NOT the spreadsheet-style results table of teams with RANK, KP and PP columns, and it is NOT the Star of the Match screen.',
            'Return one entry per TEAM BLOCK printed, in the order they are printed, with: rank (the number printed for that block) and players (one entry per player line in that block).',
            'name: the player nickname exactly as printed, including any clan prefix and any decorative character. Keep the characters as they are; do not transliterate them and do not tidy them up.',
            'eliminations: the characters printed as that player\'s elimination count, exactly as printed. A line reading "5 eliminations" is 5 and a line reading "1 elimination" is 1.',
            'There are NO TEAM NAMES on this screen. Do not return one, do not guess one from a clan prefix, and do not leave a block out because it has no name.',
            'rank and eliminations are whole numbers. This screen is drawn in a font that draws the digit 1 as a serif capital I, so I6 is 16, II is 11 and I0 is 10. Return the characters you see; do not round, total or correct anything.',
            'These screenshots overlap: a panel for the top ranks is printed on every one of them. Return every block exactly as it appears on each image, including a block you have already returned from another image. Do not merge them and do not leave a repeat out.',
            'field: the key from the list below whose name means the elimination or kill count for one player. If no name in that list means that, return an empty string.',
            'The personal figures this tournament keeps:',
        ];

        foreach ($fields as $field) {
            $lines[] = sprintf('- key "%s", named "%s"', $field['key'], $field['label'] ?? $field['key']);
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
     * @param  array<int, array{key: string, label: string}>  $fields
     */
    private function parse(array $answer, array $fields): PlayerSheetReading
    {
        if (! is_array($answer['blocks'] ?? null)) {
            throw ScoreSheetException::unusableAnswer();
        }

        [$blocks, $rejected] = $this->blocks($answer['blocks']);

        /*
         | Nothing was read, so there is nothing to put anywhere. Answered as an empty
         | reading rather than as a missing field: this is somebody uploading the wrong
         | screenshot, and telling them to go and fix their scoring profile would send
         | them to the wrong place.
         */
        if ($blocks === [] && $rejected === []) {
            return new PlayerSheetReading([], '');
        }

        $key = trim((string) ($answer['field'] ?? ''));

        // Checked against the profile rather than trusted. The key arrives from a
        // model, and one that is not on this profile is a field with nowhere to go.
        if (! in_array($key, array_map(fn (array $field) => (string) $field['key'], $fields), true)) {
            throw ScoreSheetException::noEliminationField();
        }

        return new PlayerSheetReading($blocks, $key, $rejected);
    }

    /**
     * The blocks that read, and the blocks that did not.
     *
     * A block is taken WHOLE or not at all. One player line that is not a number
     * would make the squad's kill total wrong — quietly low, which is the worst way
     * for it to be wrong — so the block is reported with its printed text and the
     * operator reads it off the image.
     *
     * @param  array<int, mixed>  $rows
     * @return array{0: array<int, PlayerSheetBlock>, 1: array<int, array{read_as: string, reason: string}>}
     */
    private function blocks(array $rows): array
    {
        $blocks = [];
        $rejected = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $printed = trim((string) ($row['rank'] ?? ''));
            $rank = VisionNumber::whole($printed);
            [$players, $unreadable] = $this->players($row['players'] ?? []);

            if ($players === [] && $unreadable === []) {
                continue;
            }

            if ($rank === null) {
                $rejected[] = [
                    'read_as' => $this->printed($printed, $players, $unreadable),
                    'reason' => sprintf('its rank was read as "%s", which is not a number', $printed),
                ];

                continue;
            }

            if ($unreadable !== []) {
                $rejected[] = [
                    'read_as' => $this->printed($printed, $players, $unreadable),
                    'reason' => sprintf(
                        'the eliminations for %s could not be read, so this squad\'s total would be wrong',
                        implode(', ', $unreadable),
                    ),
                ];

                continue;
            }

            if ($players === []) {
                $rejected[] = [
                    'read_as' => $this->printed($printed, $players, $unreadable),
                    'reason' => 'no player was printed in it, so there is nothing to tell the squad by',
                ];

                continue;
            }

            $blocks[] = new PlayerSheetBlock($rank, $players);
        }

        return [$blocks, $rejected];
    }

    /**
     * The player lines off one block.
     *
     * @param  mixed  $rows
     * @return array{0: array<int, PlayerSheetTally>, 1: array<int, string>}
     */
    private function players($rows): array
    {
        $players = [];
        $unreadable = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            // Collapsed, never stripped of its own characters: these handles carry CJK
            // and decorative marks, and they are what the matching compares.
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($row['name'] ?? '')));

            if ($name === '') {
                continue;
            }

            $value = VisionNumber::whole((string) ($row['eliminations'] ?? ''));

            if ($value === null) {
                $unreadable[] = $name;

                continue;
            }

            $players[] = new PlayerSheetTally($name, $value);
        }

        return [$players, $unreadable];
    }

    /**
     * One rejected block as it was printed, so the operator can find it on the image.
     *
     * @param  array<int, PlayerSheetTally>  $players
     * @param  array<int, string>  $unreadable
     */
    private function printed(string $rank, array $players, array $unreadable): string
    {
        $names = array_merge(
            array_map(fn (PlayerSheetTally $player) => $player->name . ' ' . $player->eliminations, $players),
            $unreadable,
        );

        return sprintf('#%s — %s', $rank === '' ? '?' : $rank, implode(', ', $names));
    }
}
