<?php

namespace App\Services\Payment;

use App\Support\PaymentSettings;

/**
 * Hands back the driver for whichever provider is currently selected.
 *
 * Only CHIP has a driver. The other providers exist on the Integration screen
 * as credential fields, and asking for one raises an exception rather than
 * pretending a payment was taken.
 */
class PaymentGatewayManager
{
    /**
     * @var array<string, class-string<PaymentGateway>>
     */
    private const DRIVERS = [
        PaymentSettings::PROVIDER_CHIP => ChipGateway::class,
    ];

    /**
     * @throws PaymentGatewayException
     */
    public function active(): PaymentGateway
    {
        $provider = PaymentSettings::provider();

        if ($provider === PaymentSettings::PROVIDER_NONE) {
            throw PaymentGatewayException::notConfigured('No gateway');
        }

        $driver = self::DRIVERS[$provider] ?? null;

        if ($driver === null) {
            throw PaymentGatewayException::unsupported(PaymentSettings::providerLabel());
        }

        /** @var PaymentGateway $gateway */
        $gateway = app($driver);

        if (! $gateway->isConfigured()) {
            throw PaymentGatewayException::notConfigured($gateway->label());
        }

        return $gateway;
    }

    /**
     * Whether a payment could be started right now, without throwing.
     *
     * Used to decide whether to show a Pay Now button at all, so a visitor is
     * never sent to a gateway that cannot answer.
     */
    public function isUsable(): bool
    {
        try {
            $this->active();

            return true;
        } catch (PaymentGatewayException) {
            return false;
        }
    }
}
