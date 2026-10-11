<?php

namespace App\Support\Tournament;

use App\Services\Vision\MatchAwardCard;

/**
 * Ties the cards read off a Star of the Match screen to the awards a profile hands
 * out and the players in one fixture.
 *
 * Knows nothing about Eloquent or about who read the image, the same way
 * ScoreSheetMatcher does not, which is what lets the real-world spellings be
 * exercised without a database or a network call.
 *
 * WHAT IT WILL NOT DO. It will not pick a player for you. The nickname is the only
 * identity on that screen — no real name, no account number — and OCR of this font
 * genuinely differs by a character or two between two screenshots of the same match:
 * the owner's own pair read QXCikaYELL and QXCikoYELL for one player. So a nickname
 * that resembles nothing, or resembles two players equally well, comes back with no
 * player and the text that was read, for somebody to settle.
 *
 * Who a nickname belongs to is PlayerNicknameMatcher's answer, shared with the
 * per-player result screen: both read the same handles off the same game in the same
 * font, and one opinion about who QXCikoYELL is beats two.
 */
class MatchAwardMatcher
{
    private readonly PlayerNicknameMatcher $names;

    /**
     * @param  array<int, array{id: int, entrant_id: int, name: string, ign: string}>  $candidates
     *         everybody who could be named in this fixture
     */
    public function __construct(array $candidates)
    {
        $this->names = new PlayerNicknameMatcher($candidates);
    }

    /**
     * @param  array<int, MatchAwardCard>  $cards
     * @param  array<int, array{key: string, label: string, fields: array<int, array<string, mixed>>}>  $awards
     * @return array{
     *     awards: array<int, array<string, mixed>>,
     *     uncovered: array<int, array{key: string, label: string}>,
     *     unknown: array<int, array{label: string, player: string, reason: string}>,
     * }
     */
    public function match(array $cards, array $awards): array
    {
        $labels = [];
        $fields = [];

        foreach ($awards as $award) {
            $key = (string) $award['key'];
            $labels[$key] = (string) ($award['label'] ?? $award['key']);

            // Sent on to the browser so the review panel can name a figure. The
            // dialog it is about to fill in has those labels printed on it, and a
            // panel that said "elims 7" where the form says "Eliminations" would be
            // a panel nobody could check against the image.
            $fields[$key] = array_map(fn (array $field) => [
                'key' => (string) $field['key'],
                'label' => (string) ($field['label'] ?? $field['key']),
            ], array_values($award['fields'] ?? []));
        }

        $matched = [];
        $unknown = [];

        foreach ($cards as $card) {
            if (! isset($labels[$card->award])) {
                continue;
            }

            /*
             | The same award on two cards. One screen cannot hand the same award out
             | twice, so the first reading stands and the second is reported: which of
             | two cards is the real one is not a decision to take on somebody's
             | behalf, and overwriting silently would hide that it happened.
             */
            if (isset($matched[$card->award])) {
                $unknown[] = [
                    'label' => $labels[$card->award],
                    'player' => $card->player,
                    'reason' => sprintf('%s was read twice, so this card was left out', $labels[$card->award]),
                ];

                continue;
            }

            [$player, $confidence, $reason] = $this->names->best($card->player);

            $matched[$card->award] = [
                'key' => $card->award,
                'label' => $labels[$card->award],
                'fields' => $fields[$card->award],

                // Null is the honest answer and the dialog shows it as one. The
                // figures still come through, so an operator who recognises the
                // nickname picks the player and keeps what was read.
                'player' => $player,

                'read_as' => $card->player,
                'figures' => $card->figures,
                'unreadable' => $card->unreadable,
                'confidence' => $confidence,
                'low_confidence' => $player !== null && $confidence < ScoreSheetMatcher::REVIEW_BELOW,
                'reason' => $reason,
            ];
        }

        $uncovered = [];

        foreach ($awards as $award) {
            $key = (string) $award['key'];

            if (! isset($matched[$key])) {
                $uncovered[] = ['key' => $key, 'label' => $labels[$key]];
            }
        }

        return [
            // Profile order, not the order the cards were printed in, so the review
            // panel reads down the dialog it is about to fill in.
            'awards' => array_values(array_map(
                fn (array $award) => $matched[(string) $award['key']],
                array_values(array_filter($awards, fn (array $award) => isset($matched[(string) $award['key']]))),
            )),
            'uncovered' => $uncovered,
            'unknown' => $unknown,
        ];
    }

}
