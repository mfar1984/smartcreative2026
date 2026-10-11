<?php

namespace Tests\Unit\Tournament;

use App\Services\Vision\MatchAwardCard;
use App\Services\Vision\VisionNumber;
use App\Support\Tournament\MatchAwardMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Tying the cards off a Star of the Match screen to awards and to players.
 *
 * No database and no network here on purpose: this is the part that decides whose
 * name ends up on a card on the website, and it has to be exercisable against the
 * spellings OCR actually produces without either.
 *
 * WHAT THESE PROVE. That the nickname is matched the way the real screenshots differ
 * — a character, some case, some punctuation — and flagged when it is not exact.
 * That a handle nobody recognises is reported rather than given to whoever it
 * resembles most. That two players with the same handle is a refusal and not a coin
 * toss. And that nothing in here reads an award label as a constant.
 */
class MatchAwardMatcherTest extends TestCase
{
    /**
     * @param  array<int, array{0: int, 1: string, 2: string}>  $players  id, in-game name, legal name
     */
    private function matcher(array $players): MatchAwardMatcher
    {
        return new MatchAwardMatcher(array_map(fn (array $player) => [
            'id' => $player[0],
            'entrant_id' => 90 + $player[0],
            'ign' => $player[1],
            'name' => $player[2],
        ], $players));
    }

    /**
     * Two awards, named and keyed the way an operator typed them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function awards(): array
    {
        return [
            [
                'key' => 'going_all_out',
                'label' => 'Going All Out',
                'fields' => [
                    ['key' => 'elims', 'label' => 'Elims'],
                    ['key' => 'damage', 'label' => 'Damage'],
                ],
            ],
            [
                'key' => 'best_companion',
                'label' => 'Best Companion',
                'fields' => [
                    ['key' => 'rescue', 'label' => 'Rescue'],
                ],
            ],
        ];
    }

    /* ---------------------------------------------------------------------
     | The nickname, which is the only identity on that screen
     * ------------------------------------------------------------------ */

    public function test_an_exact_handle_is_matched_without_a_flag(): void
    {
        $result = $this->matcher([[1, 'QXCikaYELL', 'Aiman Bin Yusof'], [2, 'LxRAAnZR', 'Low Rui An']])->match([
            new MatchAwardCard('going_all_out', 'Going All Out', 'qxcikayell', ['elims' => 7]),
        ], $this->awards());

        $award = $result['awards'][0];

        $this->assertSame(1, $award['player']['id']);
        $this->assertSame(1.0, $award['confidence']);
        $this->assertFalse($award['low_confidence'], 'An exact handle should not need a second look.');
    }

    public function test_the_character_ocr_moves_is_matched_and_flagged(): void
    {
        /*
         | The owner's own two screenshots of one player read QXCikaYELL and
         | QXCikoYELL. A character in ten is not a different person, so it matches —
         | and it is flagged, because a character in ten is also exactly how the wrong
         | person would match.
         */
        $result = $this->matcher([[1, 'QXCikaYELL', 'Aiman Bin Yusof'], [2, 'LxRAAnZR', 'Low Rui An']])->match([
            new MatchAwardCard('going_all_out', 'Going All Out', 'QXCikoYELL', ['elims' => 7]),
        ], $this->awards());

        $award = $result['awards'][0];

        $this->assertSame(1, $award['player']['id']);
        $this->assertTrue($award['low_confidence']);
        $this->assertSame('QXCikoYELL', $award['read_as']);
    }

    public function test_a_handle_nobody_goes_by_is_reported_rather_than_given_to_the_nearest(): void
    {
        $result = $this->matcher([[1, 'QXCikaYELL', 'Aiman Bin Yusof'], [2, 'LxRAAnZR', 'Low Rui An']])->match([
            new MatchAwardCard('best_companion', 'Best Companion', 'Zephyr Unknown Player', ['rescue' => 2]),
        ], $this->awards());

        $award = $result['awards'][0];

        $this->assertNull($award['player']);
        $this->assertSame('Zephyr Unknown Player', $award['read_as']);
        $this->assertStringContainsString('in-game name', $award['reason']);

        // The figures still arrive. The operator knows who it is; the matcher does not.
        $this->assertSame(['rescue' => 2], $award['figures']);
    }

    public function test_two_players_on_one_handle_is_refused_rather_than_guessed(): void
    {
        $result = $this->matcher([[1, 'Sniper', 'Aiman Bin Yusof'], [2, 'Sniper', 'Low Rui An']])->match([
            new MatchAwardCard('best_companion', 'Best Companion', 'Sniper', ['rescue' => 2]),
        ], $this->awards());

        $this->assertNull($result['awards'][0]['player']);
        $this->assertStringContainsString('equally well', $result['awards'][0]['reason']);
    }

    public function test_a_player_whose_entry_carries_no_handle_is_still_reachable(): void
    {
        // Coverage on the owner's esports event is complete, but a profile is not a
        // guarantee: an entry with no in-game name on it is matched by the only other
        // thing there is.
        $result = $this->matcher([[1, '', 'Danial Hakim'], [2, 'LxRAAnZR', 'Low Rui An']])->match([
            new MatchAwardCard('best_companion', 'Best Companion', 'DANIAL HAKIM', ['rescue' => 2]),
        ], $this->awards());

        $this->assertSame(1, $result['awards'][0]['player']['id']);
    }

    /* ---------------------------------------------------------------------
     | The awards, which belong to the profile and not to this code
     * ------------------------------------------------------------------ */

    public function test_awards_come_back_in_profile_order_with_their_field_labels(): void
    {
        $result = $this->matcher([[1, 'QXCikaYELL', 'Aiman Bin Yusof'], [2, 'LxRAAnZR', 'Low Rui An']])->match([
            // Printed in the other order on the screen.
            new MatchAwardCard('best_companion', 'Best Companion', 'LxRAAnZR', ['rescue' => 2]),
            new MatchAwardCard('going_all_out', 'Going All Out', 'QXCikaYELL', ['elims' => 7, 'damage' => 942]),
        ], $this->awards());

        // Down the dialog they are about to fill in, not across the screen they were
        // read off.
        $this->assertSame(['going_all_out', 'best_companion'], array_column($result['awards'], 'key'));

        // The labels ride along so the review panel can name a figure the way the form
        // in front of the operator names it.
        $this->assertSame(
            [['key' => 'elims', 'label' => 'Elims'], ['key' => 'damage', 'label' => 'Damage']],
            $result['awards'][0]['fields'],
        );
    }

    public function test_an_award_the_screen_did_not_cover_is_listed_and_left_blank(): void
    {
        $result = $this->matcher([[1, 'QXCikaYELL', 'Aiman Bin Yusof']])->match([
            new MatchAwardCard('going_all_out', 'Going All Out', 'QXCikaYELL', ['elims' => 7]),
        ], $this->awards());

        $this->assertCount(1, $result['awards']);
        $this->assertSame([['key' => 'best_companion', 'label' => 'Best Companion']], $result['uncovered']);
    }

    public function test_one_award_read_twice_keeps_the_first_card_and_reports_the_second(): void
    {
        $result = $this->matcher([[1, 'QXCikaYELL', 'Aiman Bin Yusof'], [2, 'LxRAAnZR', 'Low Rui An']])->match([
            new MatchAwardCard('going_all_out', 'Going All Out', 'QXCikaYELL', ['elims' => 7]),
            new MatchAwardCard('going_all_out', 'Going All Out', 'LxRAAnZR', ['elims' => 3]),
        ], $this->awards());

        $this->assertCount(1, $result['awards']);
        $this->assertSame(1, $result['awards'][0]['player']['id']);
        $this->assertSame(['elims' => 7], $result['awards'][0]['figures']);

        // Said out loud. One screen cannot hand the same award out twice, and which
        // card is the real one is not a decision to take for somebody.
        $this->assertStringContainsString('read twice', $result['unknown'][0]['reason']);
    }

    public function test_an_award_this_profile_does_not_hand_out_is_not_forced_onto_one(): void
    {
        $result = $this->matcher([[1, 'QXCikaYELL', 'Aiman Bin Yusof']])->match([
            new MatchAwardCard('paling_agresif', 'Paling Agresif', 'QXCikaYELL', ['elims' => 7]),
        ], $this->awards());

        $this->assertSame([], $result['awards']);
    }

    /* ---------------------------------------------------------------------
     | The figures as they are printed
     * ------------------------------------------------------------------ */

    public function test_a_grouped_figure_is_a_whole_number_and_never_a_decimal(): void
    {
        // 9,617 metres travelled and 1,321 seconds survived. Read as decimals these
        // would be nine metres and one second: plausible on the card, wrong by a
        // factor of a thousand.
        $this->assertSame(9617, VisionNumber::figure('9,617'));
        $this->assertSame(1321, VisionNumber::figure('1,321'));

        // The same on a field the profile declared as decimal: the comma groups, the
        // point divides, and the two are not interchangeable.
        $this->assertSame(1234.5, VisionNumber::figure('1,234.5', true));
    }

    public function test_a_figure_that_is_not_a_number_is_refused(): void
    {
        // The subtitle under an award name is sometimes words. Nothing turns that
        // into a figure.
        $this->assertNull(VisionNumber::figure('Final Elimination'));
        $this->assertNull(VisionNumber::figure(''));

        // And a point in a field that counts things is a misread, not a fraction.
        $this->assertNull(VisionNumber::figure('4.5'));
    }

    public function test_the_font_that_draws_one_as_a_capital_i_is_repaired(): void
    {
        $this->assertSame(16, VisionNumber::figure('I6'));
        $this->assertSame(11, VisionNumber::whole('II'));
    }
}
