<?php

namespace Tests\Feature\Shop;

use App\Models\ShopProduct;
use App\Models\ShopProductAddon;
use App\Models\ShopProductAddonVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The admin side of product add-ons: the panel renders, and the builder's rows
 * survive a round trip without taking anything away that has been sold.
 *
 * Nothing public is touched by this feature, so nothing here asserts a storefront
 * total. What it does assert is the part that cannot be undone once a product has
 * been bought: an edit updates rows in place, so stock_taken and the order lines
 * pointing at them stay where they are.
 */
class ProductAddonCatalogueTest extends ShopAddonTestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return $this->userWith([
            // Without this the admin middleware ends the session before the route
            // is reached, and every assertion below would fail for the wrong reason.
            'admin.access',
            'shop.products.view',
            'shop.products.create',
            'shop.products.update',
        ]);
    }

    /* ---------------------------------------------------------------------
     | The screens
     * ------------------------------------------------------------------ */

    public function test_the_create_screen_renders_the_addons_panel(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.shop.products.create'));

        $response->assertOk();
        $response->assertSee('Add-ons');
        $response->assertSee('Offer this add-on');
        $response->assertSee('the buyer must choose it', false);

        // Its ids must not be the size builder's, or one builder would clone the
        // other's rows. Both are expected on the page, separately.
        $response->assertSee('product-addon-template');
        $response->assertSee('variant-template');
    }

    public function test_the_edit_screen_renders_the_stored_addons(): void
    {
        $product = $this->addonProduct();

        $response = $this->actingAs($this->admin())->get(route('admin.shop.products.edit', $product));

        $response->assertOk();
        $response->assertSee('Add-ons');
        $response->assertSee('With Laser Hole');
        $response->assertSee('Engraving');
        $response->assertSee('Front &amp; Back', false);
        $response->assertSee('Offer this add-on');

        /*
         | Both builders, on a page that already has saved rows of each kind. The
         | add buttons and the templates are what the two scripts bind to, and each
         | script returns early when any of its own elements is missing — so a
         | renamed or shared id would silently leave one of them dead on this
         | screen rather than erroring.
         */
        $response->assertSee('id="variant-list"', false);
        $response->assertSee('id="variant-add"', false);
        $response->assertSee('id="variant-template"', false);
        $response->assertSee('id="product-addon-list"', false);
        $response->assertSee('id="product-addon-add"', false);
        $response->assertSee('id="product-addon-template"', false);
        $response->assertSee('id="product-addon-option-template"', false);
        $response->assertSee('data-variant-row', false);
        $response->assertSee('data-product-addon-row', false);
    }

    /* ---------------------------------------------------------------------
     | Writing
     * ------------------------------------------------------------------ */

    public function test_posting_addons_with_options_writes_both_tables_in_form_order(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.shop.products.store'), $this->productForm([
                'addons' => [
                    $this->addonFormRow(['name' => 'With Laser Hole', 'price' => '5.00']),
                    $this->addonFormRow([
                        'name' => 'Engraving',
                        'price' => '10.00',
                        'is_required' => '1',
                        'variants' => [
                            ['label' => 'Front', 'price' => '', 'stock' => ''],
                            ['label' => 'Front & Back', 'price' => '4.00', 'stock' => '6'],
                        ],
                    ]),
                ],
            ]))
            ->assertSessionHasNoErrors();

        $product = ShopProduct::query()->sole();
        $addons = $product->addons()->with('variants')->get();

        $this->assertSame(['With Laser Hole', 'Engraving'], $addons->pluck('name')->all());
        $this->assertSame([0, 1], $addons->pluck('sort_order')->all());

        $laser = $addons->firstWhere('name', 'With Laser Hole');
        $this->assertSame('5.00', $laser->price);
        $this->assertFalse($laser->is_required);
        $this->assertTrue($laser->is_active);

        // Blank stays null, because null means unlimited and zero means sold out.
        $this->assertNull($laser->stock);
        $this->assertNull($laser->max_quantity);
        $this->assertCount(0, $laser->variants);

        $engraving = $addons->firstWhere('name', 'Engraving');
        $this->assertTrue($engraving->is_required);
        $this->assertSame(['Front', 'Front & Back'], $engraving->variants->pluck('label')->all());
        $this->assertSame([0, 1], $engraving->variants->pluck('sort_order')->all());

        // A blank option price means "charge the add-on's price", so it is stored
        // as null and resolves to the 10.00 above.
        $front = $engraving->variants->firstWhere('label', 'Front');
        $this->assertNull($front->price);
        $this->assertSame(10.00, $front->unitPrice());
        $this->assertNull($front->stock);

        $both = $engraving->variants->firstWhere('label', 'Front & Back');
        $this->assertSame('4.00', $both->price);
        $this->assertSame(6, $both->stock);
    }

    public function test_reposting_with_ids_edits_in_place_and_leaves_stock_taken_alone(): void
    {
        $product = $this->addonProduct();
        $engraving = $product->addons->firstWhere('name', 'Engraving');
        $front = $engraving->variants->firstWhere('label', 'Front');

        // What the order flow would have written on the move into paid.
        $engraving->forceFill(['stock' => 20, 'stock_taken' => 3])->save();
        $front->forceFill(['stock' => 8, 'stock_taken' => 2])->save();

        $this->actingAs($this->admin())
            ->put(route('admin.shop.products.update', $product), $this->productForm([
                'option_name' => 'Size',
                'variants' => $product->variants
                    ->map(fn ($variant) => ['id' => $variant->id, 'label' => $variant->label, 'stock' => $variant->stock])
                    ->all(),
                'addons' => [
                    $this->addonFormRow([
                        'id' => $engraving->id,
                        'name' => 'Engraving Service',
                        'price' => '12.50',
                        'stock' => '25',
                        'is_required' => '1',
                        'variants' => [
                            ['id' => $front->id, 'label' => 'Front Only', 'price' => '', 'stock' => '9'],
                        ],
                    ]),
                ],
            ]))
            ->assertSessionHasNoErrors();

        $engraving->refresh();
        $front->refresh();

        $this->assertSame($engraving->id, $product->fresh()->addons()->sole()->id);
        $this->assertSame('Engraving Service', $engraving->name);
        $this->assertSame('12.50', $engraving->price);
        $this->assertSame(25, $engraving->stock);

        // The whole point of updating in place: the counts the order flow owns are
        // not touched by a form that never submits them.
        $this->assertSame(3, $engraving->stock_taken);
        $this->assertSame('Front Only', $front->label);
        $this->assertSame(9, $front->stock);
        $this->assertSame(2, $front->stock_taken);
    }

    public function test_swapping_two_option_labels_is_accepted(): void
    {
        $product = $this->addonProduct();
        $engraving = $product->addons->firstWhere('name', 'Engraving');
        $front = $engraving->variants->firstWhere('label', 'Front');
        $both = $engraving->variants->firstWhere('label', 'Front & Back');

        $this->actingAs($this->admin())
            ->put(route('admin.shop.products.update', $product), $this->productForm([
                'option_name' => 'Size',
                'variants' => $product->variants
                    ->map(fn ($variant) => ['id' => $variant->id, 'label' => $variant->label])
                    ->all(),
                'addons' => [
                    $this->addonFormRow([
                        'id' => $engraving->id,
                        'name' => 'Engraving',
                        'price' => '10.00',
                        'is_required' => '1',
                        'variants' => [
                            // The labels trade places, which the unique index on
                            // (add-on, label) would refuse without the parking step.
                            ['id' => $front->id, 'label' => 'Front & Back', 'price' => '4.00'],
                            ['id' => $both->id, 'label' => 'Front', 'price' => ''],
                        ],
                    ]),
                ],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Front & Back', $front->refresh()->label);
        $this->assertSame('Front', $both->refresh()->label);
    }

    public function test_a_duplicate_option_label_within_one_addon_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.shop.products.store'), $this->productForm([
                'addons' => [
                    $this->addonFormRow([
                        'name' => 'Engraving',
                        'variants' => [
                            ['label' => 'Front', 'price' => ''],
                            ['label' => 'front', 'price' => ''],
                        ],
                    ]),
                ],
            ]))
            ->assertSessionHasErrors('addons.0.variants.1.label');

        $this->assertSame(0, ShopProduct::query()->count());
    }

    public function test_an_untouched_blank_row_does_not_block_the_save(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.shop.products.store'), $this->productForm([
                'addons' => [
                    // A stray click on "Add an Add-on" leaves exactly this.
                    ['name' => '', 'price' => '', 'description' => '', 'max_quantity' => '', 'stock' => '', 'is_active' => '0', 'is_required' => '0'],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, ShopProduct::query()->sole()->addons()->count());
    }

    /* ---------------------------------------------------------------------
     | What has been sold stays
     * ------------------------------------------------------------------ */

    public function test_dropping_an_addon_that_has_been_ordered_is_refused_by_name(): void
    {
        $product = $this->addonProduct();
        $laser = $product->addons->firstWhere('name', 'With Laser Hole');
        $laser->forceFill(['stock' => 10, 'stock_taken' => 4])->save();

        $response = $this->actingAs($this->admin())
            ->put(route('admin.shop.products.update', $product), $this->productForm([
                'option_name' => 'Size',
                'addons' => [],
            ]));

        $response->assertSessionHasErrors('addons');

        $message = session('errors')->first('addons');
        $this->assertStringContainsString('With Laser Hole', $message);
        $this->assertStringContainsString('4 order(s)', $message);

        $this->assertTrue($product->addons()->whereKey($laser->id)->exists());
    }

    public function test_dropping_an_option_that_has_been_ordered_is_refused_by_name(): void
    {
        $product = $this->addonProduct();
        $engraving = $product->addons->firstWhere('name', 'Engraving');
        $front = $engraving->variants->firstWhere('label', 'Front');
        $front->forceFill(['stock' => 8, 'stock_taken' => 2])->save();

        $response = $this->actingAs($this->admin())
            ->put(route('admin.shop.products.update', $product), $this->productForm([
                'option_name' => 'Size',
                'addons' => [
                    $this->addonFormRow([
                        'id' => $engraving->id,
                        'name' => 'Engraving',
                        'price' => '10.00',
                        'is_required' => '1',

                        // "Front" is gone from the list, and it has been bought.
                        'variants' => [
                            ['id' => $engraving->variants->firstWhere('label', 'Front & Back')->id, 'label' => 'Front & Back', 'price' => '4.00'],
                        ],
                    ]),
                ],
            ]));

        $response->assertSessionHasErrors('addons');
        $this->assertStringContainsString('Front', session('errors')->first('addons'));

        $this->assertTrue(ShopProductAddonVariant::query()->whereKey($front->id)->exists());
    }

    public function test_stock_cannot_be_set_below_what_has_already_gone(): void
    {
        $product = $this->addonProduct();
        $laser = $product->addons->firstWhere('name', 'With Laser Hole');
        $laser->forceFill(['stock' => 10, 'stock_taken' => 6])->save();

        $this->actingAs($this->admin())
            ->put(route('admin.shop.products.update', $product), $this->productForm([
                'option_name' => 'Size',
                'addons' => [
                    $this->addonFormRow([
                        'id' => $laser->id,
                        'name' => 'With Laser Hole',
                        'price' => '5.00',
                        'stock' => '3',
                    ]),
                    $this->addonFormRow([
                        'id' => $product->addons->firstWhere('name', 'Engraving')->id,
                        'name' => 'Engraving',
                        'price' => '10.00',
                        'is_required' => '1',
                        'variants' => $product->addons->firstWhere('name', 'Engraving')->variants
                            ->map(fn ($option) => ['id' => $option->id, 'label' => $option->label, 'price' => $option->price])
                            ->all(),
                    ]),
                ],
            ]))
            ->assertSessionHasErrors('addons.0.stock');

        $this->assertSame(10, $laser->refresh()->stock);
    }

    public function test_is_required_persists_through_a_round_trip(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.shop.products.store'), $this->productForm([
                'addons' => [
                    $this->addonFormRow(['name' => 'Gift Wrap', 'price' => '3.00', 'is_required' => '1']),
                ],
            ]))
            ->assertSessionHasNoErrors();

        $product = ShopProduct::query()->sole();
        $addon = $product->addons()->sole();

        $this->assertTrue($addon->is_required);

        $this->actingAs($this->admin())
            ->put(route('admin.shop.products.update', $product), $this->productForm([
                'addons' => [
                    $this->addonFormRow([
                        'id' => $addon->id,
                        'name' => 'Gift Wrap',
                        'price' => '3.00',
                        'is_required' => '1',
                    ]),
                ],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($addon->refresh()->is_required);

        // And unticking it comes back off, rather than sticking because the box
        // sends nothing when cleared.
        $this->actingAs($this->admin())
            ->put(route('admin.shop.products.update', $product), $this->productForm([
                'addons' => [
                    $this->addonFormRow([
                        'id' => $addon->id,
                        'name' => 'Gift Wrap',
                        'price' => '3.00',
                        'is_required' => '0',
                    ]),
                ],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($addon->refresh()->is_required);
        $this->assertSame(1, ShopProductAddon::query()->count());
    }
}
