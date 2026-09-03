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
use Illuminate\Support\Facades\Storage;

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
    public function __construct(private ShopOrderNotifier $notifier)
    {
    }

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

        /*
         | A collected order is never posted, so postage is not quoted for it at all
         | rather than quoted and then zeroed. Nothing about the shipping settings is
         | consulted: no flat rate, no free-delivery threshold, no banding by state.
         */
        $isOffline = Cart::isOffline();
        $shipping = $isOffline ? 0.0 : ShippingSettings::quote($buyer['state'] ?? null, $itemsTotal);
        $collection = $isOffline ? Cart::collectionPoint() : null;

        return DB::transaction(function () use ($lines, $buyer, $paymentMethod, $ip, $itemsTotal, $shipping, $isOffline, $collection) {
            $order = new ShopOrder([
                'reference' => ShopOrder::nextReference(),
                'status' => ShopOrder::STATUS_PENDING_PAYMENT,
                'fulfilment' => $isOffline ? ShopOrder::FULFILMENT_OFFLINE : ShopOrder::FULFILMENT_ONLINE,
                'payment_method' => $paymentMethod,

                'customer_name' => $buyer['customer_name'],
                'customer_email' => $buyer['customer_email'],
                'customer_phone' => $buyer['customer_phone'],

                // Only held for a collected order, where somebody checks it. Storing an
                // identity number on a posted parcel would be collecting it for nothing.
                'identity_card' => $isOffline ? trim((string) ($buyer['identity_card'] ?? '')) : null,

                'address_line_1' => $buyer['address_line_1'],
                'address_line_2' => $buyer['address_line_2'] ?? null,
                'postcode' => $buyer['postcode'],
                'city' => $buyer['city'],
                'state' => $buyer['state'],
                'country' => 'Malaysia',

                'items_total' => $itemsTotal,
                'shipping_total' => $shipping,
                'grand_total' => round($itemsTotal + $shipping, 2),
                'shipping_label' => $isOffline
                    ? 'Collected at the counter, nothing posted'
                    : $this->shippingLabel($buyer['state'] ?? null, $shipping),

                /*
                 | Snapshotted, not read back through the product. The buyer was told a
                 | place and a time; if the event later moves, or is renamed, or is
                 | deleted, this order still says what was actually promised.
                 */
                'collection_event_id' => $collection['event_id'] ?? null,
                'collection_label' => $collection['label'] ?? null,
                'collection_location' => $collection['location'] ?? null,
                'collection_at' => $collection['at'] ?? null,

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
                'Order placed, paying by %s. %s',
                ShopOrder::METHODS[$paymentMethod] ?? $paymentMethod,
                $isOffline
                    ? 'To be collected at ' . ($order->collectionSummary() ?: 'the counter') . '.'
                    : 'To be posted.',
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

        $becamePaid = false;

        DB::transaction(function () use ($order, $status, $note, &$becamePaid) {
            $wasPaid = $order->isPaid();
            $becamePaid = $status === ShopOrder::STATUS_PAID && ! $wasPaid;

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

        /*
         | After the transaction, never inside it.
         |
         | A mail server can be slow or unreachable, and holding a database transaction
         | open across a network call to somebody else's machine is how row locks turn
         | into a stalled checkout. It also means a send that fails cannot roll back a
         | payment that really happened.
         |
         | This is the only place an order becomes paid, whichever route got it there,
         | which is why the collection email is triggered here rather than in each
         | caller.
         */
        if ($becamePaid) {
            $this->notifier->collectionReady($order);
        }

        return true;
    }

    /**
     * Add a line to the history without changing the status.
     */
    public function note(ShopOrder $order, string $note): void
    {
        $this->record($order, null, $note);
    }

    /**
     * Attach the buyer's proof of a bank transfer.
     *
     * Evidence for a decision, never the decision. Uploading a receipt does not mark
     * anything paid: somebody still has to look at the account and say the money
     * arrived, which is the whole reason a manual transfer is manual.
     *
     * A replacement deletes the file it replaces. Buyers do upload the wrong photo,
     * and keeping every attempt would leave the disk holding pictures nobody will
     * ever look at again.
     */
    public function attachPaymentReceipt(ShopOrder $order, string $path, ?string $note = null): void
    {
        $previous = $order->payment_receipt_path;

        $order->payment_receipt_path = $path;
        $order->payment_receipt_uploaded_at = now();
        $order->save();

        if (filled($previous) && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        $this->record($order, null, $note ?? ($previous === null
            ? 'Buyer uploaded a payment receipt. Still needs checking against the account.'
            : 'Buyer replaced the payment receipt. Still needs checking against the account.'));
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
