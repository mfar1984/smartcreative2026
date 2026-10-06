<?php

namespace Tests\Feature\Shop;

use App\Models\CollectionHandover;
use App\Models\ShopOrder;
use App\Services\ShopOrderWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Export CSV for the offline (and online) orders tab.
 *
 * Conventions: PHPUnit via phpunit.xml, RefreshDatabase, rows via Model::create()
 * — no factories beyond UserFactory, per project convention (see ShopPaymentTestCase).
 *
 * Checks:
 *  - Correct Content-Type and Content-Disposition on a successful download.
 *  - A header row plus one data row per order in the filtered set.
 *  - Orders outside the current filters are excluded.
 *  - Refused (403) without the shop.orders.view permission.
 *  - Refused (405) on a POST request.
 */
class OrderExportCsvTest extends ShopPaymentTestCase
{
    use RefreshDatabase;

    private const CAN_VIEW = ['admin.access', 'shop.orders.view'];

    /* ------------------------------------------------------------------
     | Helpers
     * ----------------------------------------------------------------- */

    private function export(array $query = [], array $permissions = self::CAN_VIEW)
    {
        return $this->actingAs($this->userWith($permissions))
            ->get(route('admin.shop.orders.export', $query));
    }

    /** A paid offline order ready for collection. */
    private function paidOfflineOrder(array $overrides = []): ShopOrder
    {
        $order = $this->order(null, array_merge([
            'status' => ShopOrder::STATUS_PAID,
            'paid_at' => now(),
        ], $overrides));

        return $order->fresh();
    }

    /* ------------------------------------------------------------------
     | Content-Type and Content-Disposition
     * ----------------------------------------------------------------- */

    public function test_it_returns_csv_content_type(): void
    {
        $this->paidOfflineOrder();

        $response = $this->export(['tab' => ShopOrder::FULFILMENT_OFFLINE]);

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_it_returns_content_disposition_attachment(): void
    {
        $this->paidOfflineOrder();

        $response = $this->export(['tab' => ShopOrder::FULFILMENT_OFFLINE]);

        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('orders-offline-', $disposition);
        $this->assertStringContainsString('.csv', $disposition);
    }

    /* ------------------------------------------------------------------
     | Header row
     * ----------------------------------------------------------------- */

    public function test_it_includes_the_header_row(): void
    {
        $response = $this->export(['tab' => ShopOrder::FULFILMENT_OFFLINE]);

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString('Reference', $content);
        $this->assertStringContainsString('Customer Name', $content);
        $this->assertStringContainsString('Identity Card', $content);
        $this->assertStringContainsString('Hand Over', $content);
        $this->assertStringContainsString('Collector IC', $content);
    }

    /* ------------------------------------------------------------------
     | One data row per matching order
     * ----------------------------------------------------------------- */

    public function test_it_includes_a_row_for_each_matching_order(): void
    {
        $order1 = $this->paidOfflineOrder();
        $order2 = $this->paidOfflineOrder();

        $response = $this->export(['tab' => ShopOrder::FULFILMENT_OFFLINE]);

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString($order1->reference, $content);
        $this->assertStringContainsString($order2->reference, $content);
    }

    public function test_it_includes_one_data_row_per_order(): void
    {
        $order1 = $this->paidOfflineOrder();
        $order2 = $this->paidOfflineOrder();

        $response = $this->export(['tab' => ShopOrder::FULFILMENT_OFFLINE]);

        $response->assertOk();
        $content = $response->streamedContent();

        // Skip BOM + header, count data rows by counting references.
        $this->assertSame(
            2,
            substr_count($content, $order1->reference) + substr_count($content, $order2->reference),
        );
    }

    /* ------------------------------------------------------------------
     | Filter exclusion
     * ----------------------------------------------------------------- */

    public function test_it_excludes_orders_from_the_other_tab(): void
    {
        // Offline order
        $offline = $this->paidOfflineOrder();

        // Online order
        $online = ShopOrder::create([
            'reference' => ShopOrder::nextReference(),
            'status' => ShopOrder::STATUS_PAID,
            'fulfilment' => ShopOrder::FULFILMENT_ONLINE,
            'payment_method' => ShopOrder::METHOD_GATEWAY,
            'customer_name' => 'Online Buyer',
            'customer_email' => 'online-' . uniqid() . '@example.com',
            'customer_phone' => '0198765432',
            'identity_card' => '850202021234',
            'address_line_1' => '2 Jalan Dua',
            'postcode' => '50000',
            'city' => 'Kuala Lumpur',
            'state' => 'WP Kuala Lumpur',
            'country' => 'Malaysia',
            'items_total' => 30.00,
            'shipping_total' => 5.00,
            'grand_total' => 35.00,
            'shipping_label' => 'Standard post',
            'paid_at' => now(),
        ]);

        $response = $this->export(['tab' => ShopOrder::FULFILMENT_OFFLINE]);

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString($offline->reference, $content);
        $this->assertStringNotContainsString($online->reference, $content);
    }

    public function test_it_respects_the_status_filter(): void
    {
        $paid = $this->paidOfflineOrder();
        $pending = $this->order(); // STATUS_PENDING_PAYMENT by default

        $response = $this->export([
            'tab' => ShopOrder::FULFILMENT_OFFLINE,
            'status' => ShopOrder::STATUS_PAID,
        ]);

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString($paid->reference, $content);
        $this->assertStringNotContainsString($pending->reference, $content);
    }

    public function test_it_respects_the_search_filter(): void
    {
        $matched = ShopOrder::create([
            'reference' => ShopOrder::nextReference(),
            'status' => ShopOrder::STATUS_PENDING_PAYMENT,
            'fulfilment' => ShopOrder::FULFILMENT_OFFLINE,
            'payment_method' => ShopOrder::METHOD_GATEWAY,
            'customer_name' => 'Zaharah Othman',
            'customer_email' => 'zaharah-' . uniqid() . '@example.com',
            'customer_phone' => '0111234567',
            'identity_card' => '900101071111',
            'address_line_1' => '1 Jalan Test',
            'postcode' => '40000',
            'city' => 'Shah Alam',
            'state' => 'Selangor',
            'country' => 'Malaysia',
            'items_total' => 25.00,
            'shipping_total' => 0,
            'grand_total' => 25.00,
            'shipping_label' => 'Collected',
        ]);

        $other = $this->order();

        $response = $this->export([
            'tab' => ShopOrder::FULFILMENT_OFFLINE,
            'q' => 'Zaharah',
        ]);

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString($matched->reference, $content);
        $this->assertStringNotContainsString($other->reference, $content);
    }

    /* ------------------------------------------------------------------
     | Hand Over column and collector details
     * ----------------------------------------------------------------- */

    public function test_it_shows_collected_hand_over_status(): void
    {
        $order = $this->paidOfflineOrder();
        app(ShopOrderWriter::class)->moveTo($order, ShopOrder::STATUS_DELIVERED, 'Handed over.');

        $response = $this->export(['tab' => ShopOrder::FULFILMENT_OFFLINE]);

        $response->assertOk();
        $this->assertStringContainsString('Collected', $response->streamedContent());
    }

    public function test_it_shows_awaiting_collection_status_for_paid_orders(): void
    {
        $order = $this->paidOfflineOrder();

        $response = $this->export(['tab' => ShopOrder::FULFILMENT_OFFLINE]);

        $response->assertOk();
        $this->assertStringContainsString('Awaiting collection', $response->streamedContent());
    }

    public function test_it_includes_collector_ic_for_third_party_handover(): void
    {
        $order = $this->paidOfflineOrder();
        app(ShopOrderWriter::class)->moveTo($order, ShopOrder::STATUS_DELIVERED, 'Collected by brother.');

        CollectionHandover::create([
            'collectable_type' => $order->getMorphClass(),
            'collectable_id' => $order->getKey(),
            'collector_kind' => CollectionHandover::KIND_OTHER,
            'collector_name' => 'Ahmad Farid',
            'collector_ic' => '901212071234',
            'collector_phone' => '0129876543',
            'verified_at' => null,
            'collected_at' => now(),
        ]);

        $response = $this->export(['tab' => ShopOrder::FULFILMENT_OFFLINE]);

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString('901212071234', $content);
        $this->assertStringContainsString('Ahmad Farid', $content);
    }

    /* ------------------------------------------------------------------
     | Permission and method guards
     * ----------------------------------------------------------------- */

    public function test_it_is_refused_without_the_view_permission(): void
    {
        $this->paidOfflineOrder();

        $response = $this->export(
            ['tab' => ShopOrder::FULFILMENT_OFFLINE],
            ['admin.access'], // no shop.orders.view
        );

        $response->assertForbidden();
    }

    public function test_it_is_refused_on_post(): void
    {
        $this->actingAs($this->userWith(self::CAN_VIEW))
            ->post(route('admin.shop.orders.export', ['tab' => ShopOrder::FULFILMENT_OFFLINE]))
            ->assertStatus(405);
    }

    /* ------------------------------------------------------------------
     | Items & Sizes column
     * ----------------------------------------------------------------- */

    /**
     * Create an order whose single item carries the given variant label.
     */
    private function orderWithItem(string $itemName, ?string $variantLabel): ShopOrder
    {
        $order = $this->paidOfflineOrder();

        // Replace the item created by paidOfflineOrder() → order()
        $order->items()->delete();
        $order->items()->create([
            'name' => $itemName,
            'variant_label' => $variantLabel,
            'unit_price' => 25.00,
            'quantity' => 1,
            'line_total' => 25.00,
        ]);

        return $order->fresh(['items']);
    }

    public function test_csv_includes_variant_label_for_item_with_variant(): void
    {
        $order = $this->orderWithItem('HSN Event Tee', 'L');

        $response = $this->export(['tab' => ShopOrder::FULFILMENT_OFFLINE]);

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString($order->reference, $content);
        // The cell must contain the name and the size in parentheses.
        $this->assertStringContainsString('HSN Event Tee (L)', $content);
    }

    public function test_csv_includes_both_variants_for_order_with_two_items(): void
    {
        $order = $this->paidOfflineOrder();

        $order->items()->delete();
        $order->items()->create([
            'name' => 'HSN Event Tee',
            'variant_label' => 'XL',
            'unit_price' => 25.00,
            'quantity' => 1,
            'line_total' => 25.00,
        ]);
        $order->items()->create([
            'name' => 'Tote Bag',
            'variant_label' => 'Blue',
            'unit_price' => 10.00,
            'quantity' => 1,
            'line_total' => 10.00,
        ]);

        $response = $this->export(['tab' => ShopOrder::FULFILMENT_OFFLINE]);

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString($order->reference, $content);
        $this->assertStringContainsString('HSN Event Tee (XL)', $content);
        $this->assertStringContainsString('Tote Bag (Blue)', $content);
    }

    public function test_csv_shows_item_name_alone_when_no_variant(): void
    {
        $order = $this->orderWithItem('Event Programme', null);

        $response = $this->export(['tab' => ShopOrder::FULFILMENT_OFFLINE]);

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString($order->reference, $content);
        $this->assertStringContainsString('Event Programme', $content);
        // Must not have empty parentheses next to the name.
        $this->assertStringNotContainsString('Event Programme ()', $content);
    }
}
