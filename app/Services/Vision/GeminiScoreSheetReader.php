<?php

namespace App\Services\Vision;

use App\Contracts\ScoreSheetReader;

/**
 * Google's Gemini, reading a results table.
 *
 * Asked for JSON against a declared schema rather than for prose, so nothing here
 * parses sentences: a model that answers in a shape we did not ask for fails at the
 * decode instead of being half understood.
 *
 * Every figure comes back as a STRING on purpose. The tables these tournaments
 * produce are printed in a font that draws 1 as a serif capital I, so the RANK
 * column arrives as I6, II or I0. Asking for an integer would have the model guess
 * at that; asking for the characters lets the repair happen in VisionNumber, where a
 * value that is still not a number after it can be refused rather than invented.
 *
 * The call itself belongs to GeminiClient, which both readers share.
 */
class GeminiScoreSheetReader implements ScoreSheetReader
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
            'rows' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'team' => ['type' => 'STRING'],
                        'rank' => ['type' => 'STRING'],
                        'kp' => ['type' => 'STRING'],
                    ],
                    'required' => ['team', 'rank', 'kp'],
                    'propertyOrdering' => ['team', 'rank', 'kp'],
                ],
            ],
        ],
        'required' => ['rows'],
    ];

    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    public function read(array $images, array $names): ScoreSheetReading
    {
        return $this->parse($this->gemini->ask($this->prompt($names), $images, self::SCHEMA));
    }

    /* ---------------------------------------------------------------------
     | What is asked for
     * ------------------------------------------------------------------ */

    /**
     * @param  array<int, string>  $names
     */
    private function prompt(array $names): string
    {
        $lines = [
            'The attached image or images are parts of ONE results table from a PUBG-style battle royale tournament: a list of squads with a finishing position and a kill count each.',
            'If what you are given is not a table of that kind, return an empty list of rows. Do not try to make one out of something else.',
            'Read the results table in the attached image or images. They are parts of ONE table.',
            'Return one row per printed team row, in the order they are printed, with: team (the name exactly as printed), rank (the RANK column) and kp (the KP column).',
            'Ignore every other column. PP is placement points, it is worked out elsewhere, and it is not wanted.',
            'RANK and KP are whole numbers. This table is printed in a font that draws the digit 1 as a serif capital I, so I6 is 16, II is 11, I0 is 10 and I2 is 12. Return the characters you see in those cells; do not round, total or correct anything else.',
            'Include a row whose team cell is blank or a dash, exactly as it appears. Do not invent a row the table does not have, and do not leave one out.',
        ];

        if ($names !== []) {
            $lines[] = 'The competitors in this fixture are: ' . implode(', ', $names)
                . '. Where a printed name is plainly one of these, spell it the way this list spells it.';
        }

        return implode("\n", $lines);
    }

    /* ---------------------------------------------------------------------
     | What comes back
     * ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $answer
     */
    private function parse(array $answer): ScoreSheetReading
    {
        if (! is_array($answer['rows'] ?? null)) {
            throw ScoreSheetException::unusableAnswer();
        }

        $rows = [];
        $rejected = [];

        foreach ($answer['rows'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['team'] ?? ''));

            // The blank or dashed row every one of these tables seems to carry. It
            // is a spacer, not a team.
            if ($name === '' || preg_match('/^[-–—_.]+$/u', $name) === 1) {
                continue;
            }

            $placement = VisionNumber::whole((string) ($row['rank'] ?? ''));
            $kills = VisionNumber::whole((string) ($row['kp'] ?? ''));

            if ($placement === null || $kills === null) {
                $rejected[] = [
                    'name' => $name,
                    'reason' => sprintf(
                        'read as rank "%s" and kills "%s", which is not a pair of numbers',
                        trim((string) ($row['rank'] ?? '')),
                        trim((string) ($row['kp'] ?? '')),
                    ),
                ];

                continue;
            }

            $rows[] = new ScoreSheetRow($name, $placement, $kills);
        }

        return new ScoreSheetReading($rows, $rejected);
    }
}
