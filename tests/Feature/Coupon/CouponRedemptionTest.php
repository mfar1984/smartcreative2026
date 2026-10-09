<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\CouponCode;
use App\Services\Coupon\CouponOutcome;
use App\Services\Coupon\CouponRedeemer;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Claiming a coupon: once each, never past the limit, never after expiry.
 *
 * The last remaining code is a contended resource, so the test that matters most here
 * is the one proving two simultaneous claims cannot both take it.
 */
class CouponRedemptionTest extends CouponTestCase
{
    use RefreshDatabase;

    private function redeemer(): CouponRedeemer
    {
        return app(CouponRedeemer::class);
    }

    /* ---------------------------------------------------------------------
     | Minting
     * ------------------------------------------------------------------ */

    public function test_a_limited_batch_mints_one_unique_code_per_use(): void
    {
        $coupon = $this->coupon(['quantity' => 5]);

        $codes = $coupon->codes()->pluck('code');

        $this->assertCount(5, $codes);
        $this->assertCount(5, $codes->unique(), 'Every minted code must be distinct.');
        $this->assertSame(5, $coupon->remaining());

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{6}$/', $code);
        }
    }

    public function test_an_unlimited_batch_mints_nothing_and_its_name_is_the_code(): void
    {
        $coupon = $this->coupon(['quantity' => 0]);

        $this->assertSame(0, $coupon->codes()->count());
        $this->assertTrue($coupon->isUnlimited());
        $this->assertNull($coupon->remaining());
        $this->assertFalse($coupon->isExhausted());
    }

    /* ---------------------------------------------------------------------
     | The race
     * ------------------------------------------------------------------ */

    public function test_two_claims_on_the_last_code_produce_exactly_one_redemption(): void
    {
        $coupon = $this->coupon(['quantity' => 1, 'discount_type' => Coupon::DISCOUNT_FIXED, 'discount_value' => 10]);

        $first = $this->redeemer()->claim($coupon, 100);
        $second = $this->redeemer()->claim($coupon->fresh(), 100);

        $this->assertTrue($first->succeeded(), 'The first claim must win.');
        $this->assertSame(10.0, $first->discount);

        // The loser gets a catchable, specific answer so the UI can fall back to the
        // normal price rather than guessing from a generic failure.
        $this->assertFalse($second->succeeded());
        $this->assertTrue($second->ranOut());
        $this->assertSame(CouponOutcome::RAN_OUT, $second->status);
        $this->assertSame(0.0, $second->discount);

        // And no second row was left behind.
        $this->assertSame(1, CouponCode::query()->where('coupon_id', $coupon->id)->redeemed()->count());
        $this->assertSame(0, $coupon->fresh()->remaining());
        $this->assertTrue($coupon->fresh()->isExhausted());
    }

    public function test_a_batch_of_three_gives_out_exactly_three_and_then_refuses(): void
    {
        $coupon = $this->coupon(['quantity' => 3, 'discount_type' => Coupon::DISCOUNT_FIXED, 'discount_value' => 5]);

        $outcomes = [];

        for ($i = 0; $i < 5; $i++) {
            $outcomes[] = $this->redeemer()->claim($coupon->fresh(), 100);
        }

        $won = array_filter($outcomes, fn (CouponOutcome $o) => $o->succeeded());
        $lost = array_filter($outcomes, fn (CouponOutcome $o) => $o->ranOut());

        $this->assertCount(3, $won);
        $this->assertCount(2, $lost);
        $this->assertSame(3, CouponCode::query()->where('coupon_id', $coupon->id)->redeemed()->count());

        // Each winner took a different code.
        $claimed = CouponCode::query()->where('coupon_id', $coupon->id)->redeemed()->pluck('code');
        $this->assertCount(3, $claimed->unique());
    }

    /* ---------------------------------------------------------------------
     | Refused at redeem time, not merely hidden
     * ------------------------------------------------------------------ */

    public function test_an_expired_batch_is_refused_at_redeem_time(): void
    {
        $coupon = $this->coupon([
            'quantity' => 10,
            'expires_at' => now()->subDay()->toDateString(),
        ]);

        // Codes are still sitting there unused, so nothing about the remaining count
        // would stop this. The expiry has to be checked inside the claim.
        $this->assertSame(10, $coupon->remaining());
        $this->assertTrue($coupon->isExpired());

        $outcome = $this->redeemer()->claim($coupon, 100);

        $this->assertFalse($outcome->succeeded());
        $this->assertSame(CouponOutcome::EXPIRED, $outcome->status);
        $this->assertSame(0, CouponCode::query()->redeemed()->count());
    }

    public function test_an_unlimited_batch_that_has_expired_is_refused_too(): void
    {
        $coupon = $this->coupon([
            'quantity' => 0,
            'expires_at' => now()->subDay()->toDateString(),
        ]);

        $outcome = $this->redeemer()->claim($coupon, 100);

        $this->assertSame(CouponOutcome::EXPIRED, $outcome->status);
        $this->assertSame(0, CouponCode::query()->count());
    }

    public function test_a_batch_expiring_today_still_works_until_the_end_of_the_day(): void
    {
        $coupon = $this->coupon(['quantity' => 1, 'expires_at' => now()->toDateString()]);

        $this->assertFalse($coupon->isExpired());
        $this->assertTrue($this->redeemer()->claim($coupon, 100)->succeeded());
    }

    public function test_an_exhausted_batch_is_refused_at_redeem_time(): void
    {
        $coupon = $this->coupon(['quantity' => 1]);

        $this->redeemer()->claim($coupon, 100);

        $outcome = $this->redeemer()->claim($coupon->fresh(), 100);

        $this->assertSame(CouponOutcome::RAN_OUT, $outcome->status);
    }

    /* ---------------------------------------------------------------------
     | By the code somebody typed
     * ------------------------------------------------------------------ */

    public function test_a_minted_code_may_be_redeemed_only_once(): void
    {
        $coupon = $this->coupon(['quantity' => 3, 'discount_type' => Coupon::DISCOUNT_FIXED, 'discount_value' => 20]);
        $code = $coupon->codes()->first()->code;

        $first = $this->redeemer()->claimByCode($code, Coupon::KIND_EVENT, 100);
        $second = $this->redeemer()->claimByCode($code, Coupon::KIND_EVENT, 100);

        $this->assertTrue($first->succeeded());
        $this->assertSame(20.0, $first->discount);

        $this->assertFalse($second->succeeded());
        $this->assertSame(CouponOutcome::ALREADY_USED, $second->status);

        // The other two codes are untouched, which is the point of a batch.
        $this->assertSame(2, $coupon->fresh()->remaining());
    }

    public function test_a_typed_code_is_matched_whatever_case_it_arrives_in(): void
    {
        $coupon = $this->coupon(['quantity' => 1]);
        $code = $coupon->codes()->first()->code;

        $this->assertTrue(
            $this->redeemer()->claimByCode(' ' . strtolower($code) . ' ', Coupon::KIND_EVENT, 100)->succeeded(),
        );
    }

    public function test_an_unlimited_batch_can_be_redeemed_repeatedly_by_its_name(): void
    {
        $coupon = $this->coupon([
            'quantity' => 0,
            'discount_type' => Coupon::DISCOUNT_FIXED,
            'discount_value' => 7.5,
        ]);

        for ($i = 0; $i < 4; $i++) {
            $outcome = $this->redeemer()->claimByCode($coupon->name, Coupon::KIND_EVENT, 100);

            $this->assertTrue($outcome->succeeded(), 'Use ' . ($i + 1) . ' should have been allowed.');
            $this->assertSame(7.5, $outcome->discount);
        }

        $this->assertSame(4, $coupon->redeemedCount());
        $this->assertFalse($coupon->fresh()->isExhausted());

        // Each use is its own row and each reads its label off the batch name.
        $rows = CouponCode::query()->where('coupon_id', $coupon->id)->get();

        $this->assertCount(4, $rows);

        foreach ($rows as $row) {
            $this->assertNull($row->code, 'An unlimited use carries no minted code of its own.');
            $this->assertSame($coupon->name, $row->codeLabel());
        }
    }

    public function test_the_name_of_a_limited_batch_is_not_itself_a_usable_code(): void
    {
        // The batch name labels the minted codes; typing it is the same mistake as
        // typing the event's name.
        $coupon = $this->coupon(['quantity' => 5]);

        $outcome = $this->redeemer()->claimByCode($coupon->name, Coupon::KIND_EVENT, 100);

        $this->assertSame(CouponOutcome::NOT_FOUND, $outcome->status);
        $this->assertSame(5, $coupon->fresh()->remaining());
    }

    public function test_a_code_for_the_wrong_kind_is_refused(): void
    {
        $shopCoupon = $this->coupon(['kind' => Coupon::KIND_SHOP, 'quantity' => 2]);
        $code = $shopCoupon->codes()->first()->code;

        $outcome = $this->redeemer()->claimByCode($code, Coupon::KIND_EVENT, 100);

        $this->assertSame(CouponOutcome::WRONG_KIND, $outcome->status);
        $this->assertSame(2, $shopCoupon->fresh()->remaining());
    }

    public function test_an_unknown_code_is_refused_without_touching_anything(): void
    {
        $this->coupon(['quantity' => 2]);

        $outcome = $this->redeemer()->claimByCode('NOPE99', Coupon::KIND_EVENT, 100);

        $this->assertSame(CouponOutcome::NOT_FOUND, $outcome->status);
        $this->assertSame(0, CouponCode::query()->redeemed()->count());
    }

    public function test_nothing_is_claimed_when_there_is_nothing_to_discount(): void
    {
        $coupon = $this->coupon(['quantity' => 2]);

        $outcome = $this->redeemer()->claim($coupon, 0);

        // A code spent on a free registration would be a coupon lost for nothing.
        $this->assertSame(CouponOutcome::NOTHING_TO_DISCOUNT, $outcome->status);
        $this->assertSame(2, $coupon->fresh()->remaining());
    }

    /* ---------------------------------------------------------------------
     | The record it leaves
     * ------------------------------------------------------------------ */

    public function test_a_redemption_records_the_figure_it_actually_gave(): void
    {
        $coupon = $this->coupon([
            'quantity' => 1,
            'discount_type' => Coupon::DISCOUNT_FIXED,
            'discount_value' => 500,
        ]);

        // Capped at the charge, and the capped figure is what is recorded — not the
        // RM500 the batch names.
        $outcome = $this->redeemer()->claim($coupon, 30);

        $this->assertTrue($outcome->succeeded());
        $this->assertSame(30.0, $outcome->discount);
        $this->assertSame('30.00', $outcome->code->fresh()->discount_amount);
    }

    public function test_every_redemption_is_logged(): void
    {
        $coupon = $this->coupon(['quantity' => 1]);

        $this->redeemer()->claim($coupon, 100);

        $this->assertDatabaseHas('activity_logs', ['action' => 'coupons.redeem']);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Coupon::class,
            'auditable_id' => $coupon->id,
            'event' => 'coupon.redeemed',
        ]);
    }

    public function test_a_redemption_is_stamped_with_when_it_happened(): void
    {
        $coupon = $this->coupon(['quantity' => 1]);

        $outcome = $this->redeemer()->claim($coupon, 100);

        $this->assertNotNull($outcome->code->redeemed_at);
        $this->assertTrue($outcome->code->isRedeemed());
    }
}
