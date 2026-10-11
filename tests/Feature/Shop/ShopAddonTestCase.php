<?php

namespace Tests\Feature\Shop;

use App\Models\ShopOrder;
use App\Models\ShopProduct;
use App\Support\Cart;

/**
 * Fixtures shared by the product add-on tests.
 *
 * Abstract, so PHPUnit does not try to run it. Additive only: it extends
 * ShopPaymentTestCase and adds helpers beside the existing ones rather than
 * changing any of them, because the payment tests depend on those exactly as they
 * are.
 *
 * No factories, following the rest of tests/Feature/Shop: every row is an explicit
 * Model::create() with a uniqid() suffix so two tests in one run cannot collide on
 * a unique slug or SKU.
 */
abstract class ShopAddonTestCase extends ShopPaymentTestCase
{
    /**
     * A posted product with three sizes and two add-ons.
     *
     * The pair of add-ons is deliberate, because the two shapes behave differently
     * everywhere: "With Laser Hole" is optional with no options, so it is a tick
     * box; "Engraving" is compulsory with options, so a choice has to be made and
     * the first option charges the add-on price by leaving its own price null.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function addonProduct(array $overrides = []): ShopProduct
    {
        $product = ShopProduct::create($overrides + [
            'slug' => 'medal-' . uniqid(),
            'name' => 'Champion Medal',
            'sku' => 'SKU-' . strtoupper(uniqid()),
            'short_description' => 'A medal.',
            'price' => 80.00,
            'track_inventory' => true,
            'stock_quantity' => 50,
            'stock_taken' => 0,
            'weight_grams' => 300,
            'option_name' => 'Size',
            'status' => ShopProduct::STATUS_ACTIVE,
            'payment_methods' => [ShopOrder::METHOD_GATEWAY],
            'fulfilment' => ShopProduct::FULFILMENT_ONLINE,
        ]);

        foreach (['S', 'M', 'L'] as $position => $label) {
            $product->variants()->create([
                'label' => $label,
                'stock' => 10,
                'stock_taken' => 0,
                'sort_order' => $position,
            ]);
        }

        $product->addons()->create([
            'name' => 'With Laser Hole',
            'description' => 'A hole for the ribbon.',
            'price' => 5.00,
            'is_required' => false,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $engraving = $product->addons()->create([
            'name' => 'Engraving',
            'price' => 10.00,
            'is_required' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // Null price, so this option charges the add-on's 10.00. The second one
        // costs more and says so.
        $engraving->variants()->create(['label' => 'Front', 'price' => null, 'sort_order' => 0]);
        $engraving->variants()->create(['label' => 'Front & Back', 'price' => 4.00, 'sort_order' => 1]);

        return $product->fresh(['variants', 'addons.variants']);
    }

    /**
     * The session a buyer would have after adding this product with extras.
     *
     * Built directly rather than through Cart::add(), the same way basket() is, so
     * a test can set up a basket without going through the storefront. The line
     * carries an 'addons' key mapping add-on id => option id or null, which is the
     * shape the cart reads.
     *
     * @param  array<int, int|null>  $addons  add-on id => chosen option id, or null
     * @return array<string, mixed>
     */
    protected function basketWithAddons(
        ShopProduct $product,
        ?int $variantId = null,
        array $addons = [],
        int $quantity = 1,
    ): array {
        $line = [
            'product_id' => $product->id,
            'variant_id' => $variantId,
            'quantity' => $quantity,
        ];

        // Only when something was chosen, so a basket with no extras stays exactly
        // the shape the existing tests assert.
        if ($addons !== []) {
            $line['addons'] = $addons;
        }

        // The selection goes into the key as well, because Cart::add() fingerprints
        // it there: the same medal with and without engraving are two lines, and a
        // helper that keyed them the same would not be setting up what the cart
        // would actually have written.
        return ['shop.cart' => [Cart::key($product->id, $variantId, $addons) => $line]];
    }

    /**
     * The product form as the admin screen posts it, with the add-on rows merged in.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function productForm(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Champion Medal',
            'price' => 80,
            'status' => ShopProduct::STATUS_ACTIVE,
            'fulfilment' => ShopProduct::FULFILMENT_ONLINE,
            'payment_methods' => [ShopOrder::METHOD_GATEWAY],
            'track_inventory' => '1',
            'stock_quantity' => 10,
            'low_stock_threshold' => 2,
            'sort_order' => 0,
        ];
    }

    /**
     * An add-on row in the shape the builder submits.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function addonFormRow(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Laser Engraving',
            'price' => '5.00',
            'description' => '',
            'max_quantity' => '',
            'stock' => '',

            // The hidden 0 plus the tick, exactly as the form posts it.
            'is_active' => '1',
            'is_required' => '0',
        ];
    }
}
