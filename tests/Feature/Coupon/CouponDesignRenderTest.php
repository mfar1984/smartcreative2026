<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Support\CouponTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;

/**
 * Every design draws the same four facts, whatever the coupon says.
 *
 * The discount, what it is for, the code and the expiry. A design that dropped one of
 * them would look fine on screen and hand somebody a coupon they could not use, which
 * is why this is asserted for every key in Coupon::DESIGNS rather than for the ones
 * that happened to be written first: a new design added later is tested the moment it
 * is listed.
 *
 * Both discount shapes are driven through all of them. "30% OFF" and "RM 25.00" sit in
 * the same slot, and the ringgit figure is the one that strains a layout built around
 * a short number.
 */
class CouponDesignRenderTest extends CouponTestCase
{
    use RefreshDatabase;

    /** A title long enough to spill out of any panel that cannot wrap. */
    private const LONG_TITLE = 'Hari Sukan Negara 2026 . Kejohanan Bola Sepak Peringkat Bahagian Sibu, Sarawak';

    /**
     * @return array<int, array<int, string>>
     */
    public static function designs(): array
    {
        return array_map(fn (string $key) => [$key], array_keys(Coupon::DESIGNS));
    }

    private function render(Coupon $coupon, string $subject, ?string $code = null): string
    {
        return Blade::render(
            '<x-coupon.ticket :coupon="$coupon" :subject="$subject" :code="$code" />',
            ['coupon' => $coupon, 'subject' => $subject, 'code' => $code],
        );
    }

    /* ---------------------------------------------------------------------
     | The four facts
     * ------------------------------------------------------------------ */

    #[\PHPUnit\Framework\Attributes\DataProvider('designs')]
    public function test_a_percentage_coupon_renders_in_every_design(string $design): void
    {
        $coupon = $this->percentageCoupon(30, ['design' => $design, 'quantity' => 1]);
        $code = $coupon->name;

        $html = $this->render($coupon, self::LONG_TITLE, $code);
        $ticket = CouponTicket::for($coupon, self::LONG_TITLE, $code);

        // The discount. Asserted on the figure rather than the whole label, because
        // 'bold' stacks the number over its unit instead of printing them on one line.
        $this->assertStringContainsString($ticket->heroValue(), $html, $design . ' lost the discount.');
        $this->assertTrue(
            str_contains($html, '%') || str_contains($html, 'PERCENT'),
            $design . ' does not say the discount is a percentage.',
        );

        $this->assertStringContainsString($code, $html, $design . ' lost the code.');
        $this->assertStringContainsString($coupon->expiresLabel(), $html, $design . ' lost the expiry.');

        // What it is for, in full. A title cut short on the server would be a design
        // deciding what the coupon says.
        $this->assertStringContainsString(e(self::LONG_TITLE), $html, $design . ' lost what it is for.');

        // Long values are wrapped rather than clipped, which is what keeps a narrow
        // phone from losing the end of a code.
        $this->assertStringContainsString('break-', $html, $design . ' has nothing to wrap long values.');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('designs')]
    public function test_a_ringgit_coupon_renders_in_every_design(string $design): void
    {
        $coupon = $this->fixedCoupon(25, ['design' => $design, 'quantity' => 1]);
        $code = $coupon->name;

        $html = $this->render($coupon, self::LONG_TITLE, $code);

        $this->assertStringContainsString('25.00', $html, $design . ' lost the discount.');
        $this->assertTrue(
            str_contains($html, 'RM') || str_contains($html, 'RINGGIT'),
            $design . ' does not say the discount is in ringgit.',
        );

        $this->assertStringContainsString($code, $html, $design . ' lost the code.');
        $this->assertStringContainsString($coupon->expiresLabel(), $html, $design . ' lost the expiry.');
        $this->assertStringContainsString(e(self::LONG_TITLE), $html, $design . ' lost what it is for.');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('designs')]
    public function test_a_large_ringgit_figure_is_stepped_down_rather_than_clipped(string $design): void
    {
        // The case the owner flagged: 'bold' sizes its hero block for one short
        // number, so a four figure sum has to shrink to fit rather than spill out.
        $coupon = $this->fixedCoupon(1250, ['design' => $design, 'quantity' => 1]);

        $html = $this->render($coupon, 'Hari Sukan Negara 2026', $coupon->name);
        $ticket = CouponTicket::for($coupon);

        $this->assertStringContainsString('1,250.00', $html);

        // Stepped down from the size a two digit figure gets.
        $this->assertNotSame(
            CouponTicket::for($this->fixedCoupon(25))->heroSizeClass(),
            $ticket->heroSizeClass(),
        );
    }

    /* ---------------------------------------------------------------------
     | The custom one
     * ------------------------------------------------------------------ */

    public function test_the_custom_design_draws_the_uploaded_artwork_and_the_facts(): void
    {
        $coupon = $this->percentageCoupon(15, [
            'design' => Coupon::DESIGN_CUSTOM,
            'quantity' => 1,
        ]);

        $coupon->design_path = Coupon::DESIGN_DIRECTORY . '/sample.png';
        $coupon->save();

        $code = $coupon->name;
        $html = $this->render($coupon->fresh(), 'Hari Sukan Negara 2026', $code);

        $this->assertStringContainsString('sample.png', $html);

        // The artwork decorates it; the four facts are still printed, because nobody
        // knows what is in an uploaded image and a code nobody can read is useless.
        $this->assertStringContainsString('15% OFF', $html);
        $this->assertStringContainsString($code, $html);
        $this->assertStringContainsString($coupon->expiresLabel(), $html);
    }

    public function test_a_custom_design_with_no_artwork_falls_back_rather_than_drawing_nothing(): void
    {
        // Saved as custom and the picture removed afterwards. Still has to be readable
        // by whoever is holding the code.
        $coupon = $this->percentageCoupon(15, ['design' => Coupon::DESIGN_CUSTOM, 'quantity' => 1]);
        $code = $coupon->name;

        $html = $this->render($coupon, 'Hari Sukan Negara 2026', $code);

        $this->assertStringContainsString('15% OFF', $html);
        $this->assertStringContainsString($code, $html);
        $this->assertStringContainsString($coupon->expiresLabel(), $html);
    }

    public function test_a_design_key_with_no_component_falls_back_to_classic(): void
    {
        // A batch created on a release that had a design this one does not. It must
        // still render rather than throw at the person holding the coupon.
        $coupon = $this->percentageCoupon(20, ['quantity' => 1]);
        $coupon->forceFill(['design' => 'something-we-removed'])->save();

        $ticket = CouponTicket::for($coupon->fresh());

        $this->assertSame('coupon.designs.classic', $ticket->component());
        $this->assertStringContainsString('20% OFF', $this->render($coupon->fresh(), 'An event'));
    }

    /* ---------------------------------------------------------------------
     | What a code reads as
     * ------------------------------------------------------------------ */

    public function test_an_unlimited_batch_draws_its_own_name_as_the_code(): void
    {
        // Nothing is minted for an unlimited batch: the batch name is what people
        // type, so it is what the coupon has to print.
        $coupon = $this->percentageCoupon(10, ['quantity' => 0]);

        $this->assertStringContainsString($coupon->name, $this->render($coupon, 'An event'));
    }

    public function test_the_summary_spells_the_coupon_out_for_a_screen_reader(): void
    {
        $coupon = $this->fixedCoupon(25, ['quantity' => 1]);
        $code = $coupon->name;

        $summary = CouponTicket::for($coupon, 'Hari Sukan Negara 2026', $code)->summary();

        $this->assertStringContainsString($code, $summary);
        $this->assertStringContainsString('RM 25.00', $summary);
        $this->assertStringContainsString('Hari Sukan Negara 2026', $summary);
        $this->assertStringContainsString($coupon->expiresLabel(), $summary);
    }
}
