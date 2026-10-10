<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\CouponHolder;
use App\Models\Event;
use App\Services\Coupon\CouponOutcome;
use App\Services\Coupon\RegistrationCouponWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Creating a coupon in either mode, through the form the operator actually uses.
 *
 * The owner's words for the workflow: create the code as usual, and the form simply
 * gains who will handle it, which is optional. So the shared path has to be exactly as
 * familiar as it was, and the unique path has to be the same form with two more
 * questions on it.
 */
class CouponModeFormTest extends CouponTestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'kind' => Coupon::KIND_EVENT,
            'mode' => Coupon::MODE_SHARED,
            'name' => 'ABC123',
            'quantity' => 0,
            'expires_at' => now()->addMonth()->toDateString(),
            'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
            'discount_value' => 10,
            'design' => 'classic',
        ];
    }

    /* ---------------------------------------------------------------------
     | The form offers both
     * ------------------------------------------------------------------ */

    public function test_the_form_offers_both_ways_of_working(): void
    {
        $response = $this->actingAs($this->couponAdmin())->get(route('admin.coupons.create'));

        $response->assertOk();
        $response->assertSee('How The Codes Work');
        $response->assertSee('One shared code');
        $response->assertSee('Individual codes');

        // The handler fields, and the sponsorship figure beside the discount.
        $response->assertSee('Who Will Handle These');
        $response->assertSee('Sponsorship Committed');
    }

    /* ---------------------------------------------------------------------
     | Shared, exactly as before
     * ------------------------------------------------------------------ */

    public function test_a_shared_coupon_is_created_exactly_as_before(): void
    {
        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $this->form(['name' => 'POSTER1', 'quantity' => 50]))
            ->assertRedirect(route('admin.coupons.index'));

        $coupon = Coupon::query()->where('name', 'POSTER1')->sole();

        $this->assertTrue($coupon->isShared());
        $this->assertSame(50, (int) $coupon->quantity);

        // Nothing minted, no blocks, no handlers.
        $this->assertSame(0, $coupon->issuedCodes()->count());
        $this->assertSame(0, $coupon->allocations()->count());
        $this->assertSame(0, $coupon->holders()->count());
    }

    public function test_a_submission_with_no_mode_at_all_is_shared(): void
    {
        $fields = $this->form(['name' => 'NOMODE1']);
        unset($fields['mode']);

        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $fields)
            ->assertSessionHasNoErrors();

        $this->assertTrue(Coupon::query()->where('name', 'NOMODE1')->sole()->isShared());
    }

    /* ---------------------------------------------------------------------
     | Unique, created with its first block
     * ------------------------------------------------------------------ */

    public function test_creating_in_unique_mode_generates_the_first_block(): void
    {
        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $this->form([
                'name' => 'SPONSOR1',
                'mode' => Coupon::MODE_UNIQUE,
                'quantity' => 100,
                'committed_amount' => 2000,
                'holder_full_name' => 'Siti Nurhaliza',
                'holder_email' => 'siti@ngo.test',
                'holder_ic_number' => '880202-13-1122',
                'holder_phone' => '0123334444',
            ]))
            ->assertSessionHasNoErrors();

        $coupon = Coupon::query()->where('name', 'SPONSOR1')->sole();

        $this->assertTrue($coupon->isUnique());
        $this->assertSame('2000.00', $coupon->committed_amount);

        // One block of a hundred, and the batch cap follows the stock.
        $this->assertSame(1, $coupon->allocations()->count());
        $this->assertSame(100, $coupon->issuedCodes()->count());
        $this->assertSame(100, (int) $coupon->quantity);

        $block = $coupon->allocations()->with('holder')->sole();

        $this->assertSame('Siti Nurhaliza', $block->holderLabel());
        $this->assertSame('siti@ngo.test', $block->holder->email);
        $this->assertSame('880202-13-1122', $block->holder->ic_number);
        $this->assertSame('0123334444', $block->holder->phone);
    }

    public function test_it_lands_on_the_batch_detail_so_the_codes_can_be_read(): void
    {
        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $this->form([
                'name' => 'SPONSOR2',
                'mode' => Coupon::MODE_UNIQUE,
                'quantity' => 5,
            ]))
            ->assertRedirect(route('admin.coupons.report.show', Coupon::query()->where('name', 'SPONSOR2')->sole()))
            ->assertSessionHas('status');
    }

    public function test_a_unique_coupon_may_be_created_with_nobody_handling_it(): void
    {
        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $this->form([
                'name' => 'SPONSOR3',
                'mode' => Coupon::MODE_UNIQUE,
                'quantity' => 5,
            ]))
            ->assertSessionHasNoErrors();

        $coupon = Coupon::query()->where('name', 'SPONSOR3')->sole();

        $this->assertSame(5, $coupon->issuedCodes()->count());
        $this->assertFalse($coupon->allocations()->sole()->hasHolder());
        $this->assertSame(0, CouponHolder::query()->count());
    }

    public function test_unique_mode_refuses_an_unlimited_quantity(): void
    {
        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $this->form([
                'name' => 'SPONSOR4',
                'mode' => Coupon::MODE_UNIQUE,
                'quantity' => 0,
            ]))
            ->assertSessionHasErrors('quantity');

        // Unlimited has no meaning for codes you print and hand over: there would be
        // nothing to hand out.
        $this->assertSame(0, Coupon::query()->where('name', 'SPONSOR4')->count());
    }

    public function test_a_phone_alone_does_not_create_a_handler_on_the_create_form(): void
    {
        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $this->form([
                'name' => 'SPONSOR5',
                'mode' => Coupon::MODE_UNIQUE,
                'quantity' => 5,
                'holder_phone' => '0123334444',
            ]))
            ->assertSessionHasErrors('holder_full_name');

        $this->assertSame(0, Coupon::query()->where('name', 'SPONSOR5')->count());
    }

    /* ---------------------------------------------------------------------
     | Editing
     * ------------------------------------------------------------------ */

    public function test_the_mode_is_fixed_once_codes_have_been_issued(): void
    {
        $coupon = $this->uniqueCoupon(['name' => 'LOCKED1']);
        $this->issue($coupon, 5, ['full_name' => 'Siti Nurhaliza']);

        $this->actingAs($this->couponAdmin())
            ->put(route('admin.coupons.update', $coupon), $this->form([
                'name' => 'LOCKED1',
                'mode' => Coupon::MODE_SHARED,
                'quantity' => 5,
            ]))
            ->assertSessionHasErrors('mode');

        // Five codes are in somebody's hands. Switching to a shared code would leave
        // them pointing at a batch that no longer honours them.
        $this->assertTrue($coupon->fresh()->isUnique());
        $this->assertSame(5, $coupon->fresh()->issuedCodes()->count());
    }

    public function test_the_mode_is_fixed_once_a_shared_batch_has_been_used(): void
    {
        $coupon = $this->fixedCoupon(10, ['name' => 'LOCKED2', 'quantity' => 10]);

        app(\App\Services\Coupon\CouponRedeemer::class)->claim($coupon, 100);

        $this->actingAs($this->couponAdmin())
            ->put(route('admin.coupons.update', $coupon), $this->form([
                'name' => 'LOCKED2',
                'mode' => Coupon::MODE_UNIQUE,
                'quantity' => 10,
                'discount_type' => Coupon::DISCOUNT_FIXED,
                'discount_value' => 10,
            ]))
            ->assertSessionHasErrors('mode');

        $this->assertTrue($coupon->fresh()->isShared());
    }

    public function test_an_unused_coupon_may_still_switch_mode(): void
    {
        // Nothing behind it yet, so there is nothing to invalidate.
        $coupon = $this->coupon(['name' => 'OPEN123', 'quantity' => 10]);

        $this->actingAs($this->couponAdmin())
            ->put(route('admin.coupons.update', $coupon), $this->form([
                'name' => 'OPEN123',
                'mode' => Coupon::MODE_UNIQUE,
                'quantity' => 10,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($coupon->fresh()->isUnique());
    }

    public function test_editing_a_unique_batch_cannot_change_its_stock_by_hand(): void
    {
        $coupon = $this->uniqueCoupon(['name' => 'FIXEDQTY']);
        $this->issue($coupon, 8, ['full_name' => 'Siti Nurhaliza']);

        // A crafted post, or a stale form, asking for a hundred uses it has no codes
        // for. Pinned to the stock instead, because the only way to allow more is to
        // issue another block.
        $this->actingAs($this->couponAdmin())
            ->put(route('admin.coupons.update', $coupon), $this->form([
                'name' => 'FIXEDQTY',
                'mode' => Coupon::MODE_UNIQUE,
                'quantity' => 100,
            ]))
            ->assertSessionHasNoErrors();

        $coupon->refresh();

        $this->assertSame(8, (int) $coupon->quantity);
        $this->assertSame(8, $coupon->issuedCodes()->count());
    }

    public function test_the_committed_amount_round_trips_and_is_optional(): void
    {
        $coupon = $this->coupon(['name' => 'PLEDGE1', 'quantity' => 10]);

        $this->actingAs($this->couponAdmin())
            ->put(route('admin.coupons.update', $coupon), $this->form([
                'name' => 'PLEDGE1',
                'quantity' => 10,
                'committed_amount' => 1500.50,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('1500.50', $coupon->fresh()->committed_amount);

        // Cleared again, which is what an empty box has to mean: the pledge was
        // recorded by mistake, not reduced to zero.
        $this->actingAs($this->couponAdmin())
            ->put(route('admin.coupons.update', $coupon), $this->form([
                'name' => 'PLEDGE1',
                'quantity' => 10,
                'committed_amount' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertNull($coupon->fresh()->committed_amount);
    }

    public function test_a_name_that_collides_with_an_issued_code_is_refused(): void
    {
        $existing = $this->uniqueCoupon();
        $this->issue($existing, 5);

        $taken = $existing->issuedCodes()->first()->code;

        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $this->form(['name' => $taken]))
            ->assertSessionHasErrors('name');

        // Both go into the same box on the public form, so one must never shadow the
        // other.
        $this->assertSame(0, Coupon::query()->where('name', $taken)->count());
    }

    /* ---------------------------------------------------------------------
     | End to end, through the public form
     * ------------------------------------------------------------------ */

    public function test_a_public_group_registration_redeems_an_individual_code(): void
    {
        $event = $this->event([
            'registration_mode' => Event::MODE_GROUPING,
            'charges_addons_per_participant' => true,
            'fee' => 0,
            'min_players' => 1,
            'max_players' => 20,
        ]);

        [$addon, $small] = $this->shirt($event, 15);

        $coupon = $this->uniqueCoupon([
            'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
            'discount_value' => 50,
        ]);

        $event->coupons()->attach($coupon);
        $block = $this->issue($coupon, 20, ['full_name' => 'Siti Nurhaliza']);
        $typed = $this->codeFrom($block);

        // Three heads at RM15 each: RM45, half off.
        $this->post(route('registration.store', ['event' => $event->slug]), [
            'voucher_code' => $typed,
            'team_name' => 'Kumpulan Sibu',
            'participants' => [
                $this->participantFields(['addons' => [$addon->id => ['choice' => (string) $small->id]]]),
                $this->participantFields(['full_name' => 'Member Two', 'addons' => [$addon->id => ['choice' => (string) $small->id]]]),
                $this->participantFields(['full_name' => 'Member Three', 'addons' => [$addon->id => ['choice' => (string) $small->id]]]),
            ],
        ])->assertSessionHasNoErrors();

        $registration = \App\Models\EventRegistration::query()->sole();

        $this->assertSame('45.00', $registration->addons_total);
        $this->assertSame('22.50', $registration->discount_amount);
        $this->assertSame('22.50', $registration->amount);

        // Three codes out of Siti's twenty, one per head.
        $this->assertSame(3, $block->fresh()->usedCount());
        $this->assertSame(17, $block->fresh()->unusedCount());
        $this->assertSame(17, $coupon->fresh()->remaining());
    }

    public function test_the_public_check_endpoint_recognises_an_individual_code(): void
    {
        $event = $this->event(['fee' => 100]);

        $coupon = $this->uniqueCoupon(['discount_type' => Coupon::DISCOUNT_FIXED, 'discount_value' => 25]);
        $event->coupons()->attach($coupon);

        $block = $this->issue($coupon, 5, ['full_name' => 'Siti Nurhaliza']);
        $typed = $this->codeFrom($block);

        $response = $this->postJson(route('voucher.check'), [
            'code' => $typed,
            'scope' => Coupon::KIND_EVENT,
            'event' => $event->slug,
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => true, 'code' => $typed]);

        // Read only: a check must never spend a code.
        $this->assertFalse($block->fresh()->codes()->used()->exists());
    }

    public function test_the_public_check_refuses_a_spent_individual_code(): void
    {
        $event = $this->event(['fee' => 100]);

        $coupon = $this->uniqueCoupon(['discount_type' => Coupon::DISCOUNT_FIXED, 'discount_value' => 25]);
        $event->coupons()->attach($coupon);

        $block = $this->issue($coupon, 5, ['full_name' => 'Siti Nurhaliza']);
        $typed = $this->codeFrom($block);

        app(RegistrationCouponWriter::class)
            ->applyCode($this->registration($event, people: 1), $typed);

        $response = $this->postJson(route('voucher.check'), [
            'code' => $typed,
            'scope' => Coupon::KIND_EVENT,
            'event' => $event->slug,
        ]);

        // "Yours has been used", not "the batch ran out": four of the five are still
        // good, and the holder needs to know which it was.
        $response->assertOk();
        $response->assertJson([
            'ok' => false,
            'message' => CouponOutcome::failed(CouponOutcome::ALREADY_USED)->message(),
        ]);
    }

    public function test_the_public_check_refuses_a_unique_batch_name(): void
    {
        $event = $this->event(['fee' => 100]);

        $coupon = $this->uniqueCoupon(['name' => 'SPONSOR9', 'discount_type' => Coupon::DISCOUNT_FIXED, 'discount_value' => 25]);
        $event->coupons()->attach($coupon);
        $this->issue($coupon, 5);

        $response = $this->postJson(route('voucher.check'), [
            'code' => 'SPONSOR9',
            'scope' => Coupon::KIND_EVENT,
            'event' => $event->slug,
        ]);

        $response->assertOk();
        $response->assertJson([
            'ok' => false,
            'message' => CouponOutcome::failed(CouponOutcome::NOT_FOUND)->message(),
        ]);
    }
}
