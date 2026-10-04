<?php

namespace Tests\Feature\Shop;

use App\Models\Role;
use App\Models\ShopOrder;
use App\Models\ShopProduct;
use App\Models\User;
use App\Support\Cart;
use App\Support\PaymentSettings;
use App\Support\ShopSettings;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fixtures shared by the shop payment tests.
 *
 * Abstract, so PHPUnit does not try to run it. The shop payment path needs a product,
 * a collection point, an order with lines, a configured gateway and an RSA keypair for
 * the webhook, and spelling all of that out eight times would mean eight chances for
 * one copy to drift and quietly stop asserting what its name claims.
 *
 * No factories: this project has none beyond UserFactory, so every row is an explicit
 * Model::create() with a uniqid() suffix, following tests/Feature/Registration.
 *
 * Every email address here ends in @example.com and nothing reaches a transport:
 * phpunit.xml sets MAIL_MAILER=array, and each test class that can send calls
 * Mail::fake() in setUp(). The action under test exists to email buyers, so that is
 * belt and braces rather than one or the other.
 */
abstract class ShopPaymentTestCase extends TestCase
{
    /** CHIP's "public key", generated per test run. Never a real credential. */
    protected string $webhookPublicKey = '';

    /** Its private half, used only to sign the bodies a test posts. */
    protected string $webhookPrivateKey = '';

    protected function setUp(): void
    {
        parent::setUp();

        // ShopSettings memoises reads for the request, and a test writes settings
        // after the container has booted.
        ShopSettings::flush();
    }

    /* ---------------------------------------------------------------------
     | Settings
     * ------------------------------------------------------------------ */

    /**
     * A shop that is open with CHIP configured and the webhook verifiable.
     */
    protected function gatewayShopSettings(): void
    {
        [$this->webhookPrivateKey, $this->webhookPublicKey] = $this->keypair();

        Setting::write('shop.enabled', '1', 'shop');

        Setting::write('integration.payments.provider', PaymentSettings::PROVIDER_CHIP, 'integration.payments');
        Setting::write('integration.payments.chip_brand_id', 'brand-' . uniqid(), 'integration.payments');
        Setting::write('integration.payments.chip_api_key', 'key-' . uniqid(), 'integration.payments');
        Setting::write('integration.payments.chip_webhook_public_key', $this->webhookPublicKey, 'integration.payments');
        Setting::write('integration.payments.currency', 'MYR', 'integration.payments');

        ShopSettings::flush();
    }

    /** Bank transfer switched on, with an account to pay into. */
    protected function enableBankTransfer(): void
    {
        Setting::write('integration.payments.bank_transfer_enabled', '1', 'integration.payments');
        Setting::write('integration.payments.bank_account_name', 'Smart Creative', 'integration.payments');
        Setting::write('integration.payments.bank_name', 'Maybank', 'integration.payments');
        Setting::write('integration.payments.bank_account_number', '512345678901', 'integration.payments');
    }

    protected function enableCod(): void
    {
        Setting::write('integration.payments.cod_enabled', '1', 'integration.payments');
    }

    protected function disableGateway(): void
    {
        Setting::write('integration.payments.provider', PaymentSettings::PROVIDER_NONE, 'integration.payments');
    }

    /* ---------------------------------------------------------------------
     | Rows
     * ------------------------------------------------------------------ */

    /**
     * A counter-collected product. The case this whole change exists for: offline
     * fulfilment, and a payment method the gateway can take.
     *
     * @param  array<int, string>  $methods
     * @param  array<string, mixed>  $overrides
     */
    protected function offlineProduct(array $methods = [ShopOrder::METHOD_GATEWAY], array $overrides = []): ShopProduct
    {
        return ShopProduct::create($overrides + [
            'slug' => 'jersey-' . uniqid(),
            'name' => 'Team Jersey',
            'sku' => 'SKU-' . strtoupper(uniqid()),
            'short_description' => 'Collected at the counter.',
            'description' => 'A jersey.',
            'price' => 25.00,
            'track_inventory' => true,
            'stock_quantity' => 50,
            'stock_taken' => 0,
            'weight_grams' => 300,
            'status' => ShopProduct::STATUS_ACTIVE,
            'payment_methods' => $methods,
            'fulfilment' => ShopProduct::FULFILMENT_OFFLINE,
            'collection_location' => 'Dewan Serbaguna, Shah Alam',
            'collection_at' => now()->addWeek()->setTime(10, 0),
        ]);
    }

    /**
     * An order as the checkout would have written it, with one line.
     *
     * The six stuck orders are exactly this shape: offline, gateway,
     * pending_payment, no payment_reference, no paid_at.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function order(?ShopProduct $product = null, array $overrides = []): ShopOrder
    {
        $product ??= $this->offlineProduct();

        $order = ShopOrder::create($overrides + [
            'reference' => ShopOrder::nextReference(),
            'status' => ShopOrder::STATUS_PENDING_PAYMENT,
            'fulfilment' => ShopOrder::FULFILMENT_OFFLINE,
            'payment_method' => ShopOrder::METHOD_GATEWAY,

            'customer_name' => 'Aminah Yusof',
            'customer_email' => 'buyer-' . uniqid() . '@example.com',
            'customer_phone' => '0123456789',
            'identity_card' => '900101071234',

            'address_line_1' => '1 Jalan Satu',
            'postcode' => '40000',
            'city' => 'Shah Alam',
            'state' => 'Selangor',
            'country' => 'Malaysia',

            'items_total' => 25.00,
            'shipping_total' => 0,
            'grand_total' => 25.00,
            'shipping_label' => 'Collected at the counter, nothing posted',

            'collection_label' => 'Collection point',
            'collection_location' => 'Dewan Serbaguna, Shah Alam',
            'collection_at' => now()->addWeek()->setTime(10, 0),
        ]);

        $order->items()->create([
            'shop_product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'unit_price' => 25.00,
            'quantity' => 1,
            'line_total' => 25.00,
            'weight_grams' => 300,
        ]);

        return $order->fresh('items');
    }

    /**
     * A user holding exactly the named permission slugs, and nothing else.
     *
     * A real role with real pivot rows rather than the super-admin shortcut, because
     * half of what these tests check is that the new control is invisible without its
     * permission — and super-admin short-circuits hasPermission() to true, which would
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

        foreach ($permissions as $index => $slug) {
            $ids[] = \App\Models\Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'group' => 'Shop', 'module' => 'Orders', 'action' => 'view', 'sort_order' => $index],
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

    /* ---------------------------------------------------------------------
     | The basket
     * ------------------------------------------------------------------ */

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
    protected function checkoutFields(string $method): array
    {
        return [
            'customer_name' => 'Aminah Yusof',
            'customer_email' => 'buyer-' . uniqid() . '@example.com',
            'customer_phone' => '0123456789',
            'identity_card' => '900101071234',
            'address_line_1' => '1 Jalan Satu',
            'postcode' => '40000',
            'city' => 'Shah Alam',
            'state' => 'Selangor',
            'payment_method' => $method,
        ];
    }

    /* ---------------------------------------------------------------------
     | CHIP
     * ------------------------------------------------------------------ */

    /**
     * CHIP accepts a purchase and hands back a checkout URL.
     */
    protected function fakeChipPurchase(string $purchaseId = 'pur_test_1'): void
    {
        Http::fake([
            'gate.chip-in.asia/api/v1/purchases/' => Http::response([
                'id' => $purchaseId,
                'checkout_url' => 'https://gate.chip-in.asia/p/' . $purchaseId,
            ]),
        ]);
    }

    /**
     * A purchase payload shaped the way the amount rule reads it.
     *
     * purchase.total is what proves the unit: collectedCents() refuses to believe
     * payment.amount unless the purchase's own total matches the figure we charged.
     *
     * @return array<string, mixed>
     */
    protected function purchasePayload(
        ShopOrder $order,
        string $purchaseId,
        string $event = 'purchase.paid',
        string $status = 'paid',
        ?int $collectedCents = null,
    ): array {
        $expected = (int) round((float) $order->grand_total * 100);

        return [
            'event_type' => $event,
            'id' => $purchaseId,
            'reference' => $order->reference,
            'status' => $status,
            'purchase' => ['total' => $expected, 'currency' => 'MYR'],
            'payment' => ['amount' => $collectedCents ?? $expected, 'currency' => 'MYR'],
        ];
    }

    /**
     * POST a body to the webhook, signed over the exact bytes sent.
     *
     * json_encode once and post that same string: isTrusted() verifies against the raw
     * body, so signing a re-encoded array would break the signature, which is the
     * whole point of reading the raw body.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function postWebhook(array $payload, ?string $signature = null)
    {
        $body = json_encode($payload);

        if ($signature === null) {
            openssl_sign($body, $raw, $this->webhookPrivateKey, OPENSSL_ALGO_SHA256);
            $signature = base64_encode($raw);
        }

        return $this->call(
            'POST',
            route('payments.chip.webhook'),
            [],
            [],
            [],
            ['HTTP_X_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $body,
        );
    }

    /**
     * A throwaway RSA keypair, generated per call.
     *
     * Generated rather than hardcoded: a private key committed to a repository is a
     * bad habit even when it is worthless, and this one only ever signs a JSON body
     * inside an in-memory test database.
     *
     * The config file is written here because openssl_pkey_new() reads openssl.cnf and
     * will not generate a key without one. Plenty of PHP builds — the Windows one this
     * was written on among them — ship no openssl.cnf and no OPENSSL_CONF, so key
     * generation fails with "Cannot get key from parameter 1" and every webhook test
     * dies in setUp(). A two-section stub is all the generator needs. Signing and
     * verifying do not read it at all, which is why only this method cares.
     *
     * @return array{0: string, 1: string}  private PEM, public PEM
     */
    private function keypair(): array
    {
        $config = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smartcreative-openssl-test.cnf';

        if (! is_file($config)) {
            file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n");
        }

        $options = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'config' => $config,
        ];

        $key = openssl_pkey_new($options);

        $this->assertNotFalse($key, 'Could not generate a test RSA keypair.');

        openssl_pkey_export($key, $private, null, $options);

        return [$private, openssl_pkey_get_details($key)['key']];
    }
}
