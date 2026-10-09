<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\CouponCode;
use App\Services\Coupon\CouponOutcome;
use App\Services\Coupon\CouponRedeemer;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Claiming a coupon: the name is the code, never past the cap, never after expiry.
 *
 * The last remaining use is a contended resource, so the test that matters most here
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
     | Nothing is pre-minted
     * ------------------------------------------------------------------ */

    public function test_a_capped_coupon_writes_no_rows_until_somebody_uses_it(): void
    {
        $coupon = $this->coupon(['quantity' => 5]);

        $this->assertSame(0, $coupon->codes()->count(), 'Nothing may be minted up front.');
        $this->assertSame(5, $coupon->remaining());
        $this->assertFalse($coupon->isExhausted());
    }

    public function test_an_unlimited_coupon_has_no_remaining_count(): void
    {
        $coupon = $this->coupon(['quantity' => 0]);

        $this->assertSame(0, $coupon->codes()->count());
        $this->assertTrue($coupon->isUnlimited());
        $this->assertNull($coupon->remaining());
        $this->assertFalse($coupon->isExhausted());
    }

    public function test_the_name_of_a_capped_coupon_redeems(): void
    {
        // The whole change: a limit no longer means the name is not the code.
        $coupon = $this->coupon([
            'quantity' => 4,
            'discount_type' => Coupon::DISCOUNT_FIXED,
            'discount_value' => 12,
        ]);

        $outcome = $this->redeemer()->claimByCode($coupon->name, Coupon::KIND_EVENT, 100);

        $this->assertTrue($outcome->succeeded());
        $this->assertSame(12.0, $outcome->discount);
        $this->assertSame(3, $coupon->fresh()->remaining());

        // The ledger row records the string that was typed.
        $this->assertSame($coupon->name, $outcome->code->code);
        $this->assertSame($coupon->name, $outcome->code->codeLabel());
    }

    /* ---------------------------------------------------------------------
     | The race
     * ------------------------------------------------------------------ */

    public function test_two_claims_on_the_last_use_produce_exactly_one_redemption(): void
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
        $this->assertSame(1, CouponCode::query()->where('coupon_id', $coupon->id)->count());
        $this->assertSame(0, $coupon->fresh()->remaining());
        $this->assertTrue($coupon->fresh()->isExhausted());
    }

    public function test_a_cap_of_three_gives_out_exactly_three_and_then_refuses(): void
    {
        $coupon = $this->coupon(['quantity' => 3, 'discount_type' => Coupon::DISCOUNT_FIXED, 'discount_value' => 5]);

        $outcomes = [];

        for ($i = 0; $i < 5; $i++) {
            $outcomes[] = $this->redeemer()->claimByCode($coupon->name, Coupon::KIND_EVENT, 100);
        }

        $won = array_filter($outcomes, fn (CouponOutcome $o) => $o->succeeded());
        $lost = array_filter($outcomes, fn (CouponOutcome $o) => $o->ranOut());

        $this->assertCount(3, $won);
        $this->assertCount(2, $lost);
        $this->assertSame(3, CouponCode::query()->where('coupon_id', $coupon->id)->redeemed()->count());

        // Every winner typed the same code, which is the point of it.
        $claimed = CouponCode::query()->where('coupon_id', $coupon->id)->redeemed()->pluck('code');
        $this->assertSame([$coupon->name], $claimed->unique()->values()->all());
    }

    public function test_an_unlimited_coupon_is_never_capped(): void
    {
        $coupon = $this->coupon([
            'quantity' => 0,
            'discount_type' => Coupon::DISCOUNT_FIXED,
            'discount_value' => 3,
        ]);

        for ($i = 0; $i < 12; $i++) {
            $this->assertTrue(
                $this->redeemer()->claimByCode($coupon->name, Coupon::KIND_EVENT, 100)->succeeded(),
                'Use ' . ($i + 1) . ' should have been allowed.',
            );
        }

        $this->assertSame(12, $coupon->fresh()->redeemedCount());
        $this->assertNull($coupon->fresh()->remaining());
        $this->assertFalse($coupon->fresh()->isExhausted());
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

        // Ten uses are still going spare, so nothing about the remaining count would
        // stop this. The expiry has to be checked inside the claim.
        $this->assertSame(10, $coupon->remaining());
        $this->assertTrue($coupon->isExpired());

        $outcome = $this->redeemer()->claim($coupon, 100);

        $this->assertFalse($outcome->succeeded());
        $this->assertSame(CouponOutcome::EXPIRED, $outcome->status);
        $this->assertSame(0, CouponCode::query()->redeemed()->count());
    }

    public function test_an_unlimited_coupon_that_has_expired_is_refused_too(): void
    {
        $coupon = $this->coupon([
            'quantity' => 0,
            'expires_at' => now()->subDay()->toDateString(),
        ]);

        $outcome = $this->redeemer()->claim($coupon, 100);

        $this->assertSame(CouponOutcome::EXPIRED, $outcome->status);
        $this->assertSame(0, CouponCode::query()->count());
    }

    public function test_a_coupon_expiring_today_still_works_until_the_end_of_the_day(): void
    {
        $coupon = $this->coupon(['quantity' => 1, 'expires_at' => now()->toDateString()]);

        $this->assertFalse($coupon->isExpired());
        $this->assertTrue($this->redeemer()->claim($coupon, 100)->succeeded());
    }

    public function test_an_exhausted_coupon_is_refused_at_redeem_time(): void
    {
        $coupon = $this->coupon(['quantity' => 1]);

        $this->redeemer()->claim($coupon, 100);

        $outcome = $this->redeemer()->claim($coupon->fresh(), 100);

        $this->assertSame(CouponOutcome::RAN_OUT, $outcome->status);
    }

    /* ---------------------------------------------------------------------
     | By the code somebody typed
     * ------------------------------------------------------------------ */

    public function test_a_typed_code_is_matched_whatever_case_it_arrives_in(): void
    {
        $coupon = $this->coupon(['quantity' => 1]);
        $code = $coupon->name;

        $this->assertTrue(
            $this->redeemer()->claimByCode(' ' . strtolower($code) . ' ', Coupon::KIND_EVENT, 100)->succeeded(),
        );
    }

    public function test_an_unlimited_coupon_can_be_redeemed_repeatedly_by_its_name(): void
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

        // Each use is its own ledger row, carrying the code that was typed.
        $rows = CouponCode::query()->where('coupon_id', $coupon->id)->get();

        $this->assertCount(4, $rows);

        foreach ($rows as $row) {
            $this->assertTrue($row->isRedeemed(), 'A ledger row only exists once it is used.');
            $this->assertSame($coupon->name, $row->code);
            $this->assertSame($coupon->name, $row->codeLabel());
        }
    }

    public function test_a_name_containing_a_confusable_character_still_redeems(): void
    {
        /*
         | The generator avoids 0 O 1 I L 8 B 5 S 2 Z J, but a name typed by hand may
         | use any of them — and the live coupon NG68BJ has three. Narrowing the
         | generator must never narrow what redeems.
         */
        $coupon = $this->coupon([
            'name' => 'NG68BJ',
            'quantity' => 2,
            'discount_type' => Coupon::DISCOUNT_FIXED,
            'discount_value' => 9,
        ]);

        $outcome = $this->redeemer()->claimByCode('ng68bj', Coupon::KIND_EVENT, 100);

        $this->assertTrue($outcome->succeeded(), 'NG68BJ must still redeem.');
        $this->assertSame(9.0, $outcome->discount);
        $this->assertSame(1, $coupon->fresh()->remaining());
    }

    public function test_a_code_for_the_wrong_kind_is_refused(): void
    {
        $shopCoupon = $this->coupon(['kind' => Coupon::KIND_SHOP, 'quantity' => 2]);
        $code = $shopCoupon->name;

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

        // A use spent on a free registration would be a coupon lost for nothing.
        $this->assertSame(CouponOutcome::NOTHING_TO_DISCOUNT, $outcome->status);
        $this->assertSame(2, $coupon->fresh()->remaining());
        $this->assertSame(0, CouponCode::query()->count());
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
