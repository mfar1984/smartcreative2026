<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\Event;
use App\Models\EventAddon;
use App\Models\EventAddonVariant;
use App\Models\EventRegistration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\ShopOrder;
use App\Models\ShopProduct;
use App\Models\User;
use App\Support\Cart;
use App\Support\ParticipantOptions;
use App\Support\PaymentSettings;
use App\Support\ShopSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Fixtures shared by the coupon tests.
 *
 * Abstract, so PHPUnit does not try to run it. No factories: this project has none
 * beyond UserFactory, so every row is an explicit Model::create() with a uniqid()
 * suffix, following tests/Feature/Registration and tests/Feature/Shop.
 */
abstract class CouponTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Nothing here has anything to prove about mail, and a registration raises
        // several notifications.
        Mail::fake();
    }

    /* ---------------------------------------------------------------------
     | Coupons
     * ------------------------------------------------------------------ */

    /**
     * A coupon. Its name is the code, and `quantity` is how many uses it allows.
     *
     * Nothing is written to coupon_codes: that table is a ledger now, and a row in it
     * only exists once somebody has redeemed.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function coupon(array $overrides = []): Coupon
    {
        return Coupon::create($overrides + [
            'kind' => Coupon::KIND_EVENT,
            'name' => strtoupper(substr(md5(uniqid('', true)), 0, 8)),
            'quantity' => 0,
            'expires_at' => now()->addMonth()->toDateString(),
            'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
            'discount_value' => 10,
            'design' => 'classic',
        ]);
    }

    /** A percentage coupon, unlimited unless told otherwise. */
    protected function percentageCoupon(float $percent, array $overrides = []): Coupon
    {
        return $this->coupon($overrides + [
            'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
            'discount_value' => $percent,
        ]);
    }

    /** A fixed-ringgit coupon, unlimited unless told otherwise. */
    protected function fixedCoupon(float $ringgit, array $overrides = []): Coupon
    {
        return $this->coupon($overrides + [
            'discount_type' => Coupon::DISCOUNT_FIXED,
            'discount_value' => $ringgit,
        ]);
    }

    /* ---------------------------------------------------------------------
     | Events and registrations
     * ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function event(array $overrides = []): Event
    {
        return Event::create($overrides + [
            'slug' => 'event-' . uniqid(),
            'title' => 'Hari Sukan Negara',
            'category' => 'Community',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'location' => 'Sibu',
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_INDIVIDUAL,
            'fee' => 100,
            'seats_total' => 0,
            'min_players' => 1,
        ]);
    }

    /**
     * A registration as the public controller would have written it: the charge split
     * across the two columns that say what it is for, and `amount` their sum.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function registration(Event $event, array $overrides = [], int $people = 1): EventRegistration
    {
        $fee = $overrides['registration_fee'] ?? $event->registrationAmount();
        $addons = $overrides['addons_total'] ?? 0;

        $registration = EventRegistration::create($overrides + [
            'event_id' => $event->id,
            'reference' => EventRegistration::nextReference(),
            'mode' => $event->registration_mode,
            'status' => EventRegistration::STATUS_PENDING,
            'payment_status' => EventRegistration::PAYMENT_UNPAID,
            'registration_fee' => $fee,
            'addons_total' => $addons,
            'amount' => round((float) $fee + (float) $addons, 2),
        ]);

        for ($i = 1; $i <= $people; $i++) {
            $registration->participants()->create([
                'role' => ParticipantOptions::ROLE_PARTICIPANT,
                'full_name' => 'Member ' . $i,
                'ic_number' => '900101' . str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'address_line_1' => $i . ' Jalan Sibu',
                'city' => 'Sibu',
                'state' => 'Sarawak',
                'country' => 'Malaysia',
                'phone' => '01400000' . $i,
                'email' => 'member' . $i . '-' . uniqid() . '@example.com',
                'gender' => 'male',
                'race' => 'malay',
            ]);
        }

        return $registration->fresh(['participants', 'event']);
    }

    /**
     * A compulsory per-head shirt, as a radio group, priced at $price.
     *
     * @return array{0: EventAddon, 1: EventAddonVariant}
     */
    protected function shirt(Event $event, float $price = 40): array
    {
        $addon = EventAddon::create([
            'event_id' => $event->id,
            'name' => 'EVENT TEE',
            'price' => $price,
            'is_required' => true,
            'is_active' => true,
            'selection_type' => EventAddon::SELECTION_RADIO,
        ]);

        $small = EventAddonVariant::create([
            'event_addon_id' => $addon->id,
            'label' => 'S',
            'price' => 0,
            'stock' => 100,
            'sort_order' => 1,
        ]);

        return [$addon->fresh(), $small];
    }

    /* ---------------------------------------------------------------------
     | Shop
     * ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function product(array $overrides = []): ShopProduct
    {
        return ShopProduct::create($overrides + [
            'slug' => 'jersey-' . uniqid(),
            'name' => 'Team Jersey',
            'sku' => 'SKU-' . strtoupper(uniqid()),
            'short_description' => 'A jersey.',
            'description' => 'A jersey.',
            'price' => 50.00,
            'track_inventory' => true,
            'stock_quantity' => 50,
            'stock_taken' => 0,
            'weight_grams' => 300,
            'status' => ShopProduct::STATUS_ACTIVE,
            'payment_methods' => [ShopOrder::METHOD_GATEWAY],
            'fulfilment' => ShopProduct::FULFILMENT_ONLINE,
        ]);
    }

    /**
     * A shop that is open with CHIP configured, so the checkout offers card payment.
     *
     * ShopSettings memoises its reads for the request, so it is flushed after the
     * writes land or a test that opens the shop would still see it closed.
     */
    protected function shopOpenWithGateway(): void
    {
        Setting::write('shop.enabled', '1', 'shop');
        Setting::write('integration.payments.provider', PaymentSettings::PROVIDER_CHIP, 'integration.payments');
        Setting::write('integration.payments.chip_brand_id', 'brand-' . uniqid(), 'integration.payments');
        Setting::write('integration.payments.chip_api_key', 'key-' . uniqid(), 'integration.payments');
        Setting::write('integration.payments.currency', 'MYR', 'integration.payments');

        ShopSettings::flush();
    }

    /** CHIP accepts a purchase and hands back a checkout URL. */
    protected function fakeChipPurchase(string $purchaseId = 'pur_test_1'): void
    {
        Http::fake([
            'gate.chip-in.asia/api/v1/purchases/' => Http::response([
                'id' => $purchaseId,
                'checkout_url' => 'https://gate.chip-in.asia/p/' . $purchaseId,
            ]),
        ]);
    }

    /** A flat postage charge with no free-delivery threshold in the way. */
    protected function flatShipping(float $rate = 10.0): void
    {
        Setting::write('integration.shipping.flat_rate_west', (string) $rate, 'integration.shipping');
        Setting::write('integration.shipping.flat_rate_east', (string) $rate, 'integration.shipping');
        Setting::write('integration.shipping.free_shipping_threshold', null, 'integration.shipping');
    }

    /**
     * The session a buyer would have after adding this product.
     *
     * @return array<string, mixed>
     */
    protected function basket(ShopProduct $product, int $quantity = 1): array
    {
        return [
            'shop.cart' => [
                Cart::key($product->id, null) => [
                    'product_id' => $product->id,
                    'variant_id' => null,
                    'quantity' => $quantity,
                ],
            ],
        ];
    }

    /**
     * The buyer's half of the checkout form.
     *
     * @return array<string, string>
     */
    protected function checkoutFields(array $extra = []): array
    {
        return $extra + [
            'customer_name' => 'Aminah Yusof',
            'customer_email' => 'buyer-' . uniqid() . '@example.com',
            'customer_phone' => '0123456789',
            'address_line_1' => '1 Jalan Satu',
            'postcode' => '40000',
            'city' => 'Shah Alam',
            'state' => 'Selangor',
            'payment_method' => ShopOrder::METHOD_GATEWAY,
        ];
    }

    /**
     * One participant's half of the public registration form.
     *
     * @return array<string, string>
     */
    protected function participantFields(array $extra = []): array
    {
        return $extra + [
            'role' => ParticipantOptions::ROLE_PARTICIPANT,
            'full_name' => 'Member One',
            'ic_number' => '9001010' . random_int(10000, 99999),
            'address_line_1' => '1 Jalan Sibu',
            'city' => 'Sibu',
            'state' => 'Sarawak',
            'country' => 'Malaysia',
            'phone' => '0140000001',
            'email' => 'member-' . uniqid() . '@example.com',
            'gender' => 'male',
            'race' => 'malay',
        ];
    }

    /* ---------------------------------------------------------------------
     | Accounts
     * ------------------------------------------------------------------ */

    /**
     * A user holding exactly the named permission slugs, and nothing else.
     *
     * A real role with real pivot rows rather than the super-admin shortcut, because
     * half of what these tests check is that a screen is unreachable without its
     * permission — and super admin short-circuits hasPermission() to true, which would
     * make that assertion pass for the wrong reason.
     *
     * @param  array<int, string>  $permissions
     */
    protected function userWith(array $permissions): User
    {
        $role = Role::create([
            'slug' => 'staff-' . uniqid(),
            'name' => 'Staff',
            'is_active' => true,
        ]);

        $ids = [];

        foreach (array_unique(['admin.access', ...$permissions]) as $index => $slug) {
            $ids[] = Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'group' => 'Coupon', 'module' => 'Coupons', 'action' => 'view', 'sort_order' => $index],
            )->id;
        }

        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'Staff',
            'username' => 'staff-' . uniqid(),
            'email' => uniqid() . '@example.com',
            'password' => 'secret-password',
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    /** Somebody who may do everything to a coupon. */
    protected function couponAdmin(): User
    {
        return $this->userWith([
            'coupons.view',
            'coupons.create',
            'coupons.update',
            'coupons.delete',
        ]);
    }
}
