<?php

namespace Tests\Feature\Coupon;

use App\Models\ActivityLog;
use App\Models\Coupon;
use App\Support\CouponArtwork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use SimpleXMLElement;

/**
 * The coupon design as a file the owner can keep.
 *
 * Two things are being held to here, and the second is the one that bites.
 *
 * SELF-CONTAINED. A downloaded file opens on a machine that has never seen this site,
 * so nothing in it may reach back: no stylesheet, no web font, no remote image. The
 * assertion for that is the absence of any URL at all beyond the SVG namespace.
 *
 * WELL FORMED, AND INSIDE THE CARD. SVG text does not wrap and the server cannot
 * measure a string, so a long event title is the failure mode: it runs out of the card
 * and off the paper, and nobody notices until it has been printed. Every case here
 * parses the response with a real XML parser — an unparseable file is one that will not
 * open — and then walks every <text> element to check it still falls inside the card.
 */
class CouponDesignDownloadTest extends CouponTestCase
{
    use RefreshDatabase;

    /** The live title that started this: long, and all caps, which is the wide case. */
    private const LONG_TITLE = '10TH ANNIVERSARY UNDERGROUND RUNNERS SIBU FUN RUN';

    /**
     * Longer again, past what even two lines of the roomiest design hold, so the
     * budget has to cut rather than merely wrap.
     */
    private const VERY_LONG_TITLE = '10TH ANNIVERSARY UNDERGROUND RUNNERS SIBU FUN RUN 2026, KEJOHANAN PERINGKAT BAHAGIAN SIBU, SARAWAK, MALAYSIA, ANJURAN JABATAN BELIA DAN SUKAN NEGERI';

    /** Where the card sits on the canvas. Nothing drawn may leave it. */
    private const CARD_LEFT = 40.0;

    private const CARD_RIGHT = 680.0;

    /**
     * The five drawn designs. Custom is left out on purpose: it has no SVG to
     * generate and is covered by its own two cases below.
     *
     * @return array<int, array<int, string>>
     */
    public static function designs(): array
    {
        $keys = array_keys(Coupon::DESIGNS);

        return array_map(
            fn (string $key) => [$key],
            array_values(array_filter($keys, fn (string $key) => $key !== Coupon::DESIGN_CUSTOM)),
        );
    }

    /* ---------------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------------ */

    private function download(Coupon $coupon, ?array $permissions = null)
    {
        return $this->actingAs($this->userWith($permissions ?? ['coupons.view']))
            ->get(route('admin.coupons.design', $coupon));
    }

    /** A coupon of $design, ticked on an event with $title. */
    private function couponFor(string $design, string $title, float $percent = 50): Coupon
    {
        $coupon = $this->percentageCoupon($percent, ['design' => $design]);
        $coupon->events()->attach($this->event(['title' => $title]));

        return $coupon->fresh();
    }

    /**
     * Parse the file the way the machine opening it would.
     *
     * This is the assertion that actually proves the download works: a malformed
     * entity or an unescaped ampersand gives a file that a browser refuses outright,
     * and no amount of string matching on the body would notice.
     */
    private function parse(string $svg, string $note = ''): SimpleXMLElement
    {
        $internal = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $xml = simplexml_load_string($svg);
        $errors = array_map(fn ($error) => trim($error->message), libxml_get_errors());

        libxml_clear_errors();
        libxml_use_internal_errors($internal);

        $this->assertSame([], $errors, $note.' produced XML errors.');
        $this->assertNotFalse($xml, $note.' did not parse as XML.');
        $this->assertSame('svg', $xml->getName());

        return $xml;
    }

    /**
     * Everything the file actually DRAWS, as one string.
     *
     * Deliberately not the response body: the <title> element carries the coupon
     * spelt out in full for anything reading rather than looking, and it is not drawn,
     * so it can neither overflow nor be truncated.
     */
    private function drawnText(SimpleXMLElement $svg): string
    {
        $nodes = $svg->xpath('//*[local-name()="text"]') ?: [];

        return implode(' ', array_map('strval', $nodes));
    }

    /**
     * Every line of text still falls inside the card.
     *
     * The width here is estimated independently of the production figures, and
     * deliberately coarser than them: 0.55 of the font size per character against
     * CouponArtwork's 0.52 for prose. A test that reused the same ratio would only be
     * checking that arithmetic agrees with itself.
     */
    private function assertTextStaysInsideTheCard(SimpleXMLElement $svg, string $note): void
    {
        $nodes = $svg->xpath('//*[local-name()="text"]') ?: [];

        $this->assertNotSame([], $nodes, $note.' drew no text at all.');

        foreach ($nodes as $node) {
            $value = trim((string) $node);

            if ($value === '') {
                continue;
            }

            $size = (float) ($node['font-size'] ?? 12);
            $spacing = (float) ($node['letter-spacing'] ?? 0);
            $width = mb_strlen($value) * ($size * 0.55 + $spacing);

            $x = (float) ($node['x'] ?? 0);

            $left = match ((string) ($node['text-anchor'] ?? 'start')) {
                'middle' => $x - $width / 2,
                'end' => $x - $width,
                default => $x,
            };

            $message = sprintf('%s: "%s" runs outside the card.', $note, $value);

            // Two units of slack, which is the stroke on the card's own border.
            $this->assertGreaterThanOrEqual(self::CARD_LEFT - 2, $left, $message);
            $this->assertLessThanOrEqual(self::CARD_RIGHT + 2, $left + $width, $message);
        }
    }

    /* ---------------------------------------------------------------------
     | The response
     * ------------------------------------------------------------------ */

    public function test_the_download_is_an_svg_named_after_the_coupon_and_its_design(): void
    {
        $coupon = $this->percentageCoupon(50, ['name' => 'NG68BJ', 'design' => 'gradient']);

        $response = $this->download($coupon);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/svg+xml');

        $disposition = (string) $response->headers->get('Content-Disposition');

        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('coupon-NG68BJ-gradient.svg', $disposition);
    }

    public function test_the_file_reaches_back_to_nothing(): void
    {
        $coupon = $this->couponFor('gradient', self::LONG_TITLE);

        $svg = $this->download($coupon)->getContent();

        // The namespace is the only URL a standalone SVG is allowed to carry.
        $body = str_replace('http://www.w3.org/2000/svg', '', $svg);

        $this->assertStringNotContainsString('http', $body, 'The artwork points at something remote.');
        $this->assertStringNotContainsString('<image', $svg, 'The artwork embeds a linked image.');
        $this->assertStringNotContainsString('<link', $svg);
        $this->assertStringNotContainsString('@import', $svg);
        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $svg);
    }

    /* ---------------------------------------------------------------------
     | All five designs, both discount shapes
     * ------------------------------------------------------------------ */

    #[\PHPUnit\Framework\Attributes\DataProvider('designs')]
    public function test_a_percentage_coupon_draws_the_four_facts_in_every_design(string $design): void
    {
        $coupon = $this->couponFor($design, self::LONG_TITLE);

        $xml = $this->parse($this->download($coupon)->getContent(), $design);
        $drawn = $this->drawnText($xml);

        // The discount. 'bold' stacks the figure over its unit rather than printing
        // the whole label on one line, which is why both shapes are accepted.
        $this->assertTrue(
            str_contains($drawn, '50% OFF') || (str_contains($drawn, 'PERCENT') && str_contains($drawn, '50')),
            $design.' lost the discount.',
        );

        $this->assertStringContainsString($coupon->name, $drawn, $design.' lost the code.');
        $this->assertStringContainsString($coupon->expiresLabel(), $drawn, $design.' lost the expiry.');
        $this->assertStringContainsString('UNDERGROUND RUNNERS', $drawn, $design.' lost what it is for.');

        $this->assertTextStaysInsideTheCard($xml, $design);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('designs')]
    public function test_a_ringgit_coupon_draws_the_four_facts_in_every_design(string $design): void
    {
        $coupon = $this->fixedCoupon(25, ['design' => $design]);
        $coupon->events()->attach($this->event(['title' => self::LONG_TITLE]));

        $xml = $this->parse($this->download($coupon->fresh())->getContent(), $design);
        $drawn = $this->drawnText($xml);

        $this->assertStringContainsString('25.00', $drawn, $design.' lost the discount.');
        $this->assertTrue(
            str_contains($drawn, 'RM') || str_contains($drawn, 'RINGGIT'),
            $design.' does not say the discount is in ringgit.',
        );

        $this->assertStringContainsString($coupon->name, $drawn, $design.' lost the code.');
        $this->assertStringContainsString($coupon->expiresLabel(), $drawn, $design.' lost the expiry.');

        $this->assertTextStaysInsideTheCard($xml, $design);
    }

    /**
     * A ringgit figure is longer than a percentage, and the discount slot is the one
     * sized around a short value. Both the owner's case and the widest the column
     * allows are driven through all five.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('designs')]
    public function test_a_long_ringgit_figure_still_fits_the_discount_slot(string $design): void
    {
        foreach ([1250.5, 99999999.99] as $value) {
            $coupon = $this->fixedCoupon($value, ['design' => $design]);

            $xml = $this->parse($this->download($coupon)->getContent(), $design);

            /*
             | Asserted on the DRAWN text, not the response body. The <title> element
             | carries the coupon spelt out in full, so a body-wide assertion passes
             | happily while the figure on the card reads "1,250.…" — which is how
             | this very bug got through the first time.
             */
            $this->assertStringContainsString(
                number_format($value, 2),
                $this->drawnText($xml),
                $design.' cut the figure instead of setting it smaller.',
            );

            $this->assertTextStaysInsideTheCard($xml, $design);
        }
    }

    /**
     * The case the owner flagged by name.
     *
     * 'bold' sizes its hero block around one short number — "50" fills it — so a four
     * figure sum has to be stepped down rather than painted over the edge of the block.
     */
    public function test_the_bold_hero_degrades_rather_than_clipping_a_ringgit_figure(): void
    {
        $small = $this->fixedCoupon(25, ['design' => 'bold']);
        $large = $this->fixedCoupon(1250.5, ['design' => 'bold']);

        $this->assertLessThan(
            CouponArtwork::for($small)->size('hero'),
            CouponArtwork::for($large)->size('hero'),
            'bold did not step its hero figure down.',
        );

        // Stepped down, not cut: the whole figure is still on the card.
        $drawn = $this->drawnText($this->parse($this->download($large)->getContent(), 'bold'));

        $this->assertStringContainsString('1,250.50', $drawn);
        $this->assertStringNotContainsString('…', $drawn, 'bold put an ellipsis in the discount.');
    }

    /* ---------------------------------------------------------------------
     | Text that does not fit
     * ------------------------------------------------------------------ */

    #[\PHPUnit\Framework\Attributes\DataProvider('designs')]
    public function test_a_very_long_title_is_cut_rather_than_run_off_the_card(string $design): void
    {
        $coupon = $this->couponFor($design, self::VERY_LONG_TITLE);

        $svg = $this->download($coupon)->getContent();
        $xml = $this->parse($svg, $design);
        $drawn = $this->drawnText($xml);

        // Cut, and said to be cut.
        $this->assertStringNotContainsString(self::VERY_LONG_TITLE, $drawn, $design.' drew the whole title.');
        $this->assertStringContainsString('…', $drawn, $design.' cut the title without saying so.');

        // The beginning survives, which is the part that identifies the event.
        $this->assertStringContainsString('10TH ANNIVERSARY', $drawn);

        // Nothing is lost to the reader who cannot see it: the <title> element still
        // spells the whole coupon out.
        $this->assertStringContainsString(self::VERY_LONG_TITLE, (string) $xml->title);

        $this->assertTextStaysInsideTheCard($xml, $design);
    }

    public function test_a_title_is_split_on_a_word_boundary_where_there_is_room_for_two_lines(): void
    {
        // Longer than one line of 'classic' holds and shorter than two, so it wraps
        // without anything being cut.
        $title = '10TH ANNIVERSARY UNDERGROUND RUNNERS SIBU FUN RUN 2026 KEJOHANAN BAHAGIAN SIBU';

        $lines = CouponArtwork::for($this->couponFor('classic', $title))->lines('subject');

        $this->assertGreaterThan(1, count($lines), 'The title was not given a second line.');

        // Rejoined it is still the title, which only holds if the split fell on a
        // space rather than through the middle of a word.
        $this->assertSame($title, implode(' ', $lines));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('designs')]
    public function test_an_ampersand_and_an_apostrophe_still_give_a_well_formed_file(string $design): void
    {
        $coupon = $this->couponFor($design, "Sukan Rakyat & Jogathon <Sibu> O'Brien 2026");

        $svg = $this->download($coupon)->getContent();

        // Escaped rather than passed through, which is what makes the file openable.
        $this->assertStringContainsString('&amp;', $svg);
        $this->assertStringNotContainsString('& Jogathon', $svg);

        // And it reads back as the title that was typed.
        $drawn = $this->drawnText($this->parse($svg, $design));

        $this->assertStringContainsString('&', $drawn);
        $this->assertStringContainsString("O'Brien", $drawn);
    }

    /* ---------------------------------------------------------------------
     | What the coupon applies to
     * ------------------------------------------------------------------ */

    public function test_the_artwork_names_the_event_the_coupon_is_ticked_on(): void
    {
        $coupon = $this->couponFor('classic', 'Hari Sukan Negara 2026');

        $drawn = $this->drawnText($this->parse($this->download($coupon)->getContent()));

        $this->assertStringContainsString('Hari Sukan Negara 2026', $drawn);
    }

    public function test_a_shop_coupon_names_the_product_it_is_ticked_on(): void
    {
        $coupon = $this->percentageCoupon(10, ['kind' => Coupon::KIND_SHOP, 'design' => 'classic']);
        $coupon->products()->attach($this->product(['name' => 'Team Jersey 2026']));

        $drawn = $this->drawnText($this->parse($this->download($coupon->fresh())->getContent()));

        $this->assertStringContainsString('Team Jersey 2026', $drawn);

        // And says which kind of thing it is for, which is the eyebrow line.
        $this->assertStringContainsString('SHOP COUPON', $drawn);
    }

    public function test_a_coupon_ticked_on_several_events_falls_back_to_the_kind(): void
    {
        // One line of SVG text cannot name five events, and naming only the first
        // would print a coupon that claims to be for an event it is not limited to.
        $coupon = $this->percentageCoupon(10, ['design' => 'classic']);
        $coupon->events()->attach($this->event(['title' => 'Hari Sukan Negara 2026']));
        $coupon->events()->attach($this->event(['title' => 'Larian Amal Sibu 2026']));

        $drawn = $this->drawnText($this->parse($this->download($coupon->fresh())->getContent()));

        $this->assertStringContainsString('Event Registration', $drawn);
        $this->assertStringNotContainsString('Hari Sukan Negara 2026', $drawn);
    }

    /* ---------------------------------------------------------------------
     | Custom artwork
     * ------------------------------------------------------------------ */

    public function test_a_custom_design_serves_the_uploaded_file_itself(): void
    {
        Storage::fake('public');

        $coupon = $this->percentageCoupon(20, ['design' => Coupon::DESIGN_CUSTOM]);
        $coupon->design_path = UploadedFile::fake()
            ->image('ticket.png', 600, 300)
            ->store(Coupon::DESIGN_DIRECTORY, 'public');
        $coupon->save();

        $response = $this->download($coupon->fresh());

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString(
            'coupon-'.$coupon->name.'-custom.png',
            (string) $response->headers->get('Content-Disposition'),
        );

        // The uploaded bytes, unchanged. Not a raster wrapped in an SVG shell and
        // called vector.
        $body = $response->streamedContent();

        $this->assertSame(Storage::disk('public')->get($coupon->design_path), $body);
        $this->assertStringNotContainsString('<svg', $body);
    }

    public function test_a_custom_design_with_nothing_uploaded_is_not_found(): void
    {
        Storage::fake('public');

        $coupon = $this->percentageCoupon(20, ['design' => Coupon::DESIGN_CUSTOM]);

        $this->download($coupon)->assertNotFound();
    }

    public function test_a_custom_design_whose_file_has_gone_is_not_found(): void
    {
        Storage::fake('public');

        $coupon = $this->percentageCoupon(20, ['design' => Coupon::DESIGN_CUSTOM]);
        $coupon->design_path = Coupon::DESIGN_DIRECTORY.'/deleted.png';
        $coupon->save();

        $this->download($coupon->fresh())->assertNotFound();
    }

    /* ---------------------------------------------------------------------
     | Permission, and the record of who took a copy
     * ------------------------------------------------------------------ */

    public function test_the_download_needs_the_coupon_view_permission(): void
    {
        $coupon = $this->percentageCoupon(10, ['design' => 'classic']);

        $this->actingAs($this->userWith(['events.view']))
            ->get(route('admin.coupons.design', $coupon))
            ->assertForbidden();

        $this->actingAs($this->userWith(['coupons.view']))
            ->get(route('admin.coupons.design', $coupon))
            ->assertOk();
    }

    public function test_a_download_is_written_to_the_activity_log(): void
    {
        $coupon = $this->couponFor('gradient', 'Hari Sukan Negara 2026');

        $this->download($coupon)->assertOk();

        $this->assertDatabaseHas('activity_logs', ['action' => 'coupons.design-download']);

        $log = ActivityLog::query()->where('action', 'coupons.design-download')->latest('id')->sole();

        $this->assertStringContainsString($coupon->name, $log->description);
        $this->assertStringContainsString('gradient', $log->description);
    }

    public function test_a_custom_download_is_recorded_too(): void
    {
        Storage::fake('public');

        $coupon = $this->percentageCoupon(20, ['design' => Coupon::DESIGN_CUSTOM]);
        $coupon->design_path = UploadedFile::fake()
            ->image('ticket.png', 600, 300)
            ->store(Coupon::DESIGN_DIRECTORY, 'public');
        $coupon->save();

        $this->download($coupon->fresh())->assertOk();

        $this->assertDatabaseHas('activity_logs', ['action' => 'coupons.design-download']);
    }

    /* ---------------------------------------------------------------------
     | The list
     * ------------------------------------------------------------------ */

    public function test_the_list_offers_the_download_for_every_coupon(): void
    {
        $event = $this->coupon(['name' => 'LISTAA', 'design' => 'classic']);
        $shop = $this->coupon(['name' => 'LISTBB', 'kind' => Coupon::KIND_SHOP, 'design' => 'minimal']);

        $response = $this->actingAs($this->userWith(['coupons.view']))
            ->get(route('admin.coupons.index'));

        $response->assertOk();
        $response->assertSee(route('admin.coupons.design', $event), false);
        $response->assertSee(route('admin.coupons.design', $shop), false);

        // Named, because three bare icons in a row say nothing to a screen reader.
        $response->assertSee('Download the LISTAA coupon design');
        $response->assertSee('Download the LISTBB coupon design');
    }

    public function test_the_download_icon_the_list_names_exists(): void
    {
        // An invented icon name renders an empty svg, which reads as a missing button
        // rather than as a mistake.
        $icons = file_get_contents(resource_path('views/components/admin/icon.blade.php'));

        $this->assertStringContainsString("@case('download')", $icons);
    }
}
