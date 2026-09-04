<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\EventRegistration;
use App\Services\Payment\CheckoutUrls;
use App\Services\Payment\PaymentGatewayException;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\RegistrationPaymentUpdater;
use App\Support\PaymentSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * The invoice a registrant lands on after submitting, and the hand off to the
 * gateway.
 *
 * Every URL here is signed. A reference like REG-2026-0007 is trivial to guess,
 * and the page shows what was ordered and what is owed, so a plain path would
 * let anyone walk the sequence and read other people's invoices.
 */
class RegistrationPaymentController extends Controller
{
    /**
     * How long a payment link stays valid.
     *
     * Long enough to come back to later, short enough that a leaked link does
     * not stay live forever.
     */
    private const LINK_DAYS = 30;

    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly RegistrationPaymentUpdater $updater,
    ) {
    }

    /**
     * Signed URL for a registration's payment page, for use in redirects and
     * anywhere the link needs to be handed out.
     */
    public static function urlFor(EventRegistration $registration): string
    {
        return URL::temporarySignedRoute(
            'registration.payment',
            now()->addDays(self::LINK_DAYS),
            ['reference' => $registration->reference],
        );
    }

    public function show(string $reference)
    {
        $registration = $this->find($reference);

        // The gateway may already have answered while the payer was away, so the
        // page is brought up to date before it is drawn.
        $this->reconcile($registration);

        return view('pages.registration-payment', $this->viewData($registration));
    }

    /**
     * Open a checkout and send the payer to it.
     */
    public function pay(string $reference)
    {
        $registration = $this->find($reference);

        if (! $registration->awaitingPayment()) {
            return redirect()->to(self::urlFor($registration));
        }

        /*
         | Send an impatient payer back to the checkout they already have, rather than
         | opening a second one.
         |
         | This is the fix for the fault that started all of this. Pressing Pay twice
         | used to create two purchases at the gateway; whichever one settled, the
         | registration ended up pointing at the other, and a real RM 250 payment went
         | unmatched. Reusing the live attempt means there is only ever one purchase to
         | settle.
         |
         | Only while the attempt is still open. A failed or expired one is not
         | reusable, and a payer who abandoned a QR code deserves a fresh page.
         */
        if ($reusable = $this->reusableCheckout($registration)) {
            return redirect()->away($reusable);
        }

        try {
            $gateway = $this->gateways->active();

            $session = $gateway->createCheckout($registration, new CheckoutUrls(
                success: $this->returnUrl($registration, 'success'),
                failure: $this->returnUrl($registration, 'failure'),
                cancel: $this->returnUrl($registration, 'cancel'),
                callback: route('payments.chip.webhook'),
            ));
        } catch (PaymentGatewayException $e) {
            Log::warning('Could not open a checkout.', [
                'reference' => $registration->reference,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->to(self::urlFor($registration))
                ->withErrors(['payment' => $e->publicMessage()]);
        }

        // Recorded before the redirect, so the webhook can find this
        // registration by the gateway's id whatever happens next.
        $this->updater->markPending(
            $registration,
            $session->reference,
            $gateway->label(),
            $session->checkoutUrl,
        );

        return redirect()->away($session->checkoutUrl);
    }

    /**
     * The checkout URL of an attempt that is still open, or null.
     *
     * Asks the gateway rather than trusting the stored status, because the stored one
     * is only as fresh as the last webhook that got through. A purchase the gateway
     * reports as still awaiting execution is one the payer can go back to.
     *
     * Any problem reaching the gateway returns null, so the worst case is the old
     * behaviour of opening a new checkout rather than a payer stuck at an error.
     */
    private function reusableCheckout(EventRegistration $registration): ?string
    {
        $latest = $registration->checkouts()->first();

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

        // The states where the payer has somewhere to go back to. Anything settled,
        // failed or expired is finished with.
        $open = ['created', 'viewed', 'pending_execute', 'pending_charge'];

        return is_string($status) && in_array($status, $open, true)
            ? $latest->checkout_url
            : null;
    }

    /**
     * Where the gateway sends the payer back to.
     *
     * The outcome in the URL is treated as a hint only. What marks a payment
     * paid is the signed webhook, or a direct read of the purchase below;
     * never a query string, which the payer controls.
     */
    public function handleReturn(string $reference, string $outcome)
    {
        $registration = $this->find($reference);

        $this->reconcile($registration);

        // array_merge, not +: the union operator keeps the left hand value, so
        // the outcome would stay null.
        return view('pages.registration-payment', array_merge($this->viewData($registration), [
            'outcome' => in_array($outcome, ['success', 'failure', 'cancel'], true) ? $outcome : null,
        ]));
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    private function find(string $reference): EventRegistration
    {
        return EventRegistration::query()
            ->with(['event', 'addonLines', 'participants'])
            ->where('reference', $reference)
            ->firstOrFail();
    }

    /**
     * Ask the gateway what happened, when there is something to ask about.
     *
     * This is what keeps the page honest where webhooks cannot arrive, such as a
     * machine the gateway cannot reach.
     */
    private function reconcile(EventRegistration $registration): void
    {
        if (blank($registration->payment_reference)) {
            return;
        }

        // Nothing to learn about a payment that has already settled.
        if ($registration->isPaid() || $registration->payment_status === EventRegistration::PAYMENT_REFUNDED) {
            return;
        }

        try {
            $this->updater->syncFromGateway($registration, $this->gateways->active());
        } catch (PaymentGatewayException) {
            // Nothing to learn right now. The page draws from what is stored.
        }
    }

    private function returnUrl(EventRegistration $registration, string $outcome): string
    {
        return URL::temporarySignedRoute(
            'registration.payment.return',
            now()->addDays(self::LINK_DAYS),
            ['reference' => $registration->reference, 'outcome' => $outcome],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(EventRegistration $registration): array
    {
        return [
            'pageTitle' => 'Payment',
            'pageSubtitle' => 'Registration ' . $registration->reference,

            'registration' => $registration,
            'event' => $registration->event,
            'currency' => PaymentSettings::currency(),

            'payUrl' => URL::temporarySignedRoute(
                'registration.payment.pay',
                now()->addDays(self::LINK_DAYS),
                ['reference' => $registration->reference],
            ),

            // Decides whether a Pay Now button is shown at all. Offering one that
            // cannot work would be worse than saying so plainly.
            'gatewayReady' => $this->gateways->isUsable(),
            'gatewayLabel' => PaymentSettings::providerLabel(),

            'outcome' => null,
        ];
    }
}
