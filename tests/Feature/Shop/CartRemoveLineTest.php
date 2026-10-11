<?php

namespace Tests\Feature\Shop;

use App\Models\ShopProduct;
use App\Support\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

/**
 * Taking things out of the basket.
 *
 * Until now the only way out was typing a zero into a quantity box, which nobody
 * found on a phone. Two controls answer it: a bin on each line, and "Empty basket"
 * for the lot. Both are write routes behind a token, because a GET would let a
 * crawler or a link prefetch empty a stranger's basket.
 *
 * Every figure below is written out by hand rather than read back from Cart, so a
 * total that moved because a removal touched the wrong line is caught here instead
 * of agreeing with whatever the code now does. Two lines of the same medal: S with
 * the laser hole and Front engraving at 80 + 5 + 10 = 95 each, two of them; and M
 * with Front & Back engraving at 80 + 4 = 84, one of it.
 */
class CartRemoveLineTest extends ShopAddonTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->gatewayShopSettings();
    }

    /**
     * The session a buyer would have after adding both lines.
     *
     * Built directly rather than through the storefront, the way basket() and
     * basketWithAddons() are, and keyed with Cart::key() so the lines are keyed
     * exactly as Cart::add() would have written them.
     *
     * @return array{0: array<string, mixed>, 1: string, 2: string}  the session, the S key, the M key
     */
    private function twoLineBasket(ShopProduct $product): array
    {
        $laser = $product->addons->firstWhere('name', 'With Laser Hole');
        $engraving = $product->addons->firstWhere('name', 'Engraving');

        $small = [
            $laser->id => null,
            $engraving->id => $engraving->variants->firstWhere('label', 'Front')->id,
        ];

        $medium = [
            $engraving->id => $engraving->variants->firstWhere('label', 'Front & Back')->id,
        ];

        $smallId = $product->variants->firstWhere('label', 'S')->id;
        $mediumId = $product->variants->firstWhere('label', 'M')->id;

        $smallKey = Cart::key($product->id, $smallId, $small);
        $mediumKey = Cart::key($product->id, $mediumId, $medium);

        return [
            ['shop.cart' => [
                $smallKey => [
                    'product_id' => $product->id,
                    'variant_id' => $smallId,
                    'quantity' => 2,
                    'addons' => $small,
                ],
                $mediumKey => [
                    'product_id' => $product->id,
                    'variant_id' => $mediumId,
                    'quantity' => 1,
                    'addons' => $medium,
                ],
            ]],
            $smallKey,
            $mediumKey,
        ];
    }

    /* ---------------------------------------------------------------------
     | The page
     * ------------------------------------------------------------------ */

    public function test_the_page_renders_a_token_backed_delete_form_for_every_line_and_for_the_lot(): void
    {
        $product = $this->addonProduct();
        [$basket, $smallKey, $mediumKey] = $this->twoLineBasket($product);

        $response = $this->withSession($basket)->get(route('cart'));

        $response->assertOk();

        $response->assertSee('action="' . route('cart.remove') . '"', false);
        $response->assertSee('id="cart-remove-' . $smallKey . '"', false);
        $response->assertSee('id="cart-remove-' . $mediumKey . '"', false);
        $response->assertSee('name="key" value="' . $smallKey . '"', false);

        $response->assertSee('Empty basket');
        $response->assertSee('action="' . route('cart.clear') . '"', false);

        // Spoofed DELETE with a token on it, which is what keeps both out of reach
        // of a GET.
        $response->assertSee('name="_method" value="DELETE"', false);
        $response->assertSee('name="_token"', false);

        // Both totals still as they were, and the hint the controls replace is gone.
        $response->assertSee('RM 190.00');
        $response->assertSee('RM 84.00');
        $response->assertSee('RM 274.00');
        $response->assertDontSee('Set a quantity to 0');
    }

    /* ---------------------------------------------------------------------
     | One line
     * ------------------------------------------------------------------ */

    public function test_removing_one_line_leaves_the_other_line_and_its_total_untouched(): void
    {
        $product = $this->addonProduct();
        [$basket, $smallKey, $mediumKey] = $this->twoLineBasket($product);

        $this->withSession($basket)
            ->delete(route('cart.remove'), ['key' => $smallKey])
            ->assertRedirect(route('cart'));

        $this->assertSame([$mediumKey], array_keys(session('shop.cart')));

        $line = Cart::lines()->sole();

        $this->assertSame('M', $line['variant']->label);
        $this->assertSame(1, $line['quantity']);
        $this->assertSame(4.0, $line['addons_unit_price']);
        $this->assertSame(84.0, $line['line_total']);
        $this->assertSame(84.0, Cart::itemsTotal());
    }

    public function test_the_page_after_a_removal_prices_only_what_is_left(): void
    {
        $product = $this->addonProduct();
        [$basket, $smallKey] = $this->twoLineBasket($product);

        $this->withSession($basket)->delete(route('cart.remove'), ['key' => $smallKey]);

        $response = $this->get(route('cart'));

        $response->assertOk();
        $response->assertSee('Removed from your basket.');
        $response->assertSee('RM 84.00');
        $response->assertDontSee('RM 190.00');
        $response->assertDontSee('RM 274.00');
    }

    public function test_removing_the_last_line_empties_the_basket_and_the_page_still_renders(): void
    {
        $product = $this->addonProduct();
        $laser = $product->addons->firstWhere('name', 'With Laser Hole');
        $engraving = $product->addons->firstWhere('name', 'Engraving');
        $variant = $product->variants->firstWhere('label', 'S');

        $addons = [
            $laser->id => null,
            $engraving->id => $engraving->variants->firstWhere('label', 'Front')->id,
        ];

        $key = Cart::key($product->id, $variant->id, $addons);

        $this->withSession($this->basketWithAddons($product, $variant->id, $addons))
            ->delete(route('cart.remove'), ['key' => $key])
            ->assertRedirect(route('cart'));

        $this->assertNull(session('shop.cart'));

        $response = $this->get(route('cart'));

        $response->assertOk();
        $response->assertSee('Your basket is empty');
        $response->assertDontSee('Empty basket');
    }

    public function test_a_key_the_basket_does_not_hold_changes_nothing(): void
    {
        $product = $this->addonProduct();
        [$basket, $smallKey, $mediumKey] = $this->twoLineBasket($product);

        $this->withSession($basket)
            ->delete(route('cart.remove'), ['key' => 'nonsense'])
            ->assertRedirect(route('cart'));

        $this->assertSame([$smallKey, $mediumKey], array_keys(session('shop.cart')));
        $this->assertSame(274.0, Cart::itemsTotal());
    }

    public function test_a_quantity_of_zero_still_removes_a_line(): void
    {
        $product = $this->addonProduct();
        [$basket, $smallKey, $mediumKey] = $this->twoLineBasket($product);

        $this->withSession($basket)
            ->put(route('cart.update'), ['quantities' => [$smallKey => 0, $mediumKey => 1]])
            ->assertRedirect(route('cart'));

        $this->assertSame([$mediumKey], array_keys(session('shop.cart')));
        $this->assertSame(84.0, Cart::itemsTotal());
    }

    /* ---------------------------------------------------------------------
     | The lot
     * ------------------------------------------------------------------ */

    public function test_empty_basket_clears_every_line(): void
    {
        $product = $this->addonProduct();
        [$basket] = $this->twoLineBasket($product);

        $this->withSession($basket)
            ->delete(route('cart.clear'))
            ->assertRedirect(route('shop'));

        $this->assertNull(session('shop.cart'));
        $this->assertTrue(Cart::lines()->isEmpty());
        $this->assertSame(0.0, Cart::itemsTotal());
    }

    /* ---------------------------------------------------------------------
     | Not over GET
     * ------------------------------------------------------------------ */

    public function test_a_get_cannot_remove_a_line(): void
    {
        $product = $this->addonProduct();
        [$basket, $smallKey, $mediumKey] = $this->twoLineBasket($product);

        $this->withSession($basket)
            ->get(route('cart.remove') . '?key=' . urlencode($smallKey))
            ->assertStatus(405);

        $this->assertSame([$smallKey, $mediumKey], array_keys(session('shop.cart')));
    }

    public function test_a_get_cannot_empty_the_basket(): void
    {
        $product = $this->addonProduct();
        [$basket, $smallKey, $mediumKey] = $this->twoLineBasket($product);

        // route('cart.clear') is /cart, which over GET is the basket page itself.
        // So the assertion is not a 405 but that nothing was cleared by asking for
        // it: a prefetch of this URL shows the basket rather than emptying it.
        $this->withSession($basket)
            ->get(route('cart.clear'))
            ->assertOk();

        $this->assertSame([$smallKey, $mediumKey], array_keys(session('shop.cart')));
        $this->assertSame(274.0, Cart::itemsTotal());
    }
}
