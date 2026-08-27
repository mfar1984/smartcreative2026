<?php

namespace App\Services\Payment;

use App\Models\EventRegistration;

interface PaymentGateway
{
    /** Provider slug, matching PaymentSettings::PROVIDER_*. */
    public function key(): string;

    public function label(): string;

    /** Whether the credentials needed to call the gateway are present. */
    public function isConfigured(): bool;

    /**
     * Open a checkout for the amount owed on this registration.
     *
     * @throws PaymentGatewayException when the gateway refuses or cannot be reached
     */
    public function createCheckout(EventRegistration $registration, CheckoutUrls $urls): CheckoutSession;

    /**
     * Ask the gateway what became of a payment it already knows about.
     *
     * Needed because a webhook can be delayed or, on a machine the gateway
     * cannot reach, never arrive at all. Returns one of
     * EventRegistration::PAYMENT_* or null when the answer is inconclusive.
     */
    public function fetchStatus(string $gatewayReference): ?string;

    /**
     * The gateway's own record of a payment, exactly as it returned it.
     *
     * Deliberately unmapped: the admin detail screen shows this verbatim, so
     * reshaping it here would hide what the gateway actually said.
     *
     * @return array<string, mixed>|null  null when it could not be read
     */
    public function fetchPayment(string $gatewayReference): ?array;

    /** Translate a raw payment record into one of EventRegistration::PAYMENT_*. */
    public function statusFromPayment(array $payment): ?string;
}
