<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Services\Coupon\CouponRedeemer;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Generated codes must be readable off a screen.
 *
 * Not a style question. The owner read NG68BJ off his own screen and typed NG6883: the
 * B became an 8 and the J became a 3. The coupon's name IS what people type now, so a
 * character that gets misread is a discount that silently does not work.
 *
 * The other half is just as important and is asserted here too: narrowing the GENERATOR
 * must not narrow what REDEEMS. A name typed by hand may use any capital letter or
 * digit, and NG68BJ itself has to keep working.
 */
class CouponLegibleCodeTest extends CouponTestCase
{
    use RefreshDatabase;

    public function test_the_alphabet_excludes_every_confusable_character(): void
    {
        foreach (str_split(Coupon::CODE_EXCLUDED) as $character) {
            $this->assertStringNotContainsString(
                $character,
                Coupon::CODE_ALPHABET,
                $character.' is confusable and must not be in the generator alphabet.',
            );
        }

        // The pairs the owner actually tripped over, named rather than implied.
        foreach (['0', 'O', '1', 'I', 'L', '8', 'B', '5', 'S', '2', 'Z', 'J'] as $character) {
            $this->assertStringContainsString($character, Coupon::CODE_EXCLUDED);
        }

        // Still worth having: 24 characters over 6 places is 191 million.
        $this->assertSame(24, strlen(Coupon::CODE_ALPHABET));
        $this->assertSame(6, Coupon::CODE_LENGTH);
        $this->assertGreaterThan(100_000_000, 24 ** Coupon::CODE_LENGTH);
    }

    public function test_the_generator_never_emits_an_excluded_character(): void
    {
        $pattern = '/^['.preg_quote(Coupon::CODE_ALPHABET, '/').']{'.Coupon::CODE_LENGTH.'}$/';

        for ($i = 0; $i < 3000; $i++) {
            $code = Coupon::generateCode();

            $this->assertMatchesRegularExpression($pattern, $code);

            foreach (str_split(Coupon::CODE_EXCLUDED) as $character) {
                $this->assertStringNotContainsString($character, $code, $code.' contains '.$character.'.');
            }
        }
    }

    public function test_the_browser_generator_reads_the_same_alphabet_as_the_server(): void
    {
        // Two copies of the alphabet would eventually disagree, and the one on the
        // form is the one an operator actually presses.
        $this->actingAs($this->couponAdmin())
            ->get(route('admin.coupons.create'))
            ->assertOk()
            ->assertSee('const ALPHABET = "'.Coupon::CODE_ALPHABET.'"', false);
    }

    /* ---------------------------------------------------------------------
     | What is still accepted
     * ------------------------------------------------------------------ */

    public function test_a_hand_typed_name_full_of_excluded_characters_is_accepted(): void
    {
        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), [
                'kind' => Coupon::KIND_EVENT,
                'name' => 'SUKAN50',
                'quantity' => 3,
                'expires_at' => now()->addMonth()->toDateString(),
                'discount_type' => Coupon::DISCOUNT_FIXED,
                'discount_value' => 20,
                'design' => 'classic',
            ])
            ->assertSessionHasNoErrors();

        $coupon = Coupon::query()->sole();

        $this->assertSame('SUKAN50', $coupon->name);

        // And it redeems, which is the point of not tightening the rule.
        $outcome = app(CouponRedeemer::class)->claimByCode('sukan50', Coupon::KIND_EVENT, 100);

        $this->assertTrue($outcome->succeeded());
        $this->assertSame(20.0, $outcome->discount);
        $this->assertSame(2, $coupon->fresh()->remaining());
    }

    public function test_the_live_coupon_ng68bj_still_redeems(): void
    {
        // The exact row on the live database: event kind, unlimited, ticked on one
        // event. It must survive the change untouched.
        $event = $this->event(['fee' => 150]);

        $coupon = $this->coupon([
            'name' => 'NG68BJ',
            'kind' => Coupon::KIND_EVENT,
            'quantity' => 0,
            'expires_at' => '2026-11-21',
            'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
            'discount_value' => 10,
        ]);

        $event->coupons()->attach($coupon);

        // Offered on the public form.
        $this->get(route('registration'))
            ->assertOk()
            ->assertSee('name="voucher_code"', false);

        // Checks out.
        $this->postJson(route('voucher.check'), [
            'code' => 'NG68BJ',
            'scope' => Coupon::KIND_EVENT,
            'event' => $event->slug,
        ])->assertJson(['ok' => true, 'code' => 'NG68BJ']);

        // And redeems.
        $outcome = app(CouponRedeemer::class)->claimByCode('NG68BJ', Coupon::KIND_EVENT, 150);

        $this->assertTrue($outcome->succeeded());
        $this->assertSame(15.0, $outcome->discount);
        $this->assertSame('NG68BJ', $outcome->code->code);
    }
}
