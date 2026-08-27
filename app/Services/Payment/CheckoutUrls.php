<?php

namespace App\Services\Payment;

/**
 * Where a gateway should send the payer, and us, once it has an outcome.
 *
 * Passed in rather than built inside a driver so the routes stay the
 * application's business and a driver stays testable without them.
 */
readonly class CheckoutUrls
{
    public function __construct(
        public string $success,
        public string $failure,
        public string $cancel,

        /** Server to server callback, which is what actually marks a payment paid. */
        public string $callback,
    ) {
    }
}
