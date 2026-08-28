<?php

namespace App\Services;

use App\Models\ShopOrder;
use App\Models\ShopOrderEvent;
use App\Models\ShopProduct;
use App\Models\ShopProductVariant;
use App\Support\Cart;
use App\Support\ShippingSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Turns a basket into an order.
 *
 * Every figure is worked out here from the database, never from the request: the
 * basket contributes quantities and the buyer contributes an address, and nothing
 * else they send is trusted with money.
 *
 * Stock is not touched at this point. It comes off when the payment is confirmed,
 * so a cash on delivery order nobody ever pays for cannot hold stock away from
 * somebody who would have.
 */
class ShopOrderWriter
{
    /**
     * @param  array<string, mixed>  $buyer  validated customer and address fields
     */
    public function place(array $buyer, string $paymentMethod, ?string $ip = null): ShopOrder
    {
        $lines = Cart::lines();

        if ($lines->isEmpty()) {
            throw new \RuntimeException('The basket is empty.');
        }

        $itemsTotal = round((float) $lines->sum('line_total'), 2);
        $shipping = ShippingSettings::quote($buyer['state'] ?? null, $itemsTotal);

        return DB::transaction(function () use ($lines, $buyer, $paymentMethod, $ip, $itemsTotal, $shipping) {
            $order = new ShopOrder([
                'reference' => ShopOrder::nextReference(),
                'status' => ShopOrder::STATUS_PENDING_PAYMENT,
                'payment_method' => $paymentMethod,

                'customer_name' => $buyer['customer_name'],
                'customer_email' => $buyer['customer_email'],
                'customer_phone' => $buyer['customer_phone'],
                'address_line_1' => $buyer['address_line_1'],
                'address_line_2' => $buyer['address_line_2'] ?? null,
                'postcode' => $buyer['postcode'],
                'city' => $buyer['city'],
                'state' => $buyer['state'],
                'country' => 'Malaysia',

                'items_total' => $itemsTotal,
                'shipping_total' => $shipping,
                'grand_total' => round($itemsTotal + $shipping, 2),
                'shipping_label' => $this->shippingLabel($buyer['state'] ?? null, $shipping),

                'ip_address' => $ip,
            ]);

            $order->save();

            foreach ($lines as $line) {
                /** @var ShopProduct $product */
                $product = $line['product'];
                /** @var ShopProductVariant|null $variant */
                $variant = $line['variant'];

                $order->items()->create([
                    'shop_product_id' => $product->id,
                    'shop_product_variant_id' => $variant?->id,

                    // Snapshots, so a later rename or reprice cannot rewrite this order.
                    'name' => $product->name,
                    'variant_label' => $variant?->label,
                    'sku' => $variant?->sku ?: $product->sku,
                    'unit_price' => $line['unit_price'],
                    'quantity' => $line['quantity'],
                    'line_total' => $line['line_total'],
                    'weight_grams' => $line['weight_grams'] ?: null,
                ]);
            }

            $this->record($order, ShopOrder::STATUS_PENDING_PAYMENT, sprintf(
                'Order placed, paying by %s.',
                ShopOrder::METHODS[$paymentMethod] ?? $paymentMethod,
            ));

            return $order;
        });
    }

    /**
     * Move an order on, writing the trail entry with it.
     *
     * Refuses a move the lifecycle does not allow, so a stale page or a crafted post
     * cannot send a delivered order back to pending.
     */
    public function moveTo(ShopOrder $order, string $status, ?string $note = null): bool
    {
        if (! $order->canMoveTo($status)) {
            return false;
        }

        DB::transaction(function () use ($order, $status, $note) {
            $wasPaid = $order->isPaid();

            $order->status = $status;

            if ($status === ShopOrder::STATUS_PAID && ! $wasPaid) {
                $order->paid_at = now();
            }

            if ($status === ShopOrder::STATUS_SHIPPED && $order->shipped_at === null) {
                $order->shipped_at = now();
            }

            if ($status === ShopOrder::STATUS_DELIVERED && $order->delivered_at === null) {
                $order->delivered_at = now();
            }

            $order->save();

            /*
             | Stock comes off exactly once, on the move into paid. Doing it at
             | checkout would let an unpaid cash on delivery order hold stock, and
             | doing it on every save would decrement twice.
             */
            if ($status === ShopOrder::STATUS_PAID && ! $wasPaid) {
                $this->takeStock($order);
            }

            $this->record($order, $status, $note);
        });

        return true;
    }

    /**
     * Add a line to the history without changing the status.
     */
    public function note(ShopOrder $order, string $note): void
    {
        $this->record($order, null, $note);
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * Move the ordered quantities into stock_taken.
     *
     * Written against the order lines rather than the basket, because by now the
     * basket may be gone and the lines are the record of what was sold.
     */
    private function takeStock(ShopOrder $order): void
    {
        foreach ($order->items as $item) {
            if ($item->shop_product_variant_id !== null) {
                ShopProductVariant::query()
                    ->whereKey($item->shop_product_variant_id)
                    ->increment('stock_taken', $item->quantity);

                continue;
            }

            if ($item->shop_product_id !== null) {
                ShopProduct::query()
                    ->whereKey($item->shop_product_id)
                    ->where('track_inventory', true)
                    ->increment('stock_taken', $item->quantity);
            }
        }
    }

    private function record(ShopOrder $order, ?string $status, ?string $note): void
    {
        $user = Auth::user();

        ShopOrderEvent::create([
            'shop_order_id' => $order->id,
            'status' => $status,
            'note' => $note,

            // Null for anything the system or the buyer did, which is most of the
            // early history: checkout runs with no session behind it.
            'user_id' => $user?->id,
            'actor_label' => $user?->logLabel(),
        ]);

        $order->unsetRelation('events');
    }

    /**
     * How the postage figure was arrived at, in words the buyer can check.
     */
    private function shippingLabel(?string $state, float $shipping): string
    {
        if ($shipping <= 0) {
            $threshold = ShippingSettings::freeShippingThreshold();

            return $threshold === null
                ? 'No delivery charge'
                : 'Free delivery, order over ' . \App\Support\PaymentFigures::money($threshold);
        }

        return ShippingSettings::isEastMalaysia($state)
            ? 'Flat rate, Sabah & Sarawak'
            : 'Flat rate, Peninsular Malaysia';
    }
}
