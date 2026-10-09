<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Support\CouponArtwork;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;

/**
 * A long event title does not land on the code.
 *
 * THE BUG THIS EXISTS FOR
 *
 * A live coupon, design 'gradient', for "10TH ANNIVERSARY UNDERGROUND RUNNERS SIBU
 * FUN RUN". In the downloaded SVG that title ran the width of the card and passed
 * under the frosted code chip, because the budget that decided how much of it fitted
 * on one line measured an all-caps string as if it were prose — a fifth narrower than
 * it draws. Every design puts the code on the right, so every one of them could do it.
 *
 * WHY THE ASSERTIONS LOOK LIKE THIS
 *
 * "The code is in the markup" was true the whole time the bug was live, on screen and
 * in the file. It says nothing about where the code is. So the SVG cases here build a
 * real box for every piece of text in the file — from its own x, anchor, size and
 * tracking, through whatever rotation its group applies — and assert that nothing
 * drawn outside the code block intersects it.
 *
 * The widths are estimated with figures of this test's own, coarser than the ones
 * CouponArtwork budgets with. A test that reused the production ratio would only be
 * checking that arithmetic agrees with itself.
 */
class CouponDesignOverlapTest extends CouponTestCase
{
    use RefreshDatabase;

    /** The live title that started this: long, and all caps, which is the wide case. */
    private const TITLE = '10TH ANNIVERSARY UNDERGROUND RUNNERS SIBU FUN RUN';

    /** Past what any design holds, so the budget has to cut rather than merely wrap. */
    private const ABSURD = 'KEJOHANAN TAHUNAN PERSATUAN BOLA SEPAK DAN LARIAN AMAL PERINGKAT BAHAGIAN SIBU SARAWAK MALAYSIA ANJURAN JABATAN BELIA DAN SUKAN NEGERI BERSAMA PERSATUAN PENDUDUK KAMPUNG NANGKA SIBU 2026';

    /** Where the card sits on the canvas. Nothing drawn may leave it. */
    private const CARD = ['left' => 40.0, 'right' => 680.0, 'top' => 40.0, 'bottom' => 240.0];

    /**
     * Advance per character as a fraction of the font size, for this test only.
     *
     * Two buckets, deliberately coarser than the six CouponArtwork budgets with, and
     * split on the one distinction that mattered: a capital letter against everything
     * else. One flat figure for both cannot work here — set low enough not to object
     * to "RM 99,999,999.99" it would also have passed the all-caps title that was
     * drawing straight through the code chip, which is the whole case.
     */
    private const CAPITAL = 0.64;

    private const OTHER = 0.52;

    private const MONO = 0.64;

    /**
     * The five drawn designs. Custom is an uploaded image and has no SVG.
     *
     * @return array<int, array<int, string>>
     */
    public static function designs(): array
    {
        return array_map(
            fn (string $key) => [$key],
            array_values(array_filter(
                array_keys(Coupon::DESIGNS),
                fn (string $key) => $key !== Coupon::DESIGN_CUSTOM,
            )),
        );
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    /** A coupon of $design, ticked on one event, so the title is what it names. */
    private function couponFor(string $design, string $title, ?float $ringgit = null): Coupon
    {
        $coupon = $ringgit === null
            ? $this->percentageCoupon(50, ['design' => $design])
            : $this->fixedCoupon($ringgit, ['design' => $design]);

        $coupon->events()->attach($this->event(['title' => $title]));

        return $coupon->fresh();
    }

    private function download(Coupon $coupon): string
    {
        $response = $this->actingAs($this->userWith(['coupons.view']))
            ->get(route('admin.coupons.design', $coupon));

        $response->assertOk();

        return $response->getContent();
    }

    private function html(Coupon $coupon, string $title): string
    {
        return Blade::render(
            '<x-coupon.ticket :coupon="$coupon" :subject="$subject" :code="$code" />',
            ['coupon' => $coupon, 'subject' => $title, 'code' => $coupon->name],
        );
    }

    /* ---------------------------------------------------------------------
     | Reading the file
     * ------------------------------------------------------------------ */

    /**
     * Parse the way the machine opening the file would.
     *
     * This is what proves the download works at all: an unescaped ampersand gives a
     * file a browser refuses outright, and no amount of string matching would notice.
     */
    private function parse(string $svg, string $note): DOMXPath
    {
        $internal = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new DOMDocument;
        $loaded = $document->loadXML($svg);
        $errors = array_map(fn ($error) => trim($error->message), libxml_get_errors());

        libxml_clear_errors();
        libxml_use_internal_errors($internal);

        $this->assertSame([], $errors, $note.' produced XML errors.');
        $this->assertTrue($loaded, $note.' did not parse as XML.');
        $this->assertSame('svg', $document->documentElement->localName);

        return new DOMXPath($document);
    }

    /**
     * @return array<int, DOMElement>
     */
    private function texts(DOMXPath $xpath): array
    {
        $nodes = [];

        foreach ($xpath->query('//*[local-name()="text"]') as $node) {
            if (trim($node->textContent) !== '') {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /* ---------------------------------------------------------------------
     | Boxes
     * ------------------------------------------------------------------ */

    /**
     * The box a <text> element occupies, in user units.
     *
     * Width from its own length, size and tracking; the vertical extent from the
     * baseline, since y in SVG is the baseline and not the top of the line.
     *
     * @return array{left: float, right: float, top: float, bottom: float}
     */
    private function textBox(DOMElement $node): array
    {
        $value = trim($node->textContent);
        $size = (float) ($node->getAttribute('font-size') ?: 12);
        $spacing = (float) ($node->getAttribute('letter-spacing') ?: 0);

        $mono = str_contains($node->getAttribute('font-family'), 'monospace');
        $width = $size * $this->advance($value, $mono) + $spacing * mb_strlen($value);

        $x = (float) ($node->getAttribute('x') ?: 0);
        $y = (float) ($node->getAttribute('y') ?: 0);

        $left = match ($node->getAttribute('text-anchor')) {
            'middle' => $x - $width / 2,
            'end' => $x - $width,
            default => $x,
        };

        return $this->transform($node, [
            'left' => $left,
            'right' => $left + $width,
            'top' => $y - $size * 0.80,
            'bottom' => $y + $size * 0.25,
        ]);
    }

    /** How wide a string is, in multiples of its font size. */
    private function advance(string $text, bool $mono): float
    {
        $characters = mb_str_split($text);

        if ($mono) {
            return count($characters) * self::MONO;
        }

        $total = 0.0;

        foreach ($characters as $character) {
            $capital = preg_match('/^\p{Lu}$/u', $character) === 1;

            $total += $capital ? self::CAPITAL : self::OTHER;
        }

        return $total;
    }

    /**
     * @return array{left: float, right: float, top: float, bottom: float}
     */
    private function shapeBox(DOMElement $node): array
    {
        $x = (float) ($node->getAttribute('x') ?: 0);
        $y = (float) ($node->getAttribute('y') ?: 0);

        return $this->transform($node, [
            'left' => $x,
            'right' => $x + (float) ($node->getAttribute('width') ?: 0),
            'top' => $y,
            'bottom' => $y + (float) ($node->getAttribute('height') ?: 0),
        ]);
    }

    /**
     * The same box after whatever its ancestors rotate it by.
     *
     * 'stamp' turns its code box -7 degrees about its own centre, and a rotated box
     * reaches further left than the one it was drawn as — which is exactly the space
     * a title must not grow into. Rotating the four corners and taking the box round
     * them is the honest answer; ignoring the transform would let a title slide under
     * the corner of the stamp and still pass.
     *
     * @param  array{left: float, right: float, top: float, bottom: float}  $box
     * @return array{left: float, right: float, top: float, bottom: float}
     */
    private function transform(DOMElement $node, array $box): array
    {
        for ($parent = $node->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            $transform = $parent->getAttribute('transform');

            if (! preg_match('/rotate\(\s*(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s*\)/', $transform, $match)) {
                continue;
            }

            [, $degrees, $cx, $cy] = $match;

            $radians = deg2rad((float) $degrees);
            $cos = cos($radians);
            $sin = sin($radians);

            $xs = [];
            $ys = [];

            foreach ([[$box['left'], $box['top']], [$box['right'], $box['top']],
                [$box['left'], $box['bottom']], [$box['right'], $box['bottom']]] as [$x, $y]) {
                $xs[] = (float) $cx + ($x - (float) $cx) * $cos - ($y - (float) $cy) * $sin;
                $ys[] = (float) $cy + ($x - (float) $cx) * $sin + ($y - (float) $cy) * $cos;
            }

            $box = ['left' => min($xs), 'right' => max($xs), 'top' => min($ys), 'bottom' => max($ys)];
        }

        return $box;
    }

    /**
     * The space the code owns: the code itself plus whatever is drawn around it.
     *
     * The chip, the stub's perforation, the stamp box. Found by taking every shape
     * that encloses the middle of the code and leaving out anything as wide as the
     * card, which is the card.
     *
     * @return array{left: float, right: float, top: float, bottom: float}
     */
    private function codeBlock(DOMXPath $xpath, string $code): array
    {
        $node = null;

        foreach ($this->texts($xpath) as $candidate) {
            if (trim($candidate->textContent) === $code) {
                $node = $candidate;
            }
        }

        $this->assertNotNull($node, 'The artwork does not draw the code '.$code.'.');

        $block = $this->textBox($node);
        $midX = ($block['left'] + $block['right']) / 2;
        $midY = ($block['top'] + $block['bottom']) / 2;

        foreach ($xpath->query('//*[local-name()="rect"]') as $shape) {
            $box = $this->shapeBox($shape);

            if ($box['right'] - $box['left'] > 560) {
                continue;
            }

            if ($midX < $box['left'] - 6 || $midX > $box['right'] + 6) {
                continue;
            }

            if ($midY < $box['top'] - 10 || $midY > $box['bottom'] + 10) {
                continue;
            }

            $block = [
                'left' => min($block['left'], $box['left']),
                'right' => max($block['right'], $box['right']),
                'top' => min($block['top'], $box['top']),
                'bottom' => max($block['bottom'], $box['bottom']),
            ];
        }

        return $block;
    }

    /**
     * @param  array{left: float, right: float, top: float, bottom: float}  $a
     * @param  array{left: float, right: float, top: float, bottom: float}  $b
     */
    private function intersects(array $a, array $b): bool
    {
        return $a['right'] > $b['left'] && $b['right'] > $a['left']
            && $a['bottom'] > $b['top'] && $b['bottom'] > $a['top'];
    }

    /* ---------------------------------------------------------------------
     | The one assertion everything here is about
     * ------------------------------------------------------------------ */

    /**
     * Nothing drawn outside the code block touches it, and nothing leaves the card.
     *
     * Called for the owner's title, for an absurd one and for a ringgit figure, in all
     * five designs, because the slot that collides is the same slot in each.
     */
    private function assertTheCodeIsClear(string $svg, Coupon $coupon, string $note): void
    {
        $xpath = $this->parse($svg, $note);
        $block = $this->codeBlock($xpath, $coupon->name);

        foreach ($this->texts($xpath) as $node) {
            $value = trim($node->textContent);
            $box = $this->textBox($node);

            // The chip's own caption is inside the block and belongs there.
            $inside = $box['left'] >= $block['left'] - 1 && $box['right'] <= $block['right'] + 1
                && $box['top'] >= $block['top'] - 1 && $box['bottom'] <= $block['bottom'] + 1;

            if (! $inside) {
                $this->assertFalse(
                    $this->intersects($box, $block),
                    sprintf(
                        '%s: "%s" [%.0f..%.0f] runs into the code block [%.0f..%.0f].',
                        $note,
                        $value,
                        $box['left'],
                        $box['right'],
                        $block['left'],
                        $block['right'],
                    ),
                );
            }

            $message = sprintf('%s: "%s" is drawn outside the card.', $note, $value);

            // Two units of slack, which is the stroke on the card's own border.
            $this->assertGreaterThanOrEqual(self::CARD['left'] - 2, $box['left'], $message);
            $this->assertLessThanOrEqual(self::CARD['right'] + 2, $box['right'], $message);
            $this->assertGreaterThanOrEqual(self::CARD['top'] - 2, $box['top'], $message);
            $this->assertLessThanOrEqual(self::CARD['bottom'] + 2, $box['bottom'], $message);
        }

        // And the code is still a code: whole, inside the card, at a size somebody can
        // read off a printed page. A truncated code fails at redemption.
        $this->assertGreaterThanOrEqual(self::CARD['left'] - 2, $block['left'], $note.': the code block left the card.');
        $this->assertLessThanOrEqual(self::CARD['right'] + 2, $block['right'], $note.': the code block left the card.');
        $this->assertGreaterThanOrEqual(8.5, CouponArtwork::for($coupon)->size('code'), $note.': the code is too small to read.');
    }

    /* ---------------------------------------------------------------------
     | SVG
     * ------------------------------------------------------------------ */

    #[\PHPUnit\Framework\Attributes\DataProvider('designs')]
    public function test_the_live_title_clears_the_code_in_every_design(string $design): void
    {
        $coupon = $this->couponFor($design, self::TITLE);

        $svg = $this->download($coupon);

        $this->assertTheCodeIsClear($svg, $coupon, $design.' / live title');

        // Wrapped rather than cut: the whole of the owner's title is still drawn.
        $this->assertSame(
            self::TITLE,
            implode(' ', CouponArtwork::for($coupon)->lines('subject')),
            $design.' cut the live title instead of wrapping it.',
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('designs')]
    public function test_an_absurdly_long_title_clears_the_code_in_every_design(string $design): void
    {
        $coupon = $this->couponFor($design, self::ABSURD);

        $this->assertTheCodeIsClear($this->download($coupon), $coupon, $design.' / absurd title');

        $lines = CouponArtwork::for($coupon)->lines('subject');

        // Two lines at most, and the last says it was cut.
        $this->assertLessThanOrEqual(2, count($lines), $design.' gave the title more lines than it has room for.');
        $this->assertStringEndsWith('…', (string) end($lines), $design.' cut the title without saying so.');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('designs')]
    public function test_a_large_ringgit_figure_clears_the_code_in_every_design(string $design): void
    {
        foreach ([1250.50, 99999999.99] as $value) {
            $coupon = $this->couponFor($design, self::TITLE, $value);

            $note = sprintf('%s / RM %s', $design, number_format($value, 2));

            $this->assertTheCodeIsClear($this->download($coupon), $coupon, $note);

            // Stepped down, not cut. A figure with its last digits replaced by an
            // ellipsis is a coupon that claims the wrong discount.
            $art = CouponArtwork::for($coupon);
            $drawn = $design === 'bold' ? $art->line('hero') : $art->line('discount');

            $this->assertStringNotContainsString('…', $drawn, $note.' cut the figure.');
            $this->assertStringContainsString(
                $design === 'bold' ? number_format($value, 2) : 'RM '.number_format($value, 2),
                $drawn,
                $note.' lost part of the figure.',
            );
        }
    }

    /* ---------------------------------------------------------------------
     | Where the line breaks
     * ------------------------------------------------------------------ */

    #[\PHPUnit\Framework\Attributes\DataProvider('designs')]
    public function test_a_wrapped_title_breaks_between_words_and_never_through_one(string $design): void
    {
        $lines = CouponArtwork::for($this->couponFor($design, self::TITLE))->lines('subject');

        $this->assertGreaterThan(1, count($lines), $design.' did not wrap the live title at all.');

        // Rejoined it is the title again, which only holds if every break fell on a
        // space. A word split across two lines would leave the halves without one.
        $this->assertSame(self::TITLE, implode(' ', $lines));

        // Said the other way round, from the words themselves.
        $words = explode(' ', self::TITLE);

        foreach ($lines as $line) {
            foreach (explode(' ', $line) as $word) {
                $this->assertContains($word, $words, $design.' broke "'.$word.'" out of a word.');
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('designs')]
    public function test_a_cut_title_still_ends_on_a_whole_word(string $design): void
    {
        $lines = CouponArtwork::for($this->couponFor($design, self::ABSURD))->lines('subject');

        $kept = rtrim(implode(' ', $lines), '…');
        $words = explode(' ', self::ABSURD);

        foreach (explode(' ', trim($kept)) as $word) {
            $this->assertContains($word, $words, $design.' cut through the middle of "'.$word.'".');
        }

        // What survives is the beginning, which is the part that names the event.
        $this->assertStringStartsWith('KEJOHANAN TAHUNAN', trim($kept));
    }

    /* ---------------------------------------------------------------------
     | On screen
     * ------------------------------------------------------------------ */

    /**
     * The on-screen designs are two real columns.
     *
     * A flexible left column that may shrink below its content, so the title wraps
     * into the space the code block leaves rather than against the full card, and a
     * code block that keeps its own width. Asserted on the markup because there is no
     * browser in this suite; the geometry itself was measured in one, against the
     * built stylesheet, at container widths from 320 to 900.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('designs')]
    public function test_the_left_column_flexes_and_the_code_keeps_its_width(string $design): void
    {
        $coupon = $this->couponFor($design, self::TITLE);
        $html = $this->html($coupon, self::TITLE);

        // Flexible, and allowed to go narrower than its longest word. Without the
        // min-w-0 a flex item will not shrink past its content and the row overflows.
        $this->assertMatchesRegularExpression(
            '/min-w-0[^"\']*flex-1|flex-1[^"\']*min-w-0/',
            $html,
            $design.' has no column that flexes into the space the code leaves.',
        );

        $this->assertStringContainsString(
            'shrink-0',
            $html,
            $design.' lets the code block be squeezed by the title.',
        );

        // Two lines, then an ellipsis — the same ceiling the SVG has, so a title that
        // wraps on screen is not one long line in the downloaded file.
        $this->assertStringContainsString(
            'line-clamp-2',
            $html,
            $design.' lets the title grow the card instead of clamping it.',
        );

        // Clamped in CSS, not on the server: the whole title is still in the markup
        // for anything reading rather than looking.
        $this->assertStringContainsString(e(self::TITLE), $html, $design.' cut the title server side.');
        $this->assertStringContainsString($coupon->name, $html, $design.' lost the code.');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('designs')]
    public function test_an_absurd_title_and_a_large_figure_keep_the_code_on_screen(string $design): void
    {
        foreach ([null, 99999999.99] as $ringgit) {
            $coupon = $this->couponFor($design, self::ABSURD, $ringgit);
            $html = $this->html($coupon, self::ABSURD);

            $this->assertStringContainsString($coupon->name, $html, $design.' lost the code.');
            $this->assertStringContainsString($coupon->expiresLabel(), $html, $design.' lost the expiry.');

            // Long values wrap instead of being clipped, which is what stops a narrow
            // phone losing the end of a code.
            $this->assertStringContainsString('break-', $html, $design.' has nothing to wrap long values.');

            if ($ringgit !== null) {
                $this->assertStringContainsString(
                    number_format($ringgit, 2),
                    $html,
                    $design.' lost part of the figure.',
                );
            }
        }
    }
}
