<?php

namespace App\Services\Vision;

use App\Contracts\ScoreSheetReader;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
 * at that; asking for the characters lets the repair happen here, where a value
 * that is still not a number after it can be refused rather than invented.
 *
 * Reference: https://ai.google.dev/api/generate-content
 */
class GeminiScoreSheetReader implements ScoreSheetReader
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /**
     * A table of twenty rows off a phone photograph is not a fast call, and the
     * operator is sitting in front of it. Long enough to finish, short enough that
     * a stalled call gives up while they are still watching.
     */
    private const TIMEOUT_SECONDS = 45;

    private const CONNECT_TIMEOUT_SECONDS = 10;

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

    public function read(array $images, array $names): ScoreSheetReading
    {
        $key = trim((string) config('services.gemini.key'));
        $model = trim((string) config('services.gemini.model'));

        if ($key === '') {
            throw ScoreSheetException::notConfigured();
        }

        if ($model === '') {
            throw ScoreSheetException::noModel();
        }

        $parts = [['text' => $this->prompt($names)]];

        foreach ($images as $image) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $image->mime,
                    'data' => base64_encode($image->bytes),
                ],
            ];
        }

        try {
            $response = Http::withHeaders([
                // In the header, never the query string: a key on the URL is a key
                // in every access log between here and Google.
                'X-goog-api-key' => $key,
            ])
                ->acceptJson()
                ->asJson()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(sprintf(self::ENDPOINT, $model), [
                    'contents' => [['parts' => $parts]],
                    'generationConfig' => [
                        // Copying a table is not creative work. The same image must
                        // read the same way twice.
                        'temperature' => 0,
                        'responseMimeType' => 'application/json',
                        'responseSchema' => self::SCHEMA,
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new ScoreSheetException(
                'Gemini could not be reached: ' . $e->getMessage(),
                'The screenshot reader did not answer in time. Try again, or type the figures in.',
                $e,
            );
        }

        if ($response->failed()) {
            throw $this->refusal($response, $model);
        }

        return $this->parse($response->json());
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
     * @param  mixed  $body
     */
    private function parse($body): ScoreSheetReading
    {
        /*
         | Every text part joined, not just the first. A model that returns its
         | answer in two parts is within its rights, and reading parts.0 alone would
         | silently drop half a table.
         */
        $text = collect(data_get($body, 'candidates.0.content.parts', []))
            ->pluck('text')
            ->filter(fn ($part) => is_string($part))
            ->implode('');

        $decoded = json_decode($text, true);

        if (! is_array($decoded) || ! is_array($decoded['rows'] ?? null)) {
            Log::warning('Gemini answered a score sheet read in an unexpected shape.', [
                'finish_reason' => data_get($body, 'candidates.0.finishReason'),
                'length' => mb_strlen($text),
            ]);

            throw new ScoreSheetException(
                'Gemini did not answer with the rows that were asked for.',
                'The screenshot reader gave an answer that could not be used. Try a clearer image, or type the figures in.',
            );
        }

        $rows = [];
        $rejected = [];

        foreach ($decoded['rows'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['team'] ?? ''));

            // The blank or dashed row every one of these tables seems to carry. It
            // is a spacer, not a team.
            if ($name === '' || preg_match('/^[-–—_.]+$/u', $name) === 1) {
                continue;
            }

            $placement = $this->wholeNumber((string) ($row['rank'] ?? ''));
            $kills = $this->wholeNumber((string) ($row['kp'] ?? ''));

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

    /**
     * A numeric cell, with the font's capital I read back as the 1 it is.
     *
     * Only in a column that is declared numeric, and only for the glyphs that are
     * actually confused: I, l and a bare pipe. Anything still not a number is
     * refused, because a rank nobody can read is worse filled in than left out.
     */
    private function wholeNumber(string $raw): ?int
    {
        $value = preg_replace('/\s+/u', '', strtr($raw, [
            'I' => '1',
            'i' => '1',
            'l' => '1',
            '|' => '1',
        ]));

        return preg_match('/^\d{1,4}$/', (string) $value) === 1 ? (int) $value : null;
    }

    /**
     * Gemini refused the request, and the operator needs to know which of the two
     * settings to go and look at.
     */
    private function refusal(\Illuminate\Http\Client\Response $response, string $model): ScoreSheetException
    {
        $reason = (string) ($response->json('error.message') ?: $response->body());

        // Logged rather than shown: the body echoes back the endpoint and can quote
        // part of the request.
        Log::warning('Gemini refused a score sheet read.', [
            'status' => $response->status(),
            'model' => $model,
            'body' => mb_substr($reason, 0, 1000),
        ]);

        $public = match (true) {
            $response->status() === 404 || str_contains(strtolower($reason), 'not found') => sprintf(
                'The screenshot reader does not have a model called "%s". Set GEMINI_MODEL on the server to one this key may use.',
                $model,
            ),
            in_array($response->status(), [401, 403], true) || str_contains(strtolower($reason), 'api key') =>
                'The screenshot reader refused the API key. Check GEMINI_API_KEY on the server.',
            $response->status() === 429 =>
                'The screenshot reader is over its quota for now. Wait a moment, or type the figures in.',
            default => 'The screenshot reader refused the request. Type the figures in instead.',
        };

        return new ScoreSheetException(
            sprintf('Gemini returned HTTP %d: %s', $response->status(), mb_substr($reason, 0, 300)),
            $public,
        );
    }
}
