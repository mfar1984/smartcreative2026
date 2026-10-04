<?php

namespace App\Services\Payment;

use App\Models\ShopOrder;
use App\Models\ShopOrderItem;

/**
 * The one place a ShopOrder becomes a GatewayCharge.
 *
 * Every figure is read from the database. Nothing a request, a form, a session or a
 * gateway redirect carries contributes to the amount, which is the whole point:
 * a payment link that could be tampered into charging a cent is not a payment link.
 *
 * A separate class so it is testable with no HTTP and no container.
 */
class ShopOrderChargeBuilder
{
    /**
     * @throws PaymentGatewayException
     */
    public function build(ShopOrder $order): GatewayCharge
    {
        $order->loadMissing('items');

        $products = [];

        foreach ($order->items as $item) {
            /** @var ShopOrderItem $item */
            $products[] = [
                // label() already folds in the variant, so the CHIP receipt
                // itemises what was bought rather than showing one lump sum.
                'name' => $this->trim($item->label()),
                'price' => $this->cents((float) $item->unit_price),
                // A string, matching the official SDK, whose Product model
                // declares it as a string and casts on the way in.
                'quantity' => (string) (int) $item->quantity,
            ];
        }

        if ((float) $order->shipping_total > 0) {
            $products[] = [
                'name' => $this->trim($order->shipping_label ?: 'Delivery'),
                'price' => $this->cents((float) $order->shipping_total),
                'quantity' => '1',
            ];
        }

        if ($products === []) {
            throw new PaymentGatewayException(
                'Nothing to charge on ' . $order->reference . '.',
                'There is nothing to pay on this order.',
            );
        }

        $amountCents = $this->cents((float) $order->grand_total);

        if ($amountCents <= 0) {
            throw new PaymentGatewayException(
                'Nothing to charge on ' . $order->reference . ': the grand total is not positive.',
                'There is nothing to pay on this order.',
            );
        }

        /*
         | Caught here as well as inside chargePayload(), so a figures mismatch is
         | refused twice before any HTTP call and a buyer never reaches a CHIP page
         | quoting an amount the order does not say.
         */
        $lineTotal = array_sum(array_map(
            fn (array $product) => $product['price'] * (int) $product['quantity'],
            $products,
        ));

        if ($lineTotal !== $amountCents) {
            throw new PaymentGatewayException(sprintf(
                'Lines on %s total %d cents but its grand total is %d cents.',
                $order->reference,
                $lineTotal,
                $amountCents,
            ), 'There is nothing we can charge for this order right now. Please contact us.');
        }

        return new GatewayCharge(
            reference: $order->reference,
            amountCents: $amountCents,
            products: $products,
            client: $this->client($order),
        );
    }

    /**
     * @return array<string, string>
     */
    private function client(ShopOrder $order): array
    {
        if (blank($order->customer_email)) {
            throw new PaymentGatewayException(
                'No buyer email on ' . $order->reference . '.',
                'We need an email address to raise the payment. Please contact us.',
            );
        }

        $client = ['email' => (string) $order->customer_email];

        if (filled($order->customer_name)) {
            $client['full_name'] = $this->trim($order->customer_name, 128);
        }

        if (filled($order->customer_phone)) {
            $client['phone'] = $this->trim($order->customer_phone, 32);
        }

        return $client;
    }

    /**
     * Ringgit to cents.
     *
     * Rounded before casting, because (int) truncates and 45.00 can arrive as
     * 44.999999 from a float multiplication.
     */
    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function trim(string $value, int $limit = 256): string
    {
        return mb_substr(trim($value), 0, $limit);
    }
}
