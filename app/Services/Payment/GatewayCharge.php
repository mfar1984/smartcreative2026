<?php

namespace App\Services\Payment;

/**
 * Something to charge for that is not a registration.
 *
 * Deliberately neutral: it carries a reference, an amount and the lines that add up
 * to it, so a driver can open a checkout without knowing what kind of record it came
 * from. The registration path keeps its own typed method, because that one works and
 * is taking money today.
 */
readonly class GatewayCharge
{
    /**
     * @param  string  $reference  our own reference, e.g. SO-2026-0001
     * @param  int  $amountCents  what must be collected, in the minor unit
     * @param  array<int, array{name: string, price: int, quantity: string}>  $products
     * @param  array<string, string>  $client  email, and optionally full_name / phone
     */
    public function __construct(
        public string $reference,
        public int $amountCents,
        public array $products,
        public array $client,
    ) {
    }
}
