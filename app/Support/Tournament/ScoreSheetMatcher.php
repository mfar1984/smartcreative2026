<?php

namespace App\Support\Tournament;

use App\Services\Vision\ScoreSheetException;
use App\Services\Vision\ScoreSheetRow;
use Illuminate\Support\Str;

/**
 * Ties the rows read off a screenshot to the competitors in one fixture.
 *
 * Deliberately knows nothing about Eloquent or about who read the image. It is
 * handed plain candidates and plain rows, which is what lets the matching be
 * exercised against the real-world spellings without a database or a network call.
 *
 * WHAT IT WILL NOT DO. It will not pick for you. A row that resembles nothing, or
 * resembles two competitors equally well, comes back as unmatched carrying the text
 * that was read, because a score entered against the wrong team is worse than one
 * nobody entered: it moves two teams in the standings and looks correct doing it.
 */
class ScoreSheetMatcher
{
    /** Below this, a name is a different team rather than a misspelling of this one. */
    public const MATCH_FLOOR = 0.78;

    /** Matched, but not so plainly that it should be applied without being looked at. */
    public const REVIEW_BELOW = 0.95;

    /**
     * @param  array<int, array{id: int, name: string, current: array<string, mixed>}>  $candidates
     */
    public function __construct(private readonly array $candidates)
    {
    }

    /**
     * @param  array<int, ScoreSheetRow>  $rows  every row read, from every image, in order
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     unmatched: array<int, array<string, mixed>>,
     *     uncovered: array<int, array{entrant_id: int, name: string}>,
     *     clashes: array<int, array<string, mixed>>,
     * }
     *
     * @throws ScoreSheetException
     */
    public function match(array $rows): array
    {
        // Keyed by entrant, because several images of one table read the same team
        // more than once and the merge happens here rather than in the browser.
        $matched = [];
        $unmatched = [];
        $clashes = [];
        $claimed = [];

        foreach ($rows as $row) {
            [$id, $confidence, $reason] = $this->best($row->name);

            if ($id === null) {
                $unmatched[] = [
                    'read_as' => $row->name,
                    'placement' => $row->placement,
                    'kills' => $row->kills,
                    'reason' => $reason,
                ];

                continue;
            }

            /*
             | The same team read twice, which is exactly what two overlapping
             | photographs of one table produce. The first reading stands. A second
             | that agrees is a duplicate and is dropped without a word; a second
             | that disagrees is reported and the row is flagged, because which of
             | two readings is right is not a decision to take on somebody's behalf.
             */
            if (isset($matched[$id])) {
                $kept = $matched[$id];

                if ($kept['placement'] === $row->placement && $kept['kills'] === $row->kills) {
                    continue;
                }

                $clashes[] = [
                    'name' => $kept['name'],
                    'kept' => ['placement' => $kept['placement'], 'kills' => $kept['kills']],
                    'also_read' => ['placement' => $row->placement, 'kills' => $row->kills],
                ];

                $matched[$id]['conflict'] = true;

                continue;
            }

            /*
             | Two different teams on one position. A table cannot do that, so the
             | image was misread somewhere, and filling in the rows that happen to
             | look fine would be filling in half of something known to be wrong.
             */
            if (isset($claimed[$row->placement])) {
                throw ScoreSheetException::duplicatePlacement(
                    $row->placement,
                    $matched[$claimed[$row->placement]]['read_as'],
                    $row->name,
                );
            }

            $claimed[$row->placement] = $id;
            $candidate = $this->candidate($id);

            $matched[$id] = [
                'entrant_id' => $id,
                'name' => (string) $candidate['name'],
                'read_as' => $row->name,
                'placement' => $row->placement,
                'kills' => $row->kills,

                // Top of the table takes the chicken dinner. Asked for rather than
                // derived everywhere else in this module, so it is proposed here and
                // the operator still presses Save on it.
                'winner' => $row->placement === 1,

                'confidence' => $confidence,
                'low_confidence' => $confidence < self::REVIEW_BELOW,
                'conflict' => false,
                'current' => $candidate['current'] ?? [],
            ];
        }

        $uncovered = [];

        foreach ($this->candidates as $candidate) {
            if (isset($matched[(int) $candidate['id']])) {
                continue;
            }

            $uncovered[] = [
                'entrant_id' => (int) $candidate['id'],
                'name' => (string) $candidate['name'],
            ];
        }

        return [
            'rows' => array_values($matched),
            'unmatched' => $unmatched,
            'uncovered' => $uncovered,
            'clashes' => $clashes,
        ];
    }

    /**
     * Strip a name down to what two spellings of it have in common.
     *
     * The real data differs by case and spacing and nothing else: Mdesa and MDESA,
     * 96k and 96K, v18 and V18. Diacritics go the same way, so a team typed with an
     * accent on the entry form still matches the screenshot that has none.
     */
    public static function normalise(string $value): string
    {
        $value = strtolower(Str::ascii($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);

        return trim(preg_replace('/\s+/', ' ', (string) $value));
    }

    /**
     * The best candidate for one read name, or null with the reason why there is none.
     *
     * @return array{0: int|null, 1: float, 2: string}
     */
    private function best(string $raw): array
    {
        $needle = self::normalise($raw);

        if ($needle === '') {
            return [null, 0.0, 'the team cell held nothing that could be read as a name'];
        }

        $scores = [];

        foreach ($this->candidates as $candidate) {
            $scores[] = [
                'id' => (int) $candidate['id'],
                'score' => self::similarity($needle, self::normalise((string) $candidate['name'])),
            ];
        }

        usort($scores, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        $top = $scores[0] ?? null;

        if ($top === null || $top['score'] < self::MATCH_FLOOR) {
            return [null, $top['score'] ?? 0.0, 'no competitor in this fixture goes by that name'];
        }

        // A tie at the top is the one case worth refusing outright. Two squads
        // called Alpha and Alpha2 are exactly how the wrong one gets the points.
        if (isset($scores[1]) && abs($scores[1]['score'] - $top['score']) < 0.0001) {
            return [null, $top['score'], 'two competitors in this fixture match that name equally well'];
        }

        return [$top['id'], $top['score'], ''];
    }

    private static function similarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        if ($a === '' || $b === '') {
            return 0.0;
        }

        similar_text($a, $b, $percent);

        return round($percent / 100, 4);
    }

    /**
     * @return array{id: int, name: string, current: array<string, mixed>}
     */
    private function candidate(int $id): array
    {
        foreach ($this->candidates as $candidate) {
            if ((int) $candidate['id'] === $id) {
                return $candidate;
            }
        }

        return ['id' => $id, 'name' => 'Competitor', 'current' => []];
    }
}
