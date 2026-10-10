<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Services\Coupon\CouponRedeemer;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The Voucher Code box appears only where a coupon has actually been ticked.
 *
 * The owner's rule, and the whole of it: ticking a coupon on the event or on a shop
 * product is what puts the field on the public page. No tick, no field — not a
 * disabled one, not one that refuses everything.
 *
 * An expired or used-up batch left ticked is deliberately NOT an offer either. The
 * price falls back to normal and the Payment button comes back, which is what the
 * owner asked for, rather than a visitor being invited to type a code that cannot
 * work.
 */
class CouponVoucherFieldTest extends CouponTestCase
{
    use RefreshDatabase;

    private const FIELD = 'name="voucher_code"';

    /* ---------------------------------------------------------------------
     | The registration form
     * ------------------------------------------------------------------ */

    public function test_the_field_is_absent_when_no_coupon_is_ticked_on_the_event(): void
    {
        $this->event(['fee' => 100]);

        // A batch exists and is perfectly usable. It is simply not ticked here.
        $this->percentageCoupon(30, ['quantity' => 5]);

        $response = $this->get(route('registration'));

        $response->assertOk();
        $response->assertDontSee(self::FIELD, false);
        $response->assertDontSee('Voucher Code');
    }

    public function test_the_field_appears_once_a_usable_coupon_is_ticked(): void
    {
        $event = $this->event(['fee' => 100]);
        $coupon = $this->percentageCoupon(30, ['quantity' => 5]);

        $event->coupons()->attach($coupon);

        $response = $this->get(route('registration'));

        $response->assertOk();
        $response->assertSee(self::FIELD, false);
        $response->assertSee('Voucher Code');

        // Scoped to this event, so a code is checked against the right batches.
        $response->assertSee('data-voucher-event="'.$event->slug.'"', false);
    }

    public function test_the_field_is_absent_when_every_ticked_batch_has_expired(): void
    {
        $event = $this->event(['fee' => 100]);

        $expired = $this->percentageCoupon(30, [
            'quantity' => 5,
            'expires_at' => now()->subDay()->toDateString(),
        ]);

        $event->coupons()->attach($expired);

        $this->assertTrue($expired->isExpired());

        $this->get(route('registration'))
            ->assertOk()
            ->assertDontSee(self::FIELD, false);
    }

    public function test_the_field_is_absent_when_every_ticked_batch_is_used_up(): void
    {
        $event = $this->event(['fee' => 100]);
        $coupon = $this->percentageCoupon(30, ['quantity' => 1]);

        $event->coupons()->attach($coupon);

        app(CouponRedeemer::class)->claim($coupon, 100);

        $this->assertTrue($coupon->fresh()->isExhausted());

        $this->get(route('registration'))
            ->assertOk()
            ->assertDontSee(self::FIELD, false);
    }

    public function test_one_usable_batch_among_used_up_ones_is_enough(): void
    {
        // The owner's case exactly: a batch runs out, a fresh one is created and
        // ticked beside it, and the field comes back.
        $event = $this->event(['fee' => 100]);

        $spent = $this->percentageCoupon(30, ['quantity' => 1]);
        $fresh = $this->percentageCoupon(30, ['quantity' => 10]);

        $event->coupons()->attach([$spent->id, $fresh->id]);

        app(CouponRedeemer::class)->claim($spent, 100);

        $this->get(route('registration'))
            ->assertOk()
            ->assertSee(self::FIELD, false);
    }

    public function test_a_shop_coupon_ticked_on_an_event_is_not_an_offer(): void
    {
        // Attachable, because the pivot does not know about kinds. The availability
        // rule does, and the event form only ever offers event batches.
        $event = $this->event(['fee' => 100]);
        $shopBatch = $this->percentageCoupon(30, ['kind' => Coupon::KIND_SHOP, 'quantity' => 5]);

        $event->coupons()->attach($shopBatch);

        $this->get(route('registration'))
            ->assertOk()
            ->assertDontSee(self::FIELD, false);
    }

    public function test_a_free_event_is_not_offered_a_voucher_box(): void
    {
        // Nothing to discount, so a code could only ever answer "there is nothing to
        // discount" and would spend nothing even if it were accepted.
        $event = $this->event(['fee' => null]);
        $coupon = $this->percentageCoupon(30, ['quantity' => 5]);

        $event->coupons()->attach($coupon);

        $this->get(route('registration'))
            ->assertOk()
            ->assertDontSee(self::FIELD, false);
    }

    /* ---------------------------------------------------------------------
     | The shop checkout
     * ------------------------------------------------------------------ */

    public function test_the_checkout_field_is_absent_when_nothing_in_the_basket_carries_a_coupon(): void
    {
        $this->shopOpenWithGateway();
        $this->flatShipping();

        $product = $this->product(['price' => 50]);
        $this->percentageCoupon(30, ['kind' => Coupon::KIND_SHOP, 'quantity' => 5]);

        $this->withSession($this->basket($product))
            ->get(route('checkout'))
            ->assertOk()
            ->assertDontSee(self::FIELD, false);
    }

    public function test_the_checkout_field_appears_once_a_product_in_the_basket_carries_one(): void
    {
        $this->shopOpenWithGateway();
        $this->flatShipping();

        $product = $this->product(['price' => 50]);
        $coupon = $this->percentageCoupon(30, ['kind' => Coupon::KIND_SHOP, 'quantity' => 5]);

        $product->coupons()->attach($coupon);

        $this->withSession($this->basket($product))
            ->get(route('checkout'))
            ->assertOk()
            ->assertSee(self::FIELD, false)
            ->assertSee('Voucher Code');
    }

    public function test_the_checkout_field_is_absent_when_the_ticked_batch_is_used_up(): void
    {
        $this->shopOpenWithGateway();
        $this->flatShipping();

        $product = $this->product(['price' => 50]);
        $coupon = $this->percentageCoupon(30, ['kind' => Coupon::KIND_SHOP, 'quantity' => 1]);

        $product->coupons()->attach($coupon);

        app(CouponRedeemer::class)->claim($coupon, 50);

        $this->withSession($this->basket($product))
            ->get(route('checkout'))
            ->assertOk()
            ->assertDontSee(self::FIELD, false);
    }

    /* ---------------------------------------------------------------------
     | Checking a code
     |
     | Read only. Nothing here may claim anything, which is the assertion that keeps
     | the check endpoint from quietly becoming a second redemption path.
     * ------------------------------------------------------------------ */

    public function test_checking_a_code_reports_its_terms_and_claims_nothing(): void
    {
        $event = $this->event(['fee' => 100]);
        $coupon = $this->percentageCoupon(30, ['quantity' => 5]);
        $event->coupons()->attach($coupon);

        $code = $coupon->name;

        $response = $this->postJson(route('voucher.check'), [
            'code' => $code,
            'scope' => Coupon::KIND_EVENT,
            'event' => $event->slug,
        ]);

        $response->assertOk();
        $response->assertJson([
            'ok' => true,
            'code' => $code,
            'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
            'discount_value' => 30,
            'per_head' => false,
        ]);

        // The coupon in its own design, drawn server side.
        $this->assertStringContainsString('30% OFF', $response->json('ticket'));
        $this->assertStringContainsString($code, $response->json('ticket'));

        // And nothing was spent.
        $this->assertSame(5, $coupon->fresh()->remaining());
        $this->assertSame(0, $coupon->fresh()->redeemedCount());
    }

    public function test_checking_reports_the_per_head_rule_for_a_fixed_discount(): void
    {
        $event = $this->event([
            'registration_mode' => \App\Models\Event::MODE_GROUPING,
            'charges_addons_per_participant' => true,
            'fee' => 100,
            'min_players' => 1,
            'max_players' => 20,
        ]);

        $coupon = $this->fixedCoupon(10, ['quantity' => 5]);
        $event->coupons()->attach($coupon);

        $this->postJson(route('voucher.check'), [
            'code' => $coupon->name,
            'scope' => Coupon::KIND_EVENT,
            'event' => $event->slug,
        ])->assertJson(['ok' => true, 'per_head' => true]);
    }

    public function test_a_percentage_is_never_owed_per_head(): void
    {
        // Multiplying a percentage per head would turn 34% into 102%, which the cap
        // would then silently read as free. Same rule as CouponDiscount.
        $event = $this->event([
            'registration_mode' => \App\Models\Event::MODE_GROUPING,
            'charges_addons_per_participant' => true,
            'fee' => 100,
            'min_players' => 1,
            'max_players' => 20,
        ]);

        $coupon = $this->percentageCoupon(34, ['quantity' => 5]);
        $event->coupons()->attach($coupon);

        $this->postJson(route('voucher.check'), [
            'code' => $coupon->name,
            'scope' => Coupon::KIND_EVENT,
            'event' => $event->slug,
        ])->assertJson(['ok' => true, 'per_head' => false]);
    }

    public function test_a_code_for_a_batch_that_is_not_ticked_here_is_refused(): void
    {
        $event = $this->event(['fee' => 100]);
        $ticked = $this->percentageCoupon(30, ['quantity' => 5]);
        $other = $this->percentageCoupon(50, ['quantity' => 5]);

        $event->coupons()->attach($ticked);

        $response = $this->postJson(route('voucher.check'), [
            'code' => $other->name,
            'scope' => Coupon::KIND_EVENT,
            'event' => $event->slug,
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => false]);
        $this->assertSame('That coupon cannot be used here.', $response->json('message'));
    }

    public function test_each_refusal_gets_its_own_message(): void
    {
        $event = $this->event(['fee' => 100]);

        $expired = $this->percentageCoupon(30, [
            'quantity' => 2,
            'expires_at' => now()->subDay()->toDateString(),
        ]);
        $spent = $this->percentageCoupon(30, ['quantity' => 1]);
        $live = $this->percentageCoupon(30, ['quantity' => 2]);

        // A perfectly good batch that is simply not ticked on this event.
        $elsewhere = $this->percentageCoupon(30, ['quantity' => 2]);

        $event->coupons()->attach([$expired->id, $spent->id, $live->id]);

        app(CouponRedeemer::class)->claim($spent, 100);

        $cases = [
            // Each of these is told the specific thing it needs to know, even though
            // an expired or spent coupon has dropped off the offer list.
            [$expired->name, 'That coupon has expired, so the normal price applies.'],
            [$spent->name, 'That coupon has been fully used, so the normal price applies.'],
            [$elsewhere->name, 'That coupon cannot be used here.'],
            ['NOSUCH', 'That coupon code was not recognised.'],
        ];

        foreach ($cases as [$code, $expected]) {
            $response = $this->postJson(route('voucher.check'), [
                'code' => $code,
                'scope' => Coupon::KIND_EVENT,
                'event' => $event->slug,
            ]);

            $response->assertJson(['ok' => false]);
            $this->assertSame($expected, $response->json('message'), 'Wrong message for '.$code);
        }
    }

    public function test_the_name_of_a_capped_coupon_is_the_code_and_checks_out(): void
    {
        // The reversal. A limit no longer means the name is not the code.
        $event = $this->event(['fee' => 100]);
        $coupon = $this->percentageCoupon(30, ['quantity' => 5]);
        $event->coupons()->attach($coupon);

        $this->postJson(route('voucher.check'), [
            'code' => $coupon->name,
            'scope' => Coupon::KIND_EVENT,
            'event' => $event->slug,
        ])->assertJson(['ok' => true, 'code' => $coupon->name]);

        // Still a read: nothing was spent.
        $this->assertSame(5, $coupon->fresh()->remaining());
    }

    public function test_an_unlimited_coupon_is_checked_by_its_name(): void
    {
        $event = $this->event(['fee' => 100]);
        $coupon = $this->percentageCoupon(30, ['quantity' => 0]);
        $event->coupons()->attach($coupon);

        $this->postJson(route('voucher.check'), [
            'code' => strtolower($coupon->name),
            'scope' => Coupon::KIND_EVENT,
            'event' => $event->slug,
        ])->assertJson(['ok' => true, 'code' => $coupon->name]);
    }

    public function test_checking_against_an_event_offering_nothing_says_nothing_useful(): void
    {
        // Nothing is ticked, so there is no box. A stranger poking at the endpoint
        // learns nothing about which codes exist.
        $event = $this->event(['fee' => 100]);
        $coupon = $this->percentageCoupon(30, ['quantity' => 5]);

        $this->postJson(route('voucher.check'), [
            'code' => $coupon->name,
            'scope' => Coupon::KIND_EVENT,
            'event' => $event->slug,
        ])->assertJson(['ok' => false, 'message' => 'That coupon code was not recognised.']);
    }

    public function test_checking_a_shop_code_against_the_basket(): void
    {
        $this->shopOpenWithGateway();

        $product = $this->product(['price' => 50]);
        $coupon = $this->fixedCoupon(15, ['kind' => Coupon::KIND_SHOP, 'quantity' => 3]);
        $product->coupons()->attach($coupon);

        $this->withSession($this->basket($product))
            ->postJson(route('voucher.check'), [
                'code' => $coupon->name,
                'scope' => Coupon::KIND_SHOP,
            ])
            ->assertJson([
                'ok' => true,
                'discount_type' => Coupon::DISCOUNT_FIXED,
                'discount_value' => 15,
                'per_head' => false,
            ]);

        $this->assertSame(3, $coupon->fresh()->remaining());
    }

    public function test_an_event_code_cannot_be_checked_against_the_shop(): void
    {
        $this->shopOpenWithGateway();

        $product = $this->product(['price' => 50]);
        $shopBatch = $this->fixedCoupon(15, ['kind' => Coupon::KIND_SHOP, 'quantity' => 3]);
        $eventBatch = $this->fixedCoupon(15, ['kind' => Coupon::KIND_EVENT, 'quantity' => 3]);

        $product->coupons()->attach($shopBatch);

        $this->withSession($this->basket($product))
            ->postJson(route('voucher.check'), [
                'code' => $eventBatch->name,
                'scope' => Coupon::KIND_SHOP,
            ])
            ->assertJson(['ok' => false, 'message' => 'That coupon cannot be used here.']);
    }

    public function test_the_scope_has_to_be_one_we_know(): void
    {
        $this->postJson(route('voucher.check'), ['code' => 'ABC123', 'scope' => 'something'])
            ->assertStatus(422);
    }
}
