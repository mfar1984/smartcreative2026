<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\EventRegistration;
use App\Models\ShopOrder;
use App\Services\Payment\RegistrationPaymentUpdater;
use App\Services\Payment\ShopOrderPaymentUpdater;
use App\Support\PaymentSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ChipWebhookController extends Controller
{
    /**
     * Header CHIP puts the signature in.
     */
    private const SIGNATURE_HEADER = 'X-Signature';

    /**
     * Gateway event name => the payment status it puts a registration into.
     *
     * Anything not listed is acknowledged but ignored, so CHIP does not retry
     * events this site has no opinion about.
     */
    private const STATUS_MAP = [
        'purchase.paid' => EventRegistration::PAYMENT_PAID,
        'purchase.settled' => EventRegistration::PAYMENT_PAID,
        'purchase.captured' => EventRegistration::PAYMENT_PAID,
        'purchase.payment_failure' => EventRegistration::PAYMENT_FAILED,
        'purchase.cancelled' => EventRegistration::PAYMENT_FAILED,
        'purchase.refunded' => EventRegistration::PAYMENT_REFUNDED,
        'payment.refunded' => EventRegistration::PAYMENT_REFUNDED,
        'purchase.created' => EventRegistration::PAYMENT_PENDING,
        'purchase.pending_execute' => EventRegistration::PAYMENT_PENDING,
        'purchase.pending_charge' => EventRegistration::PAYMENT_PENDING,
        'purchase.hold' => EventRegistration::PAYMENT_PENDING,
        'purchase.preauthorized' => EventRegistration::PAYMENT_PENDING,
    ];

    public function __construct(
        private readonly RegistrationPaymentUpdater $updater,
        private readonly ShopOrderPaymentUpdater $shopUpdater,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = $request->header(self::SIGNATURE_HEADER);

        if (! $this->isTrusted($rawBody, $signature)) {
            // A body that cannot be proven to come from CHIP is not acted on.
            Log::warning('CHIP webhook rejected: signature could not be verified.', [
                'has_signature' => filled($signature),
                'can_verify' => PaymentSettings::canVerifyWebhooks(),
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid signature.'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return response()->json(['message' => 'Malformed payload.'], Response::HTTP_BAD_REQUEST);
        }

        $event = $this->eventName($payload);
        $purchaseId = $this->purchaseId($payload);

        if ($purchaseId === null) {
            Log::info('CHIP webhook ignored: no purchase id in payload.', ['event' => $event]);

            return response()->json(['message' => 'Acknowledged.']);
        }

        $registration = $this->match($payload, $purchaseId, $event);

        if ($registration === null) {
            /*
             | Not a registration, so it may be a shop order. Tried only on this
             | branch, which previously logged and gave up, so the registration money
             | path above behaves exactly as it did before.
             */
            $order = $this->matchOrder($payload, $purchaseId, $event);

            if ($order !== null) {
                return $this->handleShopOrder($order, $payload, $purchaseId, $event);
            }

            // Most likely a purchase created outside this site. Acknowledged so
            // CHIP stops retrying, but recorded so it can be looked into.
            Log::info('CHIP webhook ignored: purchase does not match a registration or a shop order.', [
                'event' => $event,
                'purchase_id' => $purchaseId,
                'reference' => $this->ourReference($payload),
            ]);

            return response()->json(['message' => 'Acknowledged.']);
        }

        $status = self::STATUS_MAP[$event] ?? null;

        if ($status === null) {
            Log::info('CHIP webhook ignored: event not handled.', [
                'event' => $event,
                'reference' => $registration->reference,
            ]);

            return response()->json(['message' => 'Acknowledged.']);
        }

        /*
         | An outcome that settles or reverses the money decides which purchase this
         | registration is about. A pending event does not: the stored reference may
         | well be a newer live attempt, and moving it backwards would send the payer
         | and the gateway to different places.
         */
        if (in_array($status, [EventRegistration::PAYMENT_PAID, EventRegistration::PAYMENT_REFUNDED], true)) {
            $this->updater->adoptPurchase($registration, $purchaseId);
        }

        // The pushed body is the purchase object itself, so it is kept as the
        // freshest record of the payment for the admin detail screen.
        if (is_string($payload['status'] ?? null)) {
            $this->updater->rememberPayment($registration, $payload);
        }

        $this->updater->apply($registration, $status, $event);

        return response()->json(['message' => 'Acknowledged.']);
    }

    /**
     * Find the registration this purchase belongs to.
     *
     * Three ways, tried in order of how directly each one proves the link.
     *
     * The purchase id on the registration is the strongest, and it is what used to be
     * the only check. It is not enough: a payer who presses Pay twice creates a
     * second purchase, the column moves to the second, and when the first is the one
     * that gets paid its webhook arrives describing a purchase nothing points at any
     * more. That is not a hypothetical. It happened, and a paid purchase went
     * unmatched while its registration read "failed".
     *
     * So the recorded attempts are checked next, and then our own reference, which
     * CHIP echoes back in the payload because createCheckout() sends it. Matching on
     * it is safe: the value is one we generated, it is unique, and it never leaves
     * our control.
     *
     * @param  array<string, mixed>  $payload
     */
    private function match(array $payload, string $purchaseId, string $event): ?EventRegistration
    {
        $byPurchase = EventRegistration::query()
            ->where('payment_reference', $purchaseId)
            ->first();

        if ($byPurchase !== null) {
            return $byPurchase;
        }

        $byAttempt = EventRegistration::query()
            ->whereHas('checkouts', fn ($query) => $query->where('purchase_id', $purchaseId))
            ->first();

        if ($byAttempt !== null) {
            Log::info('CHIP webhook matched an earlier checkout attempt.', [
                'event' => $event,
                'purchase_id' => $purchaseId,
                'reference' => $byAttempt->reference,
                'current_reference' => $byAttempt->payment_reference,
            ]);

            return $byAttempt;
        }

        $ourReference = $this->ourReference($payload);

        if ($ourReference === null) {
            return null;
        }

        $byReference = EventRegistration::query()
            ->where('reference', $ourReference)
            ->first();

        if ($byReference !== null) {
            Log::info('CHIP webhook matched on our own reference.', [
                'event' => $event,
                'purchase_id' => $purchaseId,
                'reference' => $ourReference,
            ]);
        }

        return $byReference;
    }

    /**
     * Find the shop order this purchase belongs to.
     *
     * Three fallbacks in the same order of strength as match(), and every one of them
     * filtered to gateway orders.
     *
     * @param  array<string, mixed>  $payload
     */
    private function matchOrder(array $payload, string $purchaseId, string $event): ?ShopOrder
    {
        if ($byPurchase = $this->gatewayOrders()->where('payment_reference', $purchaseId)->first()) {
            return $byPurchase;
        }

        if ($byAttempt = $this->gatewayOrders()
            ->whereHas('checkouts', fn (Builder $query) => $query->where('purchase_id', $purchaseId))
            ->first()) {
            Log::info('CHIP webhook matched a shop order by an earlier checkout attempt.', [
                'event' => $event,
                'purchase_id' => $purchaseId,
                'reference' => $byAttempt->reference,
                'current_reference' => $byAttempt->payment_reference,
            ]);

            return $byAttempt;
        }

        $ourReference = $this->ourReference($payload);

        /*
         | No reference of ours in the body, so the weakest lookup has nothing to work
         | with. Returning here rather than running it: where('reference', null) would
         | be rewritten by the query builder into whereNull('reference'), which matches
         | nothing on a NOT NULL column — right by accident, and the kind of accident
         | that stops being right when somebody changes the column.
         */
        if ($ourReference === null) {
            return null;
        }

        /*
         | Our own reference is the weakest of the three links, so it is trusted only
         | for an order this application actually opened a purchase against.
         |
         | payment_method = 'gateway' is not enough on its own. A gateway order with
         | payment_reference = NULL and no checkout row has never been quoted to CHIP,
         | so a purchase carrying its reference did not come from here — and that is
         | exactly the shape of the orders this change exists to recover. Without this
         | clause a signature-verified purchase.paid carrying reference = 'SO-2026-0002'
         | would run applyPaid() on an order we never charged: paid_at set, stock
         | decremented, collection email sent.
         */
        $byReference = $this->gatewayOrders()
            ->where('reference', $ourReference)
            ->where(fn (Builder $query) => $query
                ->whereNotNull('payment_reference')
                ->orWhereHas('checkouts'))
            ->first();

        if ($byReference !== null) {
            Log::info('CHIP webhook matched a shop order on our own reference.', [
                'event' => $event,
                'purchase_id' => $purchaseId,
                'reference' => $ourReference,
            ]);
        }

        return $byReference;
    }

    /**
     * Only ever a gateway order.
     *
     * A cash on delivery or bank transfer order is settled by a person asserting the
     * money arrived, and this change must not open a second door into that flow:
     * before it, no webhook could reach a shop order at all.
     *
     * Nothing is lost by filtering all three lookups. A shop_order_checkouts row and
     * an SO- purchase at CHIP only ever exist because this application opened them,
     * and it only opens them for a gateway order. The first lookup genuinely needs the
     * filter: confirmPayment() lets an administrator type any string into
     * payment_reference, and a typed bank reference that happened to equal a CHIP
     * purchase id would otherwise settle the wrong order.
     */
    private function gatewayOrders(): Builder
    {
        return ShopOrder::query()->where('payment_method', ShopOrder::METHOD_GATEWAY);
    }

    /**
     * Apply a gateway event to a shop order.
     *
     * Every branch answers 200 so CHIP stops retrying, matching the existing contract:
     * a 500 would have CHIP replay a status change that has in fact been applied.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleShopOrder(ShopOrder $order, array $payload, string $purchaseId, string $event): JsonResponse
    {
        $status = self::STATUS_MAP[$event] ?? null;

        if ($status === null) {
            Log::info('CHIP webhook ignored: event not handled.', [
                'event' => $event,
                'reference' => $order->reference,
            ]);

            return response()->json(['message' => 'Acknowledged.']);
        }

        /*
         | Stored here for every branch except PAID, which stores it itself as the
         | first thing applyPaid() does — it wants the record kept even when it then
         | refuses the move. Skipping it here avoids two saves and two
         | payment_synced_at writes on the one event that matters most.
         */
        if ($status !== EventRegistration::PAYMENT_PAID && is_string($payload['status'] ?? null)) {
            $this->shopUpdater->rememberPayment($order, $payload);
        }

        /*
         | Read before adoptPurchase() and applyPaid() run, because both can change the
         | answer: the first moves payment_reference, the second writes
         | paid_purchase_id.
         |
         | A settled order keeps the id that settled it. Re-pointing payment_reference
         | at a purchase this order was not paid by is how a later refund goes to the
         | wrong purchase, and the shape that produces it is real: an administrator
         | presses Confirm Payment while a CHIP purchase is still open and the buyer
         | then completes it. Money has been taken twice, and the event that proves it
         | must not quietly rewrite the record of the first collection.
         |
         | Note what is NOT used here. payment_reference === $purchaseId, and
         | membership of shop_order_checkouts, are both TRUE for exactly that scenario,
         | because markPending() writes the attempts row and the column before the
         | buyer ever reaches CHIP. Either of them as the test would file the double
         | collection as a harmless replay.
         */
        $known = $order->wasSettledBy($purchaseId);

        if (in_array($status, [EventRegistration::PAYMENT_PAID, EventRegistration::PAYMENT_REFUNDED], true)
            && ! ($order->isPaid() && ! $known)) {
            $this->shopUpdater->adoptPurchase($order, $purchaseId);
        }

        match ($status) {
            EventRegistration::PAYMENT_PAID => $this->shopUpdater->applyPaid($order, $payload, $event, $purchaseId),
            EventRegistration::PAYMENT_FAILED => $this->shopUpdater->applyFailure($order, $event),
            EventRegistration::PAYMENT_REFUNDED => $this->shopUpdater->noteRefund($order, $payload, $event),

            // Pending: nothing to record, the order is already pending_payment.
            default => null,
        };

        return response()->json(['message' => 'Acknowledged.']);
    }

    /**
     * Our own reference, as CHIP echoes it back.
     *
     * Set on the purchase by createCheckout(), so it is the registration's reference
     * rather than anything the gateway invented. CHIP also sends a
     * `reference_generated` of its own, which is deliberately not read here.
     *
     * @param  array<string, mixed>  $payload
     */
    private function ourReference(array $payload): ?string
    {
        foreach ([$payload['reference'] ?? null, $payload['data']['reference'] ?? null] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Verify the RSA signature over the exact bytes CHIP sent.
     *
     * The raw body is used rather than a re-encoded array, because any change
     * in key order or spacing would break the signature.
     */
    private function isTrusted(string $rawBody, ?string $signature): bool
    {
        $publicKey = PaymentSettings::chipWebhookPublicKey();

        if (blank($publicKey) || blank($signature) || $rawBody === '') {
            return false;
        }

        $decoded = base64_decode($signature, true);

        if ($decoded === false) {
            return false;
        }

        $key = openssl_pkey_get_public($publicKey);

        if ($key === false) {
            Log::error('CHIP webhook public key stored in settings could not be parsed.');

            return false;
        }

        return openssl_verify($rawBody, $decoded, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function eventName(array $payload): string
    {
        // CHIP has used more than one key for this over time, so the likely
        // ones are checked rather than assuming a single shape.
        foreach (['event_type', 'event', 'type'] as $key) {
            if (filled($payload[$key] ?? null) && is_string($payload[$key])) {
                return $payload[$key];
            }
        }

        return 'unknown';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function purchaseId(array $payload): ?string
    {
        $candidates = [
            $payload['id'] ?? null,
            $payload['data']['id'] ?? null,
            $payload['purchase']['id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

}
