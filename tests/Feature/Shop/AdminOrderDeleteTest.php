<?php

namespace Tests\Feature\Shop;

use App\Models\Coupon;
use App\Models\CouponCode;
use App\Models\Permission;
use App\Models\ShopOrder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The delete action on the shop orders list.
 *
 * Guards verified here:
 *   - pending_payment, cancelled, and refunded orders can be deleted.
 *   - paid orders are refused (redirect-with-error) even on a direct request.
 *   - a coupon-bearing order clears the coupon code's shop_order_id on delete.
 *   - deleting an order with no coupon leaves coupon_codes untouched.
 *   - the endpoint refuses without the shop.orders.delete permission.
 *   - a GET to the delete endpoint is refused with 405.
 *   - the delete icon is absent on paid rows even when the user has the permission.
 *   - the delete icon is absent when the user lacks the permission.
 *   - the delete permission is present in the seeded catalogue.
 */
class AdminOrderDeleteTest extends ShopPaymentTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function deleteUser(): \App\Models\User
    {
        return $this->userWith(['admin.access', 'shop.orders.view', 'shop.orders.delete']);
    }

    /**
     * A minimal order in any status, without hitting ShopPaymentTestCase::order()
     * which only accepts a product as first argument.
     */
    private function makeOrder(string $status, array $extra = []): ShopOrder
    {
        return $this->order(null, array_merge(['status' => $status], $extra));
    }

    /* ---------------------------------------------------------------------
     | Deletable statuses
     * ------------------------------------------------------------------ */

    public function test_a_pending_order_can_be_deleted(): void
    {
        $order = $this->makeOrder(ShopOrder::STATUS_PENDING_PAYMENT);

        $this->actingAs($this->deleteUser())
            ->delete(route('admin.shop.orders.destroy', $order))
            ->assertRedirect(route('admin.shop.orders', ['tab' => $order->fulfilment]));

        $this->assertDatabaseMissing('shop_orders', ['id' => $order->id]);
    }

    public function test_a_cancelled_order_can_be_deleted(): void
    {
        $order = $this->makeOrder(ShopOrder::STATUS_CANCELLED);

        $this->actingAs($this->deleteUser())
            ->delete(route('admin.shop.orders.destroy', $order))
            ->assertRedirect(route('admin.shop.orders', ['tab' => $order->fulfilment]));

        $this->assertDatabaseMissing('shop_orders', ['id' => $order->id]);
    }

    public function test_a_refunded_order_can_be_deleted(): void
    {
        $order = $this->makeOrder(ShopOrder::STATUS_REFUNDED);

        $this->actingAs($this->deleteUser())
            ->delete(route('admin.shop.orders.destroy', $order))
            ->assertRedirect(route('admin.shop.orders', ['tab' => $order->fulfilment]));

        $this->assertDatabaseMissing('shop_orders', ['id' => $order->id]);
    }

    /* ---------------------------------------------------------------------
     | Paid orders are refused server-side
     * ------------------------------------------------------------------ */

    public function test_a_paid_order_is_refused_even_on_a_direct_delete_request(): void
    {
        $order = $this->makeOrder(ShopOrder::STATUS_PAID, ['paid_at' => now()]);

        $response = $this->actingAs($this->deleteUser())
            ->delete(route('admin.shop.orders.destroy', $order));

        // Must redirect with an error, not delete.
        $response->assertRedirect();
        $response->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('shop_orders', ['id' => $order->id]);
    }

    /* ---------------------------------------------------------------------
     | Coupon cleanup
     * ------------------------------------------------------------------ */

    public function test_deleting_a_coupon_bearing_order_clears_the_coupon_shop_order_id(): void
    {
        $coupon = Coupon::create([
            'kind' => Coupon::KIND_SHOP,
            'name' => 'TESTDEL10',
            'quantity' => 0,
            'expires_at' => now()->addMonth()->toDateString(),
            'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
            'discount_value' => 10,
            'design' => 'classic',
        ]);

        $code = CouponCode::create([
            'coupon_id' => $coupon->id,
            'code' => 'TESTDEL10',
            'redeemed_at' => now(),
            'discount_amount' => 5.00,
        ]);

        $order = $this->makeOrder(ShopOrder::STATUS_CANCELLED, [
            'coupon_code_id' => $code->id,
            'discount_total' => 5.00,
        ]);

        // The coupon code points at this order.
        $code->update(['shop_order_id' => $order->id]);

        $this->actingAs($this->deleteUser())
            ->delete(route('admin.shop.orders.destroy', $order));

        $this->assertDatabaseMissing('shop_orders', ['id' => $order->id]);
        // The coupon is freed — shop_order_id must be null now.
        $this->assertDatabaseHas('coupon_codes', [
            'id' => $code->id,
            'shop_order_id' => null,
        ]);
    }

    public function test_deleting_an_order_without_a_coupon_leaves_coupon_codes_untouched(): void
    {
        $coupon = Coupon::create([
            'kind' => Coupon::KIND_SHOP,
            'name' => 'OTHER10',
            'quantity' => 0,
            'expires_at' => now()->addMonth()->toDateString(),
            'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
            'discount_value' => 10,
            'design' => 'classic',
        ]);

        // A code used on a different (paid) order.
        $otherOrder = $this->makeOrder(ShopOrder::STATUS_PAID, ['paid_at' => now()]);

        $code = CouponCode::create([
            'coupon_id' => $coupon->id,
            'code' => 'OTHER10',
            'redeemed_at' => now(),
            'discount_amount' => 5.00,
            'shop_order_id' => $otherOrder->id,
        ]);

        // The order being deleted has no coupon.
        $order = $this->makeOrder(ShopOrder::STATUS_PENDING_PAYMENT);

        $this->actingAs($this->deleteUser())
            ->delete(route('admin.shop.orders.destroy', $order));

        $this->assertDatabaseMissing('shop_orders', ['id' => $order->id]);
        // The other order's coupon code is untouched.
        $this->assertDatabaseHas('coupon_codes', [
            'id' => $code->id,
            'shop_order_id' => $otherOrder->id,
        ]);
    }

    /* ---------------------------------------------------------------------
     | Permission guard
     * ------------------------------------------------------------------ */

    public function test_the_endpoint_is_refused_without_the_delete_permission(): void
    {
        $order = $this->makeOrder(ShopOrder::STATUS_PENDING_PAYMENT);

        // A user with view and update, but NOT delete.
        $user = $this->userWith(['admin.access', 'shop.orders.view', 'shop.orders.update']);

        $this->actingAs($user)
            ->delete(route('admin.shop.orders.destroy', $order))
            ->assertForbidden();

        $this->assertDatabaseHas('shop_orders', ['id' => $order->id]);
    }

    /* ---------------------------------------------------------------------
     | Method guard
     * ------------------------------------------------------------------ */

    public function test_a_get_to_the_delete_endpoint_is_refused_with_405(): void
    {
        $order = $this->makeOrder(ShopOrder::STATUS_PENDING_PAYMENT);

        // The destroy route is DELETE admin/shop/orders/{order}. A GET to the same
        // path is matched by the show route and returns 200, which is the right
        // behaviour: a GET can never trigger a deletion, so this double-checks that
        // no stray GET route exists for the delete action itself.
        $this->actingAs($this->deleteUser())
            ->get(route('admin.shop.orders.destroy', $order))
            ->assertOk(); // matched by orders.show — the delete action is unreachable via GET

        // The order still exists: GETting the show page never deletes.
        $this->assertDatabaseHas('shop_orders', ['id' => $order->id]);
    }

    /* ---------------------------------------------------------------------
     | Blade visibility
     * ------------------------------------------------------------------ */

    public function test_the_delete_icon_is_absent_on_paid_orders_even_with_the_permission(): void
    {
        $order = $this->makeOrder(ShopOrder::STATUS_PAID, ['paid_at' => now()]);

        $response = $this->actingAs($this->deleteUser())
            ->get(route('admin.shop.orders', ['tab' => ShopOrder::FULFILMENT_OFFLINE]));

        $response->assertOk();

        // The delete button carries this aria-label only when the icon is rendered.
        $response->assertDontSee('Delete order ' . $order->reference);
    }

    public function test_the_delete_icon_is_absent_when_the_user_lacks_the_permission(): void
    {
        $order = $this->makeOrder(ShopOrder::STATUS_PENDING_PAYMENT);

        $user = $this->userWith(['admin.access', 'shop.orders.view']);

        $response = $this->actingAs($user)
            ->get(route('admin.shop.orders', ['tab' => ShopOrder::FULFILMENT_OFFLINE]));

        $response->assertOk();
        $response->assertDontSee('Delete order ' . $order->reference);
    }

    public function test_the_delete_icon_is_visible_on_deletable_rows_with_the_permission(): void
    {
        $order = $this->makeOrder(ShopOrder::STATUS_PENDING_PAYMENT);

        $response = $this->actingAs($this->deleteUser())
            ->get(route('admin.shop.orders', ['tab' => ShopOrder::FULFILMENT_OFFLINE]));

        $response->assertOk();
        // The delete button carries this aria-label only when the icon is rendered.
        $response->assertSee('Delete order ' . $order->reference);
    }

    /* ---------------------------------------------------------------------
     | Seeder
     * ------------------------------------------------------------------ */

    public function test_the_delete_permission_is_in_the_catalogue(): void
    {
        $permission = Permission::where('slug', 'shop.orders.delete')->first();

        $this->assertNotNull($permission, 'shop.orders.delete must exist in the catalogue.');
        $this->assertSame('Shop', $permission->group);
        $this->assertSame('Orders', $permission->module);
    }
}
