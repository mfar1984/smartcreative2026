<?php

namespace Tests\Unit\Tournament;

use App\Services\Vision\ScoreSheetException;
use App\Services\Vision\ScoreSheetRow;
use App\Support\Tournament\ScoreSheetMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Tying rows read off a photograph to the competitors in one fixture.
 *
 * No database and no network here on purpose: this is the part of the feature that
 * decides which team gets which score, and it has to be exercisable against the
 * real spellings without either.
 *
 * WHAT THESE PROVE. That the spellings the real entries actually differ by are
 * matched; that a name nobody recognises is reported rather than given to whoever
 * it resembles most; that a tie is refused outright; that a table awarding one
 * position twice is refused whole; and that several images of one table merge
 * instead of fighting.
 */
class ScoreSheetMatcherTest extends TestCase
{
    /**
     * @param  array<int, array{0: int, 1: string}>  $teams
     */
    private function matcher(array $teams): ScoreSheetMatcher
    {
        return new ScoreSheetMatcher(array_map(fn (array $team) => [
            'id' => $team[0],
            'name' => $team[1],
            'current' => ['placement' => null, 'kills' => null, 'winner' => false],
        ], $teams));
    }

    /* ---------------------------------------------------------------------
     | The spellings the real data differs by
     * ------------------------------------------------------------------ */

    public function test_case_and_spacing_are_not_a_different_team(): void
    {
        $result = $this->matcher([[7, 'MDESA'], [8, '96K'], [9, 'V18']])->match([
            new ScoreSheetRow('Mdesa', 1, 12),
            new ScoreSheetRow('96k', 2, 9),
            new ScoreSheetRow('  v18  ', 3, 4),
        ]);

        $this->assertSame([], $result['unmatched']);
        $this->assertSame([7, 8, 9], array_column($result['rows'], 'entrant_id'));

        // Matched outright, so nothing here needs a second look before Apply.
        foreach ($result['rows'] as $row) {
            $this->assertSame(1.0, $row['confidence']);
            $this->assertFalse($row['low_confidence']);
        }
    }

    public function test_punctuation_and_an_accent_are_not_a_different_team(): void
    {
        $result = $this->matcher([[3, 'Café Legion'], [4, 'Alpha Squad']])->match([
            new ScoreSheetRow('CAFE  LEGION', 1, 6),
            new ScoreSheetRow('alpha-squad', 2, 2),
        ]);

        $this->assertSame([], $result['unmatched']);
        $this->assertSame([3, 4], array_column($result['rows'], 'entrant_id'));
    }

    public function test_the_top_of_the_table_is_proposed_as_the_winner(): void
    {
        $result = $this->matcher([[1, 'MDESA'], [2, '96K']])->match([
            new ScoreSheetRow('MDESA', 1, 12),
            new ScoreSheetRow('96K', 2, 9),
        ]);

        $this->assertTrue($result['rows'][0]['winner']);
        $this->assertFalse($result['rows'][1]['winner']);
    }

    /* ---------------------------------------------------------------------
     | What it refuses to guess at
     * ------------------------------------------------------------------ */

    public function test_a_row_matching_nothing_is_reported_with_what_was_read(): void
    {
        $result = $this->matcher([[1, 'MDESA'], [2, '96K']])->match([
            new ScoreSheetRow('MDESA', 1, 12),
            new ScoreSheetRow('WRECKING BALL', 2, 7),
        ]);

        $this->assertCount(1, $result['rows']);
        $this->assertCount(1, $result['unmatched']);

        // The text that was read is handed back, because that is the only way the
        // operator can tell what the reader saw.
        $this->assertSame('WRECKING BALL', $result['unmatched'][0]['read_as']);
        $this->assertSame(2, $result['unmatched'][0]['placement']);
        $this->assertSame(7, $result['unmatched'][0]['kills']);
        $this->assertStringContainsString('no competitor', $result['unmatched'][0]['reason']);

        // And it is not quietly given to the nearest name.
        $this->assertSame([1], array_column($result['rows'], 'entrant_id'));
    }

    public function test_two_competitors_matching_equally_well_are_refused_rather_than_split(): void
    {
        $result = $this->matcher([[1, 'Alpha'], [2, 'Alpba']])->match([
            new ScoreSheetRow('Alpca', 1, 4),
        ]);

        $this->assertSame([], $result['rows']);
        $this->assertCount(1, $result['unmatched']);
        $this->assertStringContainsString('equally well', $result['unmatched'][0]['reason']);
    }

    public function test_a_near_miss_is_matched_but_flagged_for_review(): void
    {
        $result = $this->matcher([[5, 'MDESA'], [6, 'Wrecking Crew']])->match([
            new ScoreSheetRow('MOESA', 1, 8),
        ]);

        $this->assertCount(1, $result['rows']);
        $this->assertSame(5, $result['rows'][0]['entrant_id']);
        $this->assertTrue(
            $result['rows'][0]['low_confidence'],
            'A name read differently from the entry must not apply itself without being looked at.',
        );
        $this->assertLessThan(ScoreSheetMatcher::REVIEW_BELOW, $result['rows'][0]['confidence']);
    }

    public function test_two_rows_on_one_position_refuse_the_whole_read(): void
    {
        $this->expectException(ScoreSheetException::class);

        $this->matcher([[1, 'MDESA'], [2, '96K']])->match([
            new ScoreSheetRow('MDESA', 3, 12),
            new ScoreSheetRow('96K', 3, 9),
        ]);
    }

    public function test_the_refusal_names_both_rows_so_the_operator_can_find_them(): void
    {
        try {
            $this->matcher([[1, 'MDESA'], [2, '96K']])->match([
                new ScoreSheetRow('MDESA', 3, 12),
                new ScoreSheetRow('96K', 3, 9),
            ]);

            $this->fail('A table awarding position 3 twice was accepted.');
        } catch (ScoreSheetException $e) {
            $this->assertStringContainsString('MDESA', $e->publicMessage);
            $this->assertStringContainsString('96K', $e->publicMessage);
            $this->assertStringContainsString('3', $e->publicMessage);
        }
    }

    /* ---------------------------------------------------------------------
     | Several images of one table
     * ------------------------------------------------------------------ */

    public function test_the_same_team_in_two_images_is_merged_once(): void
    {
        $result = $this->matcher([[1, 'MDESA'], [2, '96K']])->match([
            new ScoreSheetRow('MDESA', 1, 12),
            new ScoreSheetRow('96K', 2, 9),

            // The overlap between two photographs of one table.
            new ScoreSheetRow('Mdesa', 1, 12),
        ]);

        $this->assertCount(2, $result['rows']);
        $this->assertSame([], $result['clashes']);
        $this->assertFalse($result['rows'][0]['conflict']);
    }

    public function test_the_same_team_read_two_different_ways_keeps_the_first_and_says_so(): void
    {
        $result = $this->matcher([[1, 'MDESA'], [2, '96K']])->match([
            new ScoreSheetRow('MDESA', 1, 12),
            new ScoreSheetRow('MDESA', 4, 3),
        ]);

        $this->assertCount(1, $result['rows']);

        // The first reading stands, and the row will not apply itself.
        $this->assertSame(1, $result['rows'][0]['placement']);
        $this->assertSame(12, $result['rows'][0]['kills']);
        $this->assertTrue($result['rows'][0]['conflict']);

        $this->assertCount(1, $result['clashes']);
        $this->assertSame('MDESA', $result['clashes'][0]['name']);
        $this->assertSame(['placement' => 1, 'kills' => 12], $result['clashes'][0]['kept']);
        $this->assertSame(['placement' => 4, 'kills' => 3], $result['clashes'][0]['also_read']);
    }

    /* ---------------------------------------------------------------------
     | What the image did not cover
     * ------------------------------------------------------------------ */

    public function test_a_competitor_no_row_covered_is_listed(): void
    {
        $result = $this->matcher([[1, 'MDESA'], [2, '96K'], [3, 'KLASIK']])->match([
            new ScoreSheetRow('MDESA', 1, 12),
        ]);

        $this->assertSame([
            ['entrant_id' => 2, 'name' => '96K'],
            ['entrant_id' => 3, 'name' => 'KLASIK'],
        ], $result['uncovered']);
    }

    public function test_the_recorded_figures_ride_along_so_a_change_reads_as_a_change(): void
    {
        $matcher = new ScoreSheetMatcher([[
            'id' => 1,
            'name' => 'MDESA',
            'current' => ['placement' => 7, 'kills' => 2, 'winner' => false],
        ]]);

        $result = $matcher->match([new ScoreSheetRow('MDESA', 1, 12)]);

        $this->assertSame(7, $result['rows'][0]['current']['placement']);
        $this->assertSame(2, $result['rows'][0]['current']['kills']);
    }
}
