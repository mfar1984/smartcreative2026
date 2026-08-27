<?php

namespace App\Services\Payment;

/**
 * What a gateway hands back after a checkout has been opened.
 */
readonly class CheckoutSession
{
    public function __construct(
        /** The gateway's own id for the payment, stored as payment_reference. */
        public string $reference,

        /** Where to send the payer to complete it. */
        public string $checkoutUrl,
    ) {
    }
}
