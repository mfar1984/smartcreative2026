<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\EventRegistration;
use App\Services\Coupon\CouponAvailability;
use App\Services\Coupon\RegistrationCouponWriter;
use App\Services\Payment\CheckoutUrls;
use App\Services\Payment\PaymentGatewayException;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\RegistrationBalanceCharge;
use App\Services\Payment\RegistrationPaymentUpdater;
use App\Support\PaymentSettings;
use Illuminate\Http\Request;
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
        private readonly RegistrationBalanceCharge $balance,
        private readonly CouponAvailability $coupons,
        private readonly RegistrationCouponWriter $couponWriter,
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

    /** POST only. The action of the Voucher Code form on the payment page. */
    public static function couponUrl(EventRegistration $registration): string
    {
        return URL::temporarySignedRoute(
            'registration.payment.coupon',
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
     * Apply a voucher code to an entry that has already been submitted.
     *
     * ONLY WHILE NOTHING HAS BEEN PAID, and that is the whole rule here.
     *
     * A discount on a part-paid or settled entry would reduce the charge below money
     * already received, which creates a credit nobody has decided how to refund — so
     * the field is not drawn and this endpoint refuses. The refusal is checked here
     * and not only in the view, because a signed link lives for thirty days and the
     * page it was drawn from can be long out of date by the time it is posted.
     *
     * A refusal is not an error in the entry: the figures are left exactly as they
     * were and the payer is told why, beside a Pay button that still works.
     */
    public function applyCoupon(Request $request, string $reference)
    {
        $registration = $this->find($reference);

        $validated = $request->validate([
            'voucher_code' => ['required', 'string', 'max:64'],
        ]);

        if (! $this->canApplyCoupon($registration)) {
            return redirect()
                ->to(self::urlFor($registration))
                ->withErrors(['voucher_code' => $this->whyCouponRefused($registration)]);
        }

        $offered = $this->coupons->forRegistration($registration);
        $lookup = $this->coupons->lookup($validated['voucher_code'], $offered, Coupon::KIND_EVENT);

        if (! $lookup->succeeded()) {
            return redirect()
                ->to(self::urlFor($registration))
                ->withErrors(['voucher_code' => $lookup->message()]);
        }

        // The claim re-reads the expiry and the remaining count under a lock, so this
        // is where the last code is actually decided.
        $outcome = $this->couponWriter->applyCode($registration, $validated['voucher_code']);

        if (! $outcome->succeeded()) {
            return redirect()
                ->to(self::urlFor($registration))
                ->withErrors(['voucher_code' => $outcome->message()]);
        }

        /*
         | Nothing further is needed when the coupon covered it. The writer has
         | already settled the entry through paymentStatusFromLedger(), which answers
         | PAID for a free one, so the page draws itself as settled and stops offering
         | payment without a single new status rule.
         */
        return redirect()
            ->to(self::urlFor($registration))
            ->with('coupon_status', sprintf(
                '%s %s',
                sprintf('Coupon applied: %s', $outcome->message()),
                $registration->fresh()?->isFree()
                    ? 'There is nothing left to pay.'
                    : 'The amount due has been updated.',
            ));
    }

    /**
     * Open a checkout and send the payer to it.
     */
    public function pay(string $reference)
    {
        $registration = $this->find($reference);

        /*
         | owesBalance() rather than awaitingPayment(), so a part-paid entry may settle
         | the rest here.
         |
         | awaitingPayment() excludes it on the grounds that the ordinary checkout is
         | built from the full charge and would take the whole fee again. That reasoning
         | still holds, and is why a part-paid entry is sent to the gateway through
         | RegistrationBalanceCharge below, which asks for the balance alone.
         */
        if (! $registration->owesBalance()) {
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

            $urls = new CheckoutUrls(
                success: $this->returnUrl($registration, 'success'),
                failure: $this->returnUrl($registration, 'failure'),
                cancel: $this->returnUrl($registration, 'cancel'),
                callback: route('payments.chip.webhook'),
            );

            /*
             | Two ways to the same gateway, and which one is used is decided by the row
             | rather than by anything the payer sends.
             |
             | Nothing has arrived: the ordinary checkout, itemising the fee and every
             | add-on, because that is what the invoice says and what the receipt should.
             |
             | Some of it has arrived: one line for the balance, recomputed here. Those
             | invoice lines add up to the full charge, so sending them would ask for the
             | whole fee a second time.
             */
            $session = $registration->amountPaid() > 0
                ? $gateway->createCharge($this->balance->build($registration), $urls)
                : $gateway->createCheckout($registration, $urls);
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

            /*
             | Whether a Voucher Code box is drawn. Three things have to be true: a
             | coupon is ticked on this event and still usable, nothing has been paid,
             | and no coupon is on the entry already. See canApplyCoupon().
             */
            'canApplyCoupon' => $this->canApplyCoupon($registration),
            'couponUrl' => self::couponUrl($registration),

            'outcome' => null,
        ];
    }

    /* ---------------------------------------------------------------------
     | Coupons
     * ------------------------------------------------------------------ */

    /**
     * Whether a code may still be applied to this entry.
     *
     * The money rule first: nothing may be taken off a charge once money has arrived
     * against it. `amount_paid` is asked directly rather than trusted to the status,
     * because a payment recorded by hand and a status that has not caught up are two
     * different things and only one of them is the ledger.
     *
     * awaitingPayment() then rules out the rest — settled, refunded, cancelled, free
     * — on the same terms the Pay button uses, so the two controls on this page can
     * never disagree about where the entry stands.
     */
    private function canApplyCoupon(EventRegistration $registration): bool
    {
        if ($registration->amountPaid() > 0.005) {
            return false;
        }

        if ($registration->hasDiscount() || ! $registration->awaitingPayment()) {
            return false;
        }

        return $this->coupons->forRegistration($registration)->isNotEmpty();
    }

    /** Why a code cannot be applied, in words rather than a silent no-op. */
    private function whyCouponRefused(EventRegistration $registration): string
    {
        if ($registration->amountPaid() > 0.005) {
            return sprintf(
                'We have already received %s against this registration, so a coupon cannot be applied to it now. Contact us quoting %s.',
                $registration->amountPaidLabel(),
                $registration->reference,
            );
        }

        if ($registration->hasDiscount()) {
            return 'A coupon has already been applied to this registration.';
        }

        if ($registration->isFree()) {
            return 'There is nothing to discount on this registration.';
        }

        return sprintf(
            'A coupon cannot be applied to this registration. Contact us quoting %s if you think that is wrong.',
            $registration->reference,
        );
    }
}
