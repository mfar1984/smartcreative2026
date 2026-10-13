<?php

namespace Tests\Feature\Shop;

use App\Models\Event;
use App\Models\ShopOrder;
use App\Models\ShopProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Event and product filter dropdowns on the Offline orders tab.
 *
 * Covers:
 *  - Filter by event returns only orders for that collection event.
 *  - Filter by product returns only orders containing that product.
 *  - Both filters together narrow the set correctly.
 *  - Export CSV carries both filters.
 *  - Neither dropdown appears on the Online tab.
 *  - Junk (non-integer) event_id / product_id in the query string is silently dropped.
 */
class OrderFilterEventProductTest extends ShopPaymentTestCase
{
    use RefreshDatabase;

    private const CAN_VIEW = ['admin.access', 'shop.orders.view'];

    /* ------------------------------------------------------------------
     | Helpers
     * ----------------------------------------------------------------- */

    /** A minimal event row sufficient for a FK reference. */
    private function event(string $title = 'Test Event'): Event
    {
        return Event::create([
            'slug' => 'event-' . uniqid(),
            'title' => $title,
            'category' => 'Community',
            'starts_at' => now()->addMonth()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_INDIVIDUAL,
            'fee' => 0,
            'seats_total' => 100,
            'min_players' => 1,
            'max_players' => 10,
        ]);
    }

    /** Offline order tied to a collection event. */
    private function orderForEvent(Event $event, ?ShopProduct $product = null): ShopOrder
    {
        $order = $this->order($product, ['collection_event_id' => $event->id]);

        return $order->fresh();
    }

    /** Offline order with a specific product on it. */
    private function orderWithProduct(ShopProduct $product): ShopOrder
    {
        return $this->order($product);
    }

    private function getOrders(array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->userWith(self::CAN_VIEW))
            ->get(route('admin.shop.orders', $query));
    }

    private function exportCsv(array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->userWith(self::CAN_VIEW))
            ->get(route('admin.shop.orders.export', $query));
    }

    /* ------------------------------------------------------------------
     | Filter by event
     * ----------------------------------------------------------------- */

    public function test_filtering_by_event_returns_only_orders_for_that_event(): void
    {
        $eventA = $this->event('Event A');
        $eventB = $this->event('Event B');

        $orderA = $this->orderForEvent($eventA);
        $orderB = $this->orderForEvent($eventB);
        $noEvent = $this->order(); // no collection_event_id

        $response = $this->getOrders([
            'tab' => ShopOrder::FULFILMENT_OFFLINE,
            'event_id' => $eventA->id,
        ]);

        $response->assertOk();
        $response->assertSee($orderA->reference);
        $response->assertDontSee($orderB->reference);
        $response->assertDontSee($noEvent->reference);
    }

    /* ------------------------------------------------------------------
     | Filter by product
     * ----------------------------------------------------------------- */

    public function test_filtering_by_product_returns_only_orders_containing_that_product(): void
    {
        $productA = $this->offlineProduct();
        $productB = $this->offlineProduct();

        $orderA = $this->orderWithProduct($productA);
        $orderB = $this->orderWithProduct($productB);

        $response = $this->getOrders([
            'tab' => ShopOrder::FULFILMENT_OFFLINE,
            'product_id' => $productA->id,
        ]);

        $response->assertOk();
        $response->assertSee($orderA->reference);
        $response->assertDontSee($orderB->reference);
    }

    /* ------------------------------------------------------------------
     | Both filters together
     * ----------------------------------------------------------------- */

    public function test_both_filters_together_narrow_correctly(): void
    {
        $event = $this->event();
        $productA = $this->offlineProduct();
        $productB = $this->offlineProduct();

        // Matches both filters.
        $match = $this->orderForEvent($event, $productA);

        // Right event, wrong product.
        $wrongProduct = $this->orderForEvent($event, $productB);

        // Right product, no event.
        $wrongEvent = $this->orderWithProduct($productA);

        $response = $this->getOrders([
            'tab' => ShopOrder::FULFILMENT_OFFLINE,
            'event_id' => $event->id,
            'product_id' => $productA->id,
        ]);

        $response->assertOk();
        $response->assertSee($match->reference);
        $response->assertDontSee($wrongProduct->reference);
        $response->assertDontSee($wrongEvent->reference);
    }

    /* ------------------------------------------------------------------
     | Export CSV carries both filters
     * ----------------------------------------------------------------- */

    public function test_export_csv_carries_event_filter(): void
    {
        $eventA = $this->event('Event A');
        $eventB = $this->event('Event B');

        $orderA = $this->orderForEvent($eventA);
        $orderB = $this->orderForEvent($eventB);

        $response = $this->exportCsv([
            'tab' => ShopOrder::FULFILMENT_OFFLINE,
            'event_id' => $eventA->id,
        ]);

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $content = $response->streamedContent();
        $this->assertStringContainsString($orderA->reference, $content);
        $this->assertStringNotContainsString($orderB->reference, $content);
    }

    public function test_export_csv_carries_product_filter(): void
    {
        $productA = $this->offlineProduct();
        $productB = $this->offlineProduct();

        $orderA = $this->orderWithProduct($productA);
        $orderB = $this->orderWithProduct($productB);

        $response = $this->exportCsv([
            'tab' => ShopOrder::FULFILMENT_OFFLINE,
            'product_id' => $productA->id,
        ]);

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString($orderA->reference, $content);
        $this->assertStringNotContainsString($orderB->reference, $content);
    }

    public function test_export_csv_carries_both_filters(): void
    {
        $event = $this->event();
        $productA = $this->offlineProduct();
        $productB = $this->offlineProduct();

        $match = $this->orderForEvent($event, $productA);
        $wrongProduct = $this->orderForEvent($event, $productB);
        $wrongEvent = $this->orderWithProduct($productA);

        $response = $this->exportCsv([
            'tab' => ShopOrder::FULFILMENT_OFFLINE,
            'event_id' => $event->id,
            'product_id' => $productA->id,
        ]);

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString($match->reference, $content);
        $this->assertStringNotContainsString($wrongProduct->reference, $content);
        $this->assertStringNotContainsString($wrongEvent->reference, $content);
    }

    /* ------------------------------------------------------------------
     | Neither dropdown appears on the Online tab
     * ----------------------------------------------------------------- */

    public function test_event_and_product_dropdowns_do_not_appear_on_online_tab(): void
    {
        $event = $this->event();

        // An online order so the tab is not empty.
        ShopOrder::create([
            'reference' => ShopOrder::nextReference(),
            'status' => ShopOrder::STATUS_PENDING_PAYMENT,
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
        ]);

        $response = $this->getOrders(['tab' => ShopOrder::FULFILMENT_ONLINE]);

        $response->assertOk();

        // The event dropdown option text should not appear on the online tab.
        $response->assertDontSee('name="event_id"', false);
        $response->assertDontSee('name="product_id"', false);
    }

    public function test_event_and_product_dropdowns_appear_on_offline_tab(): void
    {
        $event = $this->event('My Event');

        $this->orderForEvent($event);

        // An active product must exist for the dropdown to render.
        $this->offlineProduct();

        $response = $this->getOrders(['tab' => ShopOrder::FULFILMENT_OFFLINE]);

        $response->assertOk();
        $response->assertSee('name="event_id"', false);
        $response->assertSee('name="product_id"', false);
    }

    /* ------------------------------------------------------------------
     | Junk values are silently dropped
     * ----------------------------------------------------------------- */

    public function test_a_non_integer_event_id_is_silently_dropped(): void
    {
        $order = $this->order();

        // 'banana' is not a valid id: the page must render normally showing all orders.
        $response = $this->getOrders([
            'tab' => ShopOrder::FULFILMENT_OFFLINE,
            'event_id' => 'banana',
        ]);

        $response->assertOk();
        $response->assertSee($order->reference);
    }

    public function test_a_zero_event_id_is_silently_dropped(): void
    {
        $order = $this->order();

        $response = $this->getOrders([
            'tab' => ShopOrder::FULFILMENT_OFFLINE,
            'event_id' => '0',
        ]);

        $response->assertOk();
        $response->assertSee($order->reference);
    }

    public function test_a_non_integer_product_id_is_silently_dropped(): void
    {
        $order = $this->order();

        $response = $this->getOrders([
            'tab' => ShopOrder::FULFILMENT_OFFLINE,
            'product_id' => 'not-a-number',
        ]);

        $response->assertOk();
        $response->assertSee($order->reference);
    }
}
