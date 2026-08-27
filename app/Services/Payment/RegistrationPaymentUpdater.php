<?php

namespace App\Services\Payment;

use App\Models\EventRegistration;
use App\Services\AdminLogger;
use App\Services\EventNotifier;
use App\Services\Messaging\StaffAlerts;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single place a registration's payment status is moved.
 *
 * Both the signed webhook and the return-from-gateway page write through here,
 * so the ordering guards and the audit trail cannot drift apart between them.
 */
class RegistrationPaymentUpdater
{
    public function __construct(
        private readonly EventNotifier $notifier,
        private readonly StaffAlerts $alerts,
    ) {
    }

    /**
     * @param  string  $status  one of EventRegistration::PAYMENT_*
     * @param  string  $source  short description for the log, e.g. the gateway event name
     * @return bool  whether anything changed
     */
    public function apply(EventRegistration $registration, string $status, string $source): bool
    {
        if (! $this->shouldApply($registration, $status)) {
            return false;
        }

        $before = [
            'payment_status' => $registration->payment_status,
            'status' => $registration->status,
        ];

        $registration->payment_status = $status;

        // Paying confirms the place; a failure leaves it pending so an
        // administrator can chase it rather than losing the entry.
        if ($status === EventRegistration::PAYMENT_PAID) {
            $registration->status = EventRegistration::STATUS_CONFIRMED;
        }

        $registration->save();

        AdminLogger::activity(
            'payments.status',
            sprintf('Payment for %s became %s (%s).', $registration->reference, $status, $source),
        );

        AdminLogger::audit($registration, 'payment.updated', $before, [
            'payment_status' => $registration->payment_status,
            'status' => $registration->status,
            'source' => $source,
        ]);

        // Told once, and only once: shouldApply() has already refused a repeat
        // of a status the registration is sitting on, so a reloaded return page
        // or a webhook arriving twice cannot send a second receipt.
        if ($status === EventRegistration::PAYMENT_PAID) {
            $this->announcePayment($registration);
        }

        return true;
    }

    /**
     * Read the payment back from the gateway, keep a copy, and apply whatever
     * status it reports.
     *
     * The copy is what the admin detail screen shows, so it is stored even when
     * the status has not moved: the gateway record carries the payment method,
     * the timeline and the fees, none of which live on the registration.
     *
     * @return array<string, mixed>|null  the record, or null when unreadable
     */
    public function syncFromGateway(EventRegistration $registration, PaymentGateway $gateway): ?array
    {
        if (blank($registration->payment_reference)) {
            return null;
        }

        $payment = $gateway->fetchPayment($registration->payment_reference);

        if ($payment === null) {
            return null;
        }

        $registration->payment_details = $payment;
        $registration->payment_synced_at = now();
        $registration->save();

        $status = $gateway->statusFromPayment($payment);

        if ($status !== null) {
            $this->apply($registration, $status, 'gateway lookup');
        }

        return $payment;
    }

    /**
     * Keep the gateway's own record of a payment, without touching its status.
     *
     * Used by the webhook, where the pushed body is the purchase object itself,
     * so the admin sees fresh detail without anyone opening a page.
     *
     * @param  array<string, mixed>  $payment
     */
    public function rememberPayment(EventRegistration $registration, array $payment): void
    {
        $registration->payment_details = $payment;
        $registration->payment_synced_at = now();
        $registration->save();
    }

    /**
     * Record that a checkout has been opened, without claiming it succeeded.
     */
    public function markPending(EventRegistration $registration, string $gatewayReference, string $source): void
    {
        $registration->payment_reference = $gatewayReference;
        $registration->payment_status = EventRegistration::PAYMENT_PENDING;

        // A new checkout means a new purchase at the gateway, so anything held
        // about the previous attempt no longer describes this one.
        $registration->payment_details = null;
        $registration->payment_synced_at = null;

        $registration->save();

        AdminLogger::activity(
            'payments.checkout',
            sprintf('Opened a %s checkout for %s.', $source, $registration->reference),
        );
    }

    /**
     * Tell the manager and the players that the money arrived.
     *
     * Wrapped because this runs on the webhook path: the payment is already
     * recorded, and answering the gateway with a failure would have it retry a
     * status change that has in fact been applied. A notification problem is
     * logged and left for the resend button.
     */
    private function announcePayment(EventRegistration $registration): void
    {
        try {
            $this->notifier->paymentReceived($registration);
        } catch (Throwable $exception) {
            Log::error('Payment was recorded but the notifications could not be raised.', [
                'registration' => $registration->reference,
                'error' => $exception->getMessage(),
            ]);
        }

        $this->alerts->paymentReceived($registration);
    }

    private function shouldApply(EventRegistration $registration, string $status): bool
    {
        if ($registration->payment_status === $status) {
            return false;
        }

        // A settled purchase must not be dragged back to pending by a late
        // arriving earlier event, nor by someone reloading the return page.
        if ($registration->payment_status === EventRegistration::PAYMENT_PAID
            && $status === EventRegistration::PAYMENT_PENDING) {
            return false;
        }

        // A refund is an administrative decision. Nothing coming back from a
        // checkout should quietly undo it.
        if ($registration->payment_status === EventRegistration::PAYMENT_REFUNDED
            && $status !== EventRegistration::PAYMENT_PAID) {
            return false;
        }

        return true;
    }
}
