<?php

namespace App\Services\Payment;

use App\Models\ShopOrder;

/**
 * Open a CHIP checkout for a shop order, or hand back the one already open.
 *
 * Both callers that need one — the public checkout redirect and the signed pay
 * route — go through here, so "open or reuse" is answered in one place.
 */
class ShopCheckoutStarter
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly ShopOrderPaymentUpdater $updater,
        private readonly ShopOrderChargeBuilder $charges,
    ) {
    }

    /**
     * The URL to send the buyer to.
     *
     * @throws PaymentGatewayException
     */
    public function start(ShopOrder $order, CheckoutUrls $urls): string
    {
        /*
         | Send an impatient buyer back to the checkout they already have, rather than
         | opening a second one. Pressing Pay twice is how a registration once ended up
         | pointing at one purchase while a different one held the money.
         */
        if ($reusable = $this->reusableCheckout($order)) {
            return $reusable;
        }

        $gateway = $this->gateways->active();

        $session = $gateway->createCharge($this->charges->build($order), $urls);

        // Recorded before the redirect, so the webhook can find this order by the
        // gateway's id whatever happens next.
        $this->updater->markPending(
            $order,
            $session->reference,
            $gateway->label(),
            $session->checkoutUrl,
        );

        return $session->checkoutUrl;
    }

    /**
     * The checkout URL of an attempt that is still open, or null.
     *
     * Asks the gateway rather than trusting the stored status, because the stored one
     * is only as fresh as the last webhook that got through.
     *
     * Any problem reaching the gateway returns null, so the worst case is the old
     * behaviour of opening a new checkout rather than a buyer stuck at an error.
     */
    private function reusableCheckout(ShopOrder $order): ?string
    {
        $latest = $order->checkouts()->first();

        if ($latest === null || blank($latest->checkout_url)) {
            return null;
        }

        try {
            $payment = $this->gateways->active()->fetchPayment($latest->purchase_id);
        } catch (PaymentGatewayException) {
            return null;
        }

        if ($payment === null) {
            return null;
        }

        $status = $payment['status'] ?? null;

        // The states where the buyer has somewhere to go back to. Anything settled,
        // failed or expired is finished with.
        $open = ['created', 'viewed', 'pending_execute', 'pending_charge'];

        return is_string($status) && in_array($status, $open, true)
            ? $latest->checkout_url
            : null;
    }
}
