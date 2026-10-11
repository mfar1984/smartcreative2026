<?php

namespace Tests\Feature\Shop;

use App\Models\Setting;
use App\Models\ShopOrder;
use App\Models\ShopProduct;
use App\Models\ShopProductAddon;
use App\Models\User;
use App\Support\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * How an add-on is answered, and what the group of them is called.
 *
 * Two gaps against the registration form, which has allowed both for a while: the
 * operator could not say whether an add-on is one choice or several, and could not
 * say what the block is headed. Neither touches money, so the figures asserted here
 * are the ones already asserted elsewhere and they are expected not to move.
 *
 * The part worth guarding is the enforcement. A radio add-on holding two options is
 * refused by Cart rather than by the markup, because markup is not a rule: a crafted
 * POST reaches the same code path as the form.
 */
class ProductAddonInputTypeTest extends ShopAddonTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->gatewayShopSettings();

        // A real postage figure, so a checkout in here prices the same way the rest
        // of the add-on tests do.
        Setting::write('integration.shipping.flat_rate_west', '8.00', 'integration.shipping');
    }

    private function admin(): User
    {
        return $this->userWith([
            'admin.access',
            'shop.products.view',
            'shop.products.create',
            'shop.products.update',
        ]);
    }

    /* ---------------------------------------------------------------------
     | What an add-on from before this change does
     * ------------------------------------------------------------------ */

    /**
     * The regression lock on the column default.
     *
     * forceFill past the model, so the row looks exactly like one written before
     * the column existed: whatever the default put there is what an add-on already
     * in the shop carries, and it has to keep meaning "one option only".
     */
    public function test_an_addon_with_no_stored_input_type_is_still_a_radio_group(): void
    {
        $product = $this->addonProduct();
        $engraving = $product->addons->firstWhere('name', 'Engraving');

        $this->assertSame(ShopProductAddon::SELECTION_RADIO, $engraving->selection_type);
        $this->assertTrue($engraving->isRadioSelection());
        $this->assertFalse($engraving->isCheckboxSelection());
        $this->assertFalse($engraving->allowsManyOptions());

        $response = $this->get(route('shop.product', $product->slug));

        $response->assertOk();
        $response->assertSee('type="radio"', false);

        // One name for the whole group, which is what holds it to a single answer.
        // A checkbox group would carry the [] suffix instead.
        $response->assertSee('name="addons[' . $engraving->id . ']"', false);
        $response->assertDontSee('name="addons[' . $engraving->id . '][]"', false);
    }

    /**
     * A value this release does not recognise falls back rather than throwing, which
     * is what keeps a rolled-back deployment from breaking a live product page.
     */
    public function test_an_unknown_stored_input_type_falls_back_to_radio(): void
    {
        $addon = $this->addonProduct()->addons->firstWhere('name', 'Engraving');
        $addon->forceFill(['selection_type' => 'something-else'])->save();

        $addon->refresh()->loadMissing('variants');

        $this->assertTrue($addon->isRadioSelection());
        $this->assertFalse($addon->allowsManyOptions());
        $this->assertSame(
            ShopProductAddon::SELECTION_TYPES[ShopProductAddon::SELECTION_RADIO],
            $addon->selectionTypeLabel(),
        );
    }

    public function test_reposting_an_existing_addon_leaves_its_input_type_alone(): void
    {
        $product = $this->addonProduct();
        $engraving = $product->addons->firstWhere('name', 'Engraving');

        // The form as it was before the dropdown existed: no selection_type at all.
        $this->actingAs($this->admin())
            ->put(route('admin.shop.products.update', $product), $this->productForm([
                'option_name' => 'Size',
                'addons' => [
                    $this->addonFormRow([
                        'id' => $engraving->id,
                        'name' => 'Engraving',
                        'price' => '10.00',
                        'is_required' => '1',
                        'variants' => $engraving->variants
                            ->map(fn ($option) => ['id' => $option->id, 'label' => $option->label, 'price' => $option->price])
                            ->all(),
                    ]),
                ],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(ShopProductAddon::SELECTION_RADIO, $engraving->refresh()->selection_type);
    }

    /* ---------------------------------------------------------------------
     | Radio: one answer, enforced on the server
     * ------------------------------------------------------------------ */

    public function test_a_radio_addon_refuses_two_selections(): void
    {
        $product = $this->addonProduct();
        $variant = $product->variants->firstWhere('label', 'S');
        $engraving = $product->addons->firstWhere('name', 'Engraving');

        // Both options against one radio add-on, which the page cannot produce.
        $this->post(route('cart.store'), [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'addons' => [$engraving->id => $engraving->variants->pluck('id')->all()],
        ])->assertSessionHasErrors('cart');

        $this->assertSame(Cart::UNAVAILABLE, session('errors')->first('cart'));
        $this->assertTrue(Cart::isEmpty());
        $this->assertSame(0, DB::table('shop_order_item_addons')->count());
    }

    public function test_a_compulsory_radio_addon_with_nothing_chosen_is_refused(): void
    {
        $product = $this->addonProduct();
        $variant = $product->variants->firstWhere('label', 'S');
        $engraving = $product->addons->firstWhere('name', 'Engraving');

        // Every shape "nothing chosen" takes: absent, blank, and an empty list from
        // a checkbox group that was never ticked.
        foreach ([[], [$engraving->id => ''], [$engraving->id => []]] as $addons) {
            $this->flushSession();

            $this->post(route('cart.store'), [
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'addons' => $addons,
            ])->assertSessionHasErrors('cart');

            $this->assertStringContainsString(
                'Choose an option for "Engraving"',
                session('errors')->first('cart'),
            );

            $this->assertTrue(Cart::isEmpty());
        }
    }

    /* ---------------------------------------------------------------------
     | Checkbox: as many as wanted
     * ------------------------------------------------------------------ */

    public function test_a_checkbox_addon_draws_checkboxes_and_no_none_row(): void
    {
        $product = $this->addonProduct();
        $engraving = $product->addons->firstWhere('name', 'Engraving');
        $engraving->forceFill([
            'is_required' => false,
            'selection_type' => ShopProductAddon::SELECTION_CHECKBOX,
        ])->save();

        $response = $this->get(route('shop.product', $product->slug));

        $response->assertOk();
        $response->assertSee('name="addons[' . $engraving->id . '][]"', false);

        // The None row exists only to clear a radio group, which a checkbox does by
        // being left alone.
        $response->assertDontSee('name="addons[' . $engraving->id . ']" value=""', false);
    }

    public function test_two_options_of_one_checkbox_addon_are_both_charged_and_both_recorded(): void
    {
        $product = $this->addonProduct();
        $variant = $product->variants->firstWhere('label', 'S');

        $engraving = $product->addons->firstWhere('name', 'Engraving');
        $engraving->forceFill(['selection_type' => ShopProductAddon::SELECTION_CHECKBOX])->save();

        $front = $engraving->variants->firstWhere('label', 'Front');
        $both = $engraving->variants->firstWhere('label', 'Front & Back');

        $this->post(route('cart.store'), [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'addons' => [$engraving->id => [$front->id, $both->id]],
        ])->assertRedirect(route('cart'));

        $line = Cart::lines()->sole();

        // Front charges the add-on's 10.00 through its null price, Front & Back
        // carries its own 4.00, and the medal is 80.00.
        $this->assertSame(14.0, $line['addons_unit_price']);
        $this->assertSame(94.0, $line['line_total']);
        $this->assertCount(2, $line['addons']);

        $this->fakeChipPurchase();
        $this->post(route('checkout.place'), $this->checkoutFields(ShopOrder::METHOD_GATEWAY));

        $item = ShopOrder::query()->latest('id')->firstOrFail()->items()->sole();
        $rows = $item->addons()->get();

        $this->assertCount(2, $rows);
        $this->assertSame(['Front', 'Front & Back'], $rows->pluck('variant_label')->sort()->values()->all());
        $this->assertSame('14.00', number_format($item->addonsTotal(), 2));

        // Two rows for one add-on, each pointing at its own option, so the admin and
        // the invoice can both say which was taken.
        $this->assertSame([$engraving->id, $engraving->id], $rows->pluck('shop_product_addon_id')->all());
        $this->assertSame(
            [$front->id, $both->id],
            $rows->pluck('shop_product_addon_variant_id')->sort()->values()->all(),
        );
    }

    public function test_the_same_two_options_ticked_in_either_order_are_one_basket_line(): void
    {
        $product = $this->addonProduct();
        $variant = $product->variants->firstWhere('label', 'S');

        $engraving = $product->addons->firstWhere('name', 'Engraving');
        $engraving->forceFill(['selection_type' => ShopProductAddon::SELECTION_CHECKBOX])->save();

        $ids = $engraving->variants->pluck('id')->all();

        foreach ([$ids, array_reverse($ids)] as $order) {
            $this->post(route('cart.store'), [
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'addons' => [$engraving->id => $order],
            ])->assertRedirect(route('cart'));
        }

        $this->assertCount(1, session('shop.cart'));
        $this->assertSame(2, Cart::lines()->sole()['quantity']);
    }

    public function test_a_checkbox_addon_still_refuses_an_option_from_another_product(): void
    {
        $product = $this->addonProduct();
        $other = $this->addonProduct();
        $variant = $product->variants->firstWhere('label', 'S');

        $engraving = $product->addons->firstWhere('name', 'Engraving');
        $engraving->forceFill(['selection_type' => ShopProductAddon::SELECTION_CHECKBOX])->save();

        $this->post(route('cart.store'), [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'addons' => [$engraving->id => [
                $engraving->variants->first()->id,
                $other->addons->firstWhere('name', 'Engraving')->variants->first()->id,
            ]],
        ])->assertSessionHasErrors('cart');

        $this->assertSame(Cart::UNAVAILABLE, session('errors')->first('cart'));
        $this->assertTrue(Cart::isEmpty());
    }

    /**
     * The shape most products have is an add-on with nothing to choose between, and
     * the input type has no say over it: a tick box is the only sensible input, and
     * the stored line is the add-on id against null exactly as before.
     */
    public function test_an_addon_with_no_options_is_a_tick_box_whatever_the_input_type(): void
    {
        $product = $this->addonProduct();
        $variant = $product->variants->firstWhere('label', 'S');

        ShopProductAddon::query()
            ->whereKey($product->addons->firstWhere('name', 'Engraving')->id)
            ->delete();

        $laser = $product->addons->firstWhere('name', 'With Laser Hole');
        $laser->forceFill(['selection_type' => ShopProductAddon::SELECTION_CHECKBOX])->save();

        $response = $this->get(route('shop.product', $product->slug));

        $response->assertOk();
        $response->assertSee('Add this');
        $response->assertSee('name="addons[' . $laser->id . ']" value="1"', false);

        $this->post(route('cart.store'), [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'addons' => [$laser->id => '1'],
        ])->assertRedirect(route('cart'));

        $this->assertSame([$laser->id => null], collect(session('shop.cart'))->sole()['addons']);
        $this->assertSame(85.0, Cart::lines()->sole()['line_total']);
    }

    /* ---------------------------------------------------------------------
     | The admin round trip
     * ------------------------------------------------------------------ */

    public function test_the_input_type_dropdown_is_on_the_form_and_survives_a_round_trip(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.shop.products.create'));

        $response->assertOk();
        $response->assertSee('Input Type');
        $response->assertSee(ShopProductAddon::SELECTION_TYPES[ShopProductAddon::SELECTION_CHECKBOX], false);

        $this->actingAs($this->admin())
            ->post(route('admin.shop.products.store'), $this->productForm([
                'addons' => [
                    $this->addonFormRow([
                        'name' => 'Engraving',
                        'price' => '10.00',
                        'selection_type' => ShopProductAddon::SELECTION_CHECKBOX,
                        'variants' => [
                            ['label' => 'Front', 'price' => ''],
                            ['label' => 'Back', 'price' => '4.00'],
                        ],
                    ]),
                ],
            ]))
            ->assertSessionHasNoErrors();

        $addon = ShopProduct::query()->sole()->addons()->sole();

        $this->assertSame(ShopProductAddon::SELECTION_CHECKBOX, $addon->selection_type);
        $this->assertTrue($addon->isCheckboxSelection());
    }

    public function test_an_unknown_posted_input_type_is_stored_as_radio(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.shop.products.store'), $this->productForm([
                'addons' => [
                    $this->addonFormRow(['name' => 'Engraving', 'selection_type' => 'dropdown']),
                ],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ShopProductAddon::SELECTION_RADIO,
            ShopProduct::query()->sole()->addons()->sole()->selection_type,
        );
    }

    /* ---------------------------------------------------------------------
     | What the group is called
     * ------------------------------------------------------------------ */

    public function test_the_typed_heading_is_used_verbatim_on_the_product_page(): void
    {
        $product = $this->addonProduct(['addon_group_name' => 'Add On Items']);

        $response = $this->get(route('shop.product', $product->slug));

        $response->assertOk();
        $response->assertSee('Add On Items');
        $response->assertDontSee('Extras');
    }

    public function test_a_blank_heading_falls_back_to_extras(): void
    {
        $product = $this->addonProduct();

        $this->assertNull($product->addon_group_name);
        $this->assertSame('Extras', $product->addonGroupName());

        $response = $this->get(route('shop.product', $product->slug));

        $response->assertOk();
        $response->assertSee('Extras');
        $response->assertSee('With Laser Hole');
    }

    public function test_the_heading_field_is_on_the_admin_form_and_survives_a_round_trip(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.shop.products.create'));

        $response->assertOk();
        $response->assertSee('What The Add-ons Are');
        $response->assertSee('name="addon_group_name"', false);

        $this->actingAs($this->admin())
            ->post(route('admin.shop.products.store'), $this->productForm([
                'addon_group_name' => 'Add On Items',
                'addons' => [$this->addonFormRow(['name' => 'Engraving'])],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Add On Items', ShopProduct::query()->sole()->addon_group_name);
    }

    /**
     * With no add-ons there is no group to name, so the word is cleared rather than
     * left behind to reappear above the next add-on somebody creates.
     */
    public function test_clearing_every_addon_clears_the_heading(): void
    {
        $product = $this->addonProduct(['addon_group_name' => 'Add On Items']);

        $this->actingAs($this->admin())
            ->put(route('admin.shop.products.update', $product), $this->productForm([
                'option_name' => 'Size',
                'addon_group_name' => 'Add On Items',
                'addons' => [],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertNull($product->refresh()->addon_group_name);
    }

    /* ---------------------------------------------------------------------
     | A price of nothing
     * ------------------------------------------------------------------ */

    /**
     * Presentation only. The row is still written and still priced at zero
     * everywhere money is recorded; what changes is that the page stops printing a
     * plus sign in front of nothing.
     */
    public function test_a_zero_priced_addon_reads_as_included_rather_than_plus_nothing(): void
    {
        $product = $this->addonProduct();

        ShopProductAddon::query()
            ->whereKey($product->addons->firstWhere('name', 'Engraving')->id)
            ->delete();

        $laser = $product->addons->firstWhere('name', 'With Laser Hole');
        $laser->forceFill(['price' => 0])->save();

        $response = $this->get(route('shop.product', $product->slug));

        $response->assertOk();
        $response->assertDontSee('+RM 0.00');
        $response->assertSee('Included');

        // And nothing about the money moved: the medal alone.
        $this->post(route('cart.store'), [
            'product_id' => $product->id,
            'variant_id' => $product->variants->firstWhere('label', 'S')->id,
            'addons' => [$laser->id => '1'],
        ])->assertRedirect(route('cart'));

        $this->assertSame(80.0, Cart::lines()->sole()['line_total']);
    }

    /**
     * An option explicitly priced at zero inside an add-on that does cost money.
     *
     * The word rather than an empty cell, because an empty cell beside a priced
     * option reads as a rendering fault. Same reasoning the registration form
     * already carries.
     */
    public function test_a_free_option_beside_a_priced_one_says_free_rather_than_nothing(): void
    {
        $product = $this->addonProduct();
        $engraving = $product->addons->firstWhere('name', 'Engraving');

        $engraving->variants->firstWhere('label', 'Front')->forceFill(['price' => 0])->save();

        $response = $this->get(route('shop.product', $product->slug));

        $response->assertOk();
        $response->assertSee('Free');
        $response->assertSee('+RM 4.00');
        $response->assertDontSee('+RM 0.00');
    }
}
