<?php

namespace App\Services\Payment;

use App\Models\ShopOrder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Open a CHIP checkout for a shop order, hand back the one already open, or say that
 * an attempt is in flight at a bank and nothing should be opened at all.
 *
 * Both callers that need one — the public checkout redirect and the signed pay
 * route — go through here, so that choice is made in one place.
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
     * The URL to send the buyer to, or NULL when an attempt is already in flight at a
     * bank.
     *
     * Null is not a failure and it is not "no gateway": it means the buyer has a live
     * attempt that CHIP will not take a second go at, so a second purchase must not
     * be opened and the wedged URL must not be handed back. The caller shows
     * OpenCheckout::holdingMessage() instead.
     *
     * @throws PaymentGatewayException
     */
    public function start(ShopOrder $order, CheckoutUrls $urls): ?string
    {
        $open = $this->openCheckout($order);

        /*
         | Send an impatient buyer back to the checkout they already have, rather than
         | opening a second one. Pressing Pay twice is how a registration once ended up
         | pointing at one purchase while a different one held the money.
         */
        if ($open->mayReuse()) {
            return $open->checkoutUrl;
        }

        if ($open->isInProgress()) {
            return null;
        }

        $gateway = $this->gateways->active();

        $session = $gateway->createCharge($this->charges->build($order), $urls);

        /*
         | Recorded before the redirect, so the webhook can find this order by the
         | gateway's id whatever happens next — but never at the cost of the payment.
         |
         | The purchase exists at CHIP by the time this runs. Everything in
         | markPending() is bookkeeping: the attempt row, the reference, a trail note,
         | an activity line. Unwrapped, one failed write in there took the whole
         | response with it and the buyer got a server error instead of the gateway,
         | while a good checkout sat waiting. Logged as an error, with the purchase
         | id, and the URL is still handed back.
         */
        try {
            $this->updater->markPending(
                $order,
                $session->reference,
                $gateway->label(),
                $session->checkoutUrl,
            );
        } catch (Throwable $e) {
            Log::error('A shop checkout was opened but could not be recorded. The buyer was sent to it anyway.', [
                'reference' => $order->reference,
                'purchase_id' => $session->reference,
                'error' => $e->getMessage(),
            ]);
        }

        return $session->checkoutUrl;
    }

    /**
     * What may be done with the attempt this order already has at the gateway.
     *
     * The rules are in OpenCheckout, shared with the registration path so the two
     * cannot drift apart on which gateway states mean "go back to it" and which mean
     * "an attempt is at a bank".
     */
    private function openCheckout(ShopOrder $order): OpenCheckout
    {
        $latest = $order->checkouts()->first();

        return OpenCheckout::at(
            $this->gateways,
            $latest?->purchase_id,
            $latest?->checkout_url,
            $latest?->opened_at,
        );
    }
}
