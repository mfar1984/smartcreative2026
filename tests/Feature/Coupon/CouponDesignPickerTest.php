<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The admin picks a design by looking at it.
 *
 * It was a dropdown, which is the one field on that form whose name tells an operator
 * nothing about what he is choosing. Every key in Coupon::DESIGNS now draws a preview,
 * and the test is written against that constant so a design added later is covered the
 * moment it is listed.
 *
 * The posted field is unchanged — still `design`, still the same values — because the
 * stored choice is what a coupon created today will be drawn with years later.
 */
class CouponDesignPickerTest extends CouponTestCase
{
    use RefreshDatabase;

    public function test_the_create_form_previews_every_design(): void
    {
        $response = $this->actingAs($this->couponAdmin())->get(route('admin.coupons.create'));

        $response->assertOk();

        foreach (array_keys(Coupon::DESIGNS) as $design) {
            // A real radio, so the keyboard reaches it and the group is announced.
            $response->assertSee('name="design" value="' . $design . '"', false);

            // And a preview beside it, drawn with sample figures.
            $response->assertSee('data-design-preview="' . $design . '"', false);
        }

        // The group is labelled for a screen reader, which cannot read a grid.
        $response->assertSee('data-design-picker', false);
        $response->assertSee('Coupon design');
    }

    public function test_every_design_is_offered_by_its_own_label(): void
    {
        $response = $this->actingAs($this->couponAdmin())->get(route('admin.coupons.create'));

        foreach (Coupon::DESIGNS as $label) {
            $response->assertSee($label, false);
        }
    }

    public function test_the_picker_still_posts_the_same_field(): void
    {
        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), [
                'kind' => Coupon::KIND_EVENT,
                'name' => 'BOLD01',
                'quantity' => 5,
                'expires_at' => now()->addMonth()->toDateString(),
                'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
                'discount_value' => 30,
                'design' => 'bold',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('bold', Coupon::query()->where('name', 'BOLD01')->sole()->design);
    }

    public function test_a_design_that_does_not_exist_is_refused(): void
    {
        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), [
                'kind' => Coupon::KIND_EVENT,
                'name' => 'NOPE01',
                'quantity' => 5,
                'expires_at' => now()->addMonth()->toDateString(),
                'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
                'discount_value' => 30,
                'design' => 'does-not-exist',
            ])
            ->assertSessionHasErrors('design');
    }

    public function test_editing_previews_with_the_coupons_own_figures(): void
    {
        // So the operator is choosing between pictures of the coupon he actually has
        // rather than between pictures of a made-up one.
        $coupon = $this->fixedCoupon(45, ['quantity' => 3, 'design' => 'stamp']);

        $response = $this->actingAs($this->couponAdmin())->get(route('admin.coupons.edit', $coupon));

        $response->assertOk();
        $response->assertSee('RM 45.00');
        $response->assertSee($coupon->name);
        $response->assertSee('name="design" value="stamp"', false);
    }

    public function test_the_custom_upload_field_is_still_there(): void
    {
        $response = $this->actingAs($this->couponAdmin())->get(route('admin.coupons.create'));

        $response->assertSee('name="design_image"', false);
        $response->assertSee('data-custom-row', false);
    }
}
