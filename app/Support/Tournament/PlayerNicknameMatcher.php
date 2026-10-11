<?php

namespace App\Support\Tournament;

/**
 * One in-game nickname read off a game screen, turned into a player in this fixture.
 *
 * Lifted out of MatchAwardMatcher when the per-player result screen arrived and
 * asked the same question. It is the same question: a handle printed in a font that
 * loses a character, against the handles on the entry list. Two copies of it would
 * mean two opinions about who QXCikoYELL is, and the two screens would fill the
 * same form in differently.
 *
 * WHAT IT WILL NOT DO. It will not pick for you. The nickname is the only identity
 * on either of those screens — no real name, no account id — and OCR of this font
 * genuinely differs by a character or two between two screenshots of one match: the
 * owner's own pair read QXCikaYELL and QXCikoYELL. So a handle that resembles
 * nothing, or two players equally well, comes back as nobody with the reason why.
 *
 * ON THE DECORATIVE CHARACTERS. These handles carry CJK and ornaments: FrezRoz男生,
 * 『DV』INUII, Z1✕LARA, D2WGximie丶影. ScoreSheetMatcher::normalise strips all of it
 * before comparing, and it strips it from both sides, so the entry list and the
 * screen meet on what they have in common. A handle that is nothing but ornament
 * normalises to nothing and comes back as nobody, which is the honest answer.
 */
class PlayerNicknameMatcher
{
    /**
     * @param  array<int, array{id: int, entrant_id: int, name: string, ign: string}>  $candidates
     *         everybody who could be named in this fixture
     */
    public function __construct(private readonly array $candidates)
    {
    }

    /**
     * The best player for one nickname, or null with the reason why there is none.
     *
     * @return array{0: array{id: int, entrant_id: int, name: string, ign: string}|null, 1: float, 2: string}
     */
    public function best(string $raw): array
    {
        $needle = ScoreSheetMatcher::normalise($raw);

        if ($needle === '') {
            return [null, 0.0, 'there was nothing in it that could be read as an in-game name'];
        }

        $scores = [];

        foreach ($this->candidates as $candidate) {
            $scores[] = ['candidate' => $candidate, 'score' => $this->score($needle, $candidate)];
        }

        usort($scores, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        $top = $scores[0] ?? null;

        if ($top === null || $top['score'] < ScoreSheetMatcher::MATCH_FLOOR) {
            return [null, $top['score'] ?? 0.0, 'no player in this fixture goes by that in-game name'];
        }

        // A tie at the top is the one case worth refusing outright. Two players who
        // chose nearly the same handle is exactly how the wrong one gets the figure.
        if (isset($scores[1]) && abs($scores[1]['score'] - $top['score']) < 0.0001) {
            return [null, $top['score'], 'two players in this fixture go by that in-game name equally well'];
        }

        return [$top['candidate'], $top['score'], ''];
    }

    /**
     * How well one printed handle fits one player.
     *
     * The in-game name is what is printed on these screens, so it is what is
     * compared. The legal name is tried too and never beats a better in-game match:
     * it is there for the player whose entry carries no handle at all, who would
     * otherwise be unreachable from a screenshot.
     *
     * @param  array{id: int, entrant_id: int, name: string, ign: string}  $candidate
     */
    private function score(string $needle, array $candidate): float
    {
        $best = 0.0;

        foreach ([$candidate['ign'] ?? '', $candidate['name'] ?? ''] as $against) {
            $compare = ScoreSheetMatcher::normalise((string) $against);

            if ($compare === '') {
                continue;
            }

            if ($compare === $needle) {
                return 1.0;
            }

            similar_text($needle, $compare, $percent);
            $best = max($best, round($percent / 100, 4));
        }

        return $best;
    }
}
