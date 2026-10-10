<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\CouponCode;
use App\Models\Event;
use App\Models\Setting;
use App\Models\ShopProduct;
use App\Support\AdminNavigation;
use App\Support\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * The admin screens: the module, its four permissions, and the tick lists.
 */
class CouponAdminScreenTest extends CouponTestCase
{
    use RefreshDatabase;

    /* ---------------------------------------------------------------------
     | The sidebar
     * ------------------------------------------------------------------ */

    public function test_the_coupon_group_sits_above_event_in_the_modules_section(): void
    {
        $user = $this->userWith(['coupons.view', 'events.view']);

        $modules = collect(AdminNavigation::for($user))
            ->firstWhere('label', 'Modules');

        $this->assertNotNull($modules);

        $keys = collect($modules['items'])->pluck('key')->values()->all();

        $this->assertContains('coupons', $keys);
        $this->assertContains('event', $keys);
        $this->assertLessThan(
            array_search('event', $keys, true),
            array_search('coupons', $keys, true),
            'Coupon must sit above Event.',
        );

        $group = collect($modules['items'])->firstWhere('key', 'coupons');

        $this->assertSame('Coupon', $group['label']);
        $this->assertSame(
            ['Coupon', 'Tracking', 'Report'],
            collect($group['children'])->pluck('label')->all(),
        );
    }

    public function test_the_coupon_group_is_hidden_without_the_view_permission(): void
    {
        $user = $this->userWith(['events.view']);

        $modules = collect(AdminNavigation::for($user))->firstWhere('label', 'Modules');

        $this->assertNotContains('coupons', collect($modules['items'])->pluck('key')->all());
    }

    public function test_the_icon_the_group_names_exists(): void
    {
        // An invented icon name renders an empty svg, which reads as a missing
        // sidebar item rather than as a mistake.
        $icons = file_get_contents(resource_path('views/components/admin/icon.blade.php'));

        $this->assertStringContainsString("@case('tag')", $icons);
    }

    /* ---------------------------------------------------------------------
     | Each endpoint needs its own permission
     * ------------------------------------------------------------------ */

    public function test_the_list_and_tracking_need_the_view_permission(): void
    {
        $without = $this->userWith(['events.view']);

        $this->actingAs($without)->get(route('admin.coupons.index'))->assertForbidden();
        $this->actingAs($without)->get(route('admin.coupons.tracking'))->assertForbidden();

        $with = $this->userWith(['coupons.view']);

        $this->actingAs($with)->get(route('admin.coupons.index'))->assertOk();
        $this->actingAs($with)->get(route('admin.coupons.tracking'))->assertOk();
    }

    public function test_creating_needs_the_create_permission(): void
    {
        $without = $this->userWith(['coupons.view']);

        $this->actingAs($without)->get(route('admin.coupons.create'))->assertForbidden();
        $this->actingAs($without)->post(route('admin.coupons.store'), $this->form())->assertForbidden();

        $this->assertSame(0, Coupon::query()->count());

        $with = $this->userWith(['coupons.view', 'coupons.create']);

        $this->actingAs($with)->get(route('admin.coupons.create'))->assertOk();
        $this->actingAs($with)->post(route('admin.coupons.store'), $this->form())->assertSessionHasNoErrors();

        $this->assertSame(1, Coupon::query()->count());
    }

    public function test_editing_needs_the_update_permission(): void
    {
        $coupon = $this->coupon();
        $without = $this->userWith(['coupons.view', 'coupons.create']);

        $this->actingAs($without)->get(route('admin.coupons.edit', $coupon))->assertForbidden();
        $this->actingAs($without)
            ->put(route('admin.coupons.update', $coupon), $this->form(['name' => 'CHANGED1']))
            ->assertForbidden();

        $this->assertNotSame('CHANGED1', $coupon->fresh()->name);

        $with = $this->userWith(['coupons.view', 'coupons.update']);

        $this->actingAs($with)->get(route('admin.coupons.edit', $coupon))->assertOk();
        $this->actingAs($with)
            ->put(route('admin.coupons.update', $coupon), $this->form(['name' => 'CHANGED1']))
            ->assertSessionHasNoErrors();

        $this->assertSame('CHANGED1', $coupon->fresh()->name);
    }

    public function test_deleting_needs_the_delete_permission(): void
    {
        $coupon = $this->coupon();
        $without = $this->userWith(['coupons.view', 'coupons.update']);

        $this->actingAs($without)->delete(route('admin.coupons.destroy', $coupon))->assertForbidden();
        $this->assertDatabaseHas('coupons', ['id' => $coupon->id]);

        $with = $this->userWith(['coupons.view', 'coupons.delete']);

        $this->actingAs($with)->delete(route('admin.coupons.destroy', $coupon))->assertRedirect();
        $this->assertDatabaseMissing('coupons', ['id' => $coupon->id]);
    }

    /* ---------------------------------------------------------------------
     | The list reads in uses, not in codes
     * ------------------------------------------------------------------ */

    public function test_the_list_counts_uses_and_calls_the_name_a_code(): void
    {
        $coupon = $this->coupon(['name' => 'USESBB', 'quantity' => 10]);

        app(\App\Services\Coupon\CouponRedeemer::class)->claim($coupon, 100);

        $response = $this->actingAs($this->userWith(['coupons.view']))
            ->get(route('admin.coupons.index'));

        $response->assertOk();
        $response->assertSee('Coupon Code');
        $response->assertSee('1 / 10');
        $response->assertSee('9 uses left');
        $response->assertDontSee('codes left');
    }

    public function test_an_unlimited_coupon_is_listed_as_unlimited_rather_than_as_a_count(): void
    {
        $coupon = $this->coupon(['name' => 'NOLIMT', 'quantity' => 0]);

        app(\App\Services\Coupon\CouponRedeemer::class)->claim($coupon, 100);

        $this->actingAs($this->userWith(['coupons.view']))
            ->get(route('admin.coupons.index'))
            ->assertOk()
            ->assertSee('no limit')
            ->assertDontSee('uses left');
    }

    public function test_the_form_asks_how_many_uses_rather_than_how_many_codes(): void
    {
        $response = $this->actingAs($this->couponAdmin())->get(route('admin.coupons.create'));

        $response->assertOk();
        $response->assertSee('How Many Uses');
        $response->assertSee('How many times the code may be used. 0 means no limit.');

        // The wording that caused the whole detour.
        $response->assertDontSee('unique code');
        $response->assertDontSee('Number of Coupon');
    }

    /* ---------------------------------------------------------------------
     | Creating
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'kind' => Coupon::KIND_EVENT,
            'name' => 'ABC123',
            'quantity' => 0,
            'expires_at' => now()->addMonth()->toDateString(),
            'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
            'discount_value' => 10,
            'design' => 'classic',
        ];
    }

    public function test_a_capped_coupon_stores_its_limit_and_mints_nothing(): void
    {
        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $this->form(['name' => 'XYZ987', 'quantity' => 25]))
            ->assertSessionHasNoErrors();

        $coupon = Coupon::query()->sole();

        $this->assertSame(25, (int) $coupon->quantity);
        $this->assertSame(25, $coupon->remaining());
        $this->assertSame(0, $coupon->codes()->count(), 'Nothing may be minted up front.');
        $this->assertDatabaseHas('activity_logs', ['action' => 'coupons.create']);
    }

    public function test_a_lowercase_name_is_folded_rather_than_refused(): void
    {
        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $this->form(['name' => ' abc 123 ']))
            ->assertSessionHasNoErrors();

        $this->assertSame('ABC123', Coupon::query()->sole()->name);
    }

    public function test_a_name_with_punctuation_is_refused(): void
    {
        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $this->form(['name' => 'ABC-123']))
            ->assertSessionHasErrors('name');

        $this->assertSame(0, Coupon::query()->count());
    }

    public function test_a_name_that_collides_with_another_coupon_is_refused(): void
    {
        $existing = $this->coupon();

        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $this->form(['name' => $existing->name]))
            ->assertSessionHasErrors('name');
    }

    public function test_a_percentage_over_a_hundred_is_refused(): void
    {
        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $this->form([
                'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
                'discount_value' => 101,
            ]))
            ->assertSessionHasErrors('discount_value');

        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $this->form([
                'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
                'discount_value' => 0,
            ]))
            ->assertSessionHasErrors('discount_value');

        $this->assertSame(0, Coupon::query()->count());
    }

    public function test_a_hundred_percent_is_allowed(): void
    {
        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $this->form(['discount_value' => 100]))
            ->assertSessionHasNoErrors();

        $this->assertSame('100.00', Coupon::query()->sole()->discount_value);
    }

    public function test_a_fixed_amount_of_zero_is_refused(): void
    {
        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $this->form([
                'discount_type' => Coupon::DISCOUNT_FIXED,
                'discount_value' => 0,
            ]))
            ->assertSessionHasErrors('discount_value');
    }

    public function test_an_expiry_in_the_past_is_refused(): void
    {
        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $this->form([
                'expires_at' => now()->subDay()->toDateString(),
            ]))
            ->assertSessionHasErrors('expires_at');
    }

    public function test_a_negative_quantity_is_refused_and_zero_is_not(): void
    {
        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $this->form(['quantity' => -1]))
            ->assertSessionHasErrors('quantity');

        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $this->form(['quantity' => 0]))
            ->assertSessionHasNoErrors();
    }

    public function test_a_custom_design_image_is_stored_on_the_public_disk(): void
    {
        Storage::fake('public');

        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $this->form([
                'design' => Coupon::DESIGN_CUSTOM,
                'design_image' => UploadedFile::fake()->image('ticket.png', 600, 300),
            ]))
            ->assertSessionHasNoErrors();

        $coupon = Coupon::query()->sole();

        $this->assertTrue($coupon->hasCustomDesign());
        $this->assertStringStartsWith(Coupon::DESIGN_DIRECTORY.'/', $coupon->design_path);
        Storage::disk('public')->assertExists($coupon->design_path);
    }

    public function test_a_design_upload_that_is_not_an_image_is_refused(): void
    {
        Storage::fake('public');

        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), $this->form([
                'design' => Coupon::DESIGN_CUSTOM,
                'design_image' => UploadedFile::fake()->create('rules.pdf', 20, 'application/pdf'),
            ]))
            ->assertSessionHasErrors('design_image');
    }

    /* ---------------------------------------------------------------------
     | Editing and deleting a used batch
     * ------------------------------------------------------------------ */

    public function test_raising_the_limit_on_a_used_coupon_is_allowed(): void
    {
        // Safe, and the whole reason the old refusal was wrong: more uses of the same
        // code takes nothing away from the uses already honoured.
        $coupon = $this->coupon(['quantity' => 3]);

        app(\App\Services\Coupon\CouponRedeemer::class)->claim($coupon, 100);

        $this->actingAs($this->couponAdmin())
            ->put(route('admin.coupons.update', $coupon), $this->form([
                'name' => $coupon->name,
                'quantity' => 10,
            ]))
            ->assertSessionHasNoErrors();

        $coupon = $coupon->fresh();

        $this->assertSame(10, (int) $coupon->quantity);
        $this->assertSame(9, $coupon->remaining());
        $this->assertSame(1, $coupon->redeemedCount());
    }

    public function test_dropping_the_limit_to_unlimited_on_a_used_coupon_is_allowed(): void
    {
        $coupon = $this->coupon(['quantity' => 2]);

        app(\App\Services\Coupon\CouponRedeemer::class)->claim($coupon, 100);

        $this->actingAs($this->couponAdmin())
            ->put(route('admin.coupons.update', $coupon), $this->form([
                'name' => $coupon->name,
                'quantity' => 0,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($coupon->fresh()->isUnlimited());
        $this->assertNull($coupon->fresh()->remaining());
    }

    public function test_lowering_the_limit_below_what_has_been_used_is_refused(): void
    {
        $coupon = $this->coupon(['quantity' => 5]);
        $redeemer = app(\App\Services\Coupon\CouponRedeemer::class);

        $redeemer->claim($coupon, 100);
        $redeemer->claim($coupon->fresh(), 100);
        $redeemer->claim($coupon->fresh(), 100);

        $response = $this->actingAs($this->couponAdmin())
            ->put(route('admin.coupons.update', $coupon), $this->form([
                'name' => $coupon->name,
                'quantity' => 2,
            ]));

        $response->assertSessionHasErrors('quantity');

        // The message says what happened and why, rather than "cannot change".
        $message = session('errors')->first('quantity');

        $this->assertStringContainsString('already been used 3 times', $message);
        $this->assertStringContainsString('cannot be set below 3', $message);

        $this->assertSame(5, (int) $coupon->fresh()->quantity);
    }

    public function test_the_limit_may_be_set_exactly_to_what_has_been_used(): void
    {
        // Closing a coupon off at the uses it has already given is not a retroactive
        // change: nothing already honoured is invalidated.
        $coupon = $this->coupon(['quantity' => 5]);

        app(\App\Services\Coupon\CouponRedeemer::class)->claim($coupon, 100);

        $this->actingAs($this->couponAdmin())
            ->put(route('admin.coupons.update', $coupon), $this->form([
                'name' => $coupon->name,
                'quantity' => 1,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $coupon->fresh()->remaining());
        $this->assertTrue($coupon->fresh()->isExhausted());
    }

    public function test_a_used_coupon_cannot_be_deleted(): void
    {
        $coupon = $this->coupon(['quantity' => 2]);

        app(\App\Services\Coupon\CouponRedeemer::class)->claim($coupon, 100);

        $this->actingAs($this->couponAdmin())
            ->delete(route('admin.coupons.destroy', $coupon))
            ->assertSessionHasErrors('coupon');

        $this->assertDatabaseHas('coupons', ['id' => $coupon->id]);
    }

    /* ---------------------------------------------------------------------
     | The tick lists
     * ------------------------------------------------------------------ */

    public function test_only_event_coupons_are_offered_on_the_event_form(): void
    {
        $eventCoupon = $this->coupon(['kind' => Coupon::KIND_EVENT, 'name' => 'EVENTA']);
        $shopCoupon = $this->coupon(['kind' => Coupon::KIND_SHOP, 'name' => 'SHOPAA']);

        $response = $this->actingAs($this->userWith(['events.create', 'coupons.view']))
            ->get(route('admin.event.registration.create'));

        $response->assertOk();

        // The picker is present...
        $response->assertSee('Valid Coupons');
        $response->assertSee('name="coupons[]"', false);
        $response->assertSee('name="coupons_present"', false);

        // ...and offers only the Event-kind batch.
        $response->assertSee('EVENTA');
        $response->assertDontSee('SHOPAA');

        $offered = $response->viewData('coupons');

        $this->assertSame([$eventCoupon->id], $offered->pluck('id')->all());
        $this->assertNotContains($shopCoupon->id, $offered->pluck('id')->all());
    }

    public function test_only_shop_coupons_are_offered_on_the_product_form(): void
    {
        $shopCoupon = $this->coupon(['kind' => Coupon::KIND_SHOP, 'name' => 'SHOPBB']);
        $this->coupon(['kind' => Coupon::KIND_EVENT, 'name' => 'EVENTB']);

        $response = $this->actingAs($this->userWith(['shop.products.create', 'coupons.view']))
            ->get(route('admin.shop.products.create'));

        $response->assertOk();
        $response->assertSee('Valid Coupons');
        $response->assertSee('name="coupons[]"', false);
        $response->assertSee('SHOPBB');
        $response->assertDontSee('EVENTB');

        $this->assertSame([$shopCoupon->id], $response->viewData('coupons')->pluck('id')->all());
    }

    public function test_an_expired_coupon_is_not_offered(): void
    {
        $this->coupon(['kind' => Coupon::KIND_EVENT, 'name' => 'GONEAA', 'expires_at' => now()->subDay()->toDateString()]);
        $live = $this->coupon(['kind' => Coupon::KIND_EVENT, 'name' => 'LIVEAA']);

        $response = $this->actingAs($this->userWith(['events.create', 'coupons.view']))
            ->get(route('admin.event.registration.create'));

        $this->assertSame([$live->id], $response->viewData('coupons')->pluck('id')->all());
    }

    public function test_an_exhausted_coupon_is_still_offered_so_a_new_one_can_sit_beside_it(): void
    {
        $used = $this->coupon(['kind' => Coupon::KIND_EVENT, 'name' => 'USEDUP', 'quantity' => 1]);
        app(\App\Services\Coupon\CouponRedeemer::class)->claim($used, 100);

        $this->assertTrue($used->fresh()->isExhausted());

        $response = $this->actingAs($this->userWith(['events.create', 'coupons.view']))
            ->get(route('admin.event.registration.create'));

        $this->assertContains($used->id, $response->viewData('coupons')->pluck('id')->all());
        $response->assertSee('Every use has been taken.');
    }

    public function test_the_picker_counts_uses_rather_than_codes(): void
    {
        $batch = $this->coupon(['kind' => Coupon::KIND_EVENT, 'name' => 'USESAA', 'quantity' => 10]);

        app(\App\Services\Coupon\CouponRedeemer::class)->claim($batch, 100);

        $this->actingAs($this->userWith(['events.create', 'coupons.view']))
            ->get(route('admin.event.registration.create'))
            ->assertOk()
            ->assertSee('9 of 10 uses left')
            ->assertDontSee('codes left');
    }

    public function test_ticking_coupons_on_an_event_round_trips(): void
    {
        $admin = $this->userWith(['events.create', 'events.update', 'events.view', 'coupons.view']);

        $one = $this->coupon(['kind' => Coupon::KIND_EVENT, 'name' => 'ONEONE']);
        $two = $this->coupon(['kind' => Coupon::KIND_EVENT, 'name' => 'TWOTWO']);
        $shop = $this->coupon(['kind' => Coupon::KIND_SHOP, 'name' => 'SHOPCC']);

        $this->actingAs($admin)
            ->post(route('admin.event.registration.store'), $this->eventForm([
                'coupons_present' => '1',
                // The shop id is posted deliberately: a tampered payload must not be
                // able to attach a shop coupon to an event.
                'coupons' => [$one->id, $two->id, $shop->id],
            ]))
            ->assertSessionHasNoErrors();

        $event = Event::query()->sole();

        $this->assertSame([$one->id, $two->id], $event->coupons()->pluck('coupons.id')->sort()->values()->all());

        // The edit form comes back ticked.
        $response = $this->actingAs($admin)->get(route('admin.event.registration.edit', $event));
        $this->assertSame([$one->id, $two->id], collect($response->viewData('selectedCoupons'))->sort()->values()->all());

        // And unticking everything detaches, rather than silently keeping them.
        $this->actingAs($admin)
            ->put(route('admin.event.registration.update', $event), $this->eventForm([
                'coupons_present' => '1',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame([], $event->fresh()->coupons()->pluck('coupons.id')->all());
    }

    public function test_ticking_coupons_on_a_product_round_trips(): void
    {
        $admin = $this->userWith([
            'shop.products.create',
            'shop.products.update',
            'shop.products.view',
            'coupons.view',
        ]);

        $one = $this->coupon(['kind' => Coupon::KIND_SHOP, 'name' => 'SHOPDD']);
        $event = $this->coupon(['kind' => Coupon::KIND_EVENT, 'name' => 'EVENTC']);

        $this->actingAs($admin)
            ->post(route('admin.shop.products.store'), $this->productForm([
                'coupons_present' => '1',
                'coupons' => [$one->id, $event->id],
            ]))
            ->assertSessionHasNoErrors();

        $product = ShopProduct::query()->sole();

        $this->assertSame([$one->id], $product->coupons()->pluck('coupons.id')->all());

        $this->actingAs($admin)
            ->put(route('admin.shop.products.update', $product), $this->productForm([
                'coupons_present' => '1',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame([], $product->fresh()->coupons()->pluck('coupons.id')->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function eventForm(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Admin Made Event',
            'category' => 'Community',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'location' => 'Sibu',
            'seats_total' => 0,
            'status' => Event::STATUS_DRAFT,
            'registration_mode' => Event::MODE_INDIVIDUAL,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productForm(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Admin Made Product',
            'price' => 50,
            'status' => ShopProduct::STATUS_DRAFT,
            'fulfilment' => ShopProduct::FULFILMENT_ONLINE,
            'payment_methods' => [\App\Models\ShopOrder::METHOD_GATEWAY],
            'track_inventory' => '1',
            'stock_quantity' => 10,
            'low_stock_threshold' => 2,
            'sort_order' => 0,
        ];
    }

    /* ---------------------------------------------------------------------
     | Tracking
     * ------------------------------------------------------------------ */

    public function test_tracking_lists_a_redemption_with_its_code_coupon_and_figure(): void
    {
        $event = $this->event(['fee' => 200]);
        $registration = $this->registration($event);

        $coupon = $this->fixedCoupon(50, ['quantity' => 2, 'name' => 'TRACK1']);

        app(\App\Services\Coupon\RegistrationCouponWriter::class)->apply($registration, $coupon);

        $response = $this->actingAs($this->userWith(['coupons.view', 'participants.view']))
            ->get(route('admin.coupons.tracking'));

        $response->assertOk();
        $response->assertSee('TRACK1');
        $response->assertSee($registration->reference);
        $response->assertSee($event->title);
        $response->assertSee('RM 50.00');

        $this->assertSame(1, $response->viewData('redemptions')->total());
        $this->assertSame(50.0, $response->viewData('discountTotal'));
    }

    public function test_tracking_filters_by_coupon(): void
    {
        $one = $this->coupon(['quantity' => 1, 'name' => 'BATCHA', 'discount_type' => Coupon::DISCOUNT_FIXED, 'discount_value' => 10]);
        $two = $this->coupon(['quantity' => 1, 'name' => 'BATCHB', 'discount_type' => Coupon::DISCOUNT_FIXED, 'discount_value' => 20]);

        app(\App\Services\Coupon\CouponRedeemer::class)->claim($one, 100);
        app(\App\Services\Coupon\CouponRedeemer::class)->claim($two, 100);

        $user = $this->userWith(['coupons.view']);

        $all = $this->actingAs($user)->get(route('admin.coupons.tracking'));
        $this->assertSame(2, $all->viewData('redemptions')->total());
        $this->assertSame(30.0, $all->viewData('discountTotal'));

        $filtered = $this->actingAs($user)->get(route('admin.coupons.tracking', ['coupon' => $two->id]));
        $this->assertSame(1, $filtered->viewData('redemptions')->total());
        $this->assertSame(20.0, $filtered->viewData('discountTotal'));
    }

    public function test_the_tracking_date_filter_speaks_the_office_clock(): void
    {
        Setting::write('general.timezone', 'Asia/Kuala_Lumpur', 'general');
        GeneralSettings::flush();

        $coupon = $this->coupon(['quantity' => 1, 'discount_type' => Coupon::DISCOUNT_FIXED, 'discount_value' => 10]);
        $outcome = app(\App\Services\Coupon\CouponRedeemer::class)->claim($coupon, 100);

        /*
         | 2026-10-07 20:00 UTC is 2026-10-08 04:00 in Kuala Lumpur. Filtering the
         | 8th on the office clock has to return it, even though the stored UTC date
         | is the 7th. A bare whereDate against the picker's value would lose it,
         | which is the bug this codebase already shipped once on the Logging screen.
         */
        CouponCode::query()
            ->whereKey($outcome->code->id)
            ->update(['redeemed_at' => Carbon::parse('2026-10-07 20:00:00', 'UTC')]);

        $user = $this->userWith(['coupons.view']);

        $onTheEighth = $this->actingAs($user)
            ->get(route('admin.coupons.tracking', ['from' => '2026-10-08', 'to' => '2026-10-08']));

        $this->assertSame(1, $onTheEighth->viewData('redemptions')->total());

        $onTheSeventh = $this->actingAs($user)
            ->get(route('admin.coupons.tracking', ['from' => '2026-10-07', 'to' => '2026-10-07']));

        $this->assertSame(0, $onTheSeventh->viewData('redemptions')->total());
    }

    public function test_a_malformed_tracking_date_is_ignored_rather_than_zeroing_the_list(): void
    {
        $coupon = $this->coupon(['quantity' => 1]);
        app(\App\Services\Coupon\CouponRedeemer::class)->claim($coupon, 100);

        $response = $this->actingAs($this->userWith(['coupons.view']))
            ->get(route('admin.coupons.tracking', ['from' => 'not-a-date']));

        $this->assertSame(1, $response->viewData('redemptions')->total());
    }

    /* ---------------------------------------------------------------------
     | The seeder
     * ------------------------------------------------------------------ */

    public function test_the_four_coupon_permissions_are_seeded_idempotently(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        foreach (['coupons.view', 'coupons.create', 'coupons.update', 'coupons.delete'] as $slug) {
            $this->assertSame(
                1,
                \App\Models\Permission::query()->where('slug', $slug)->count(),
                $slug.' must exist exactly once.',
            );
        }

        // Super admin reaches them without a pivot row, which is how its access
        // works everywhere else in this system.
        $permission = \App\Models\Permission::query()->where('slug', 'coupons.create')->sole();

        $this->assertSame('Coupon', $permission->group);
        $this->assertSame('Coupons', $permission->module);
    }
}
