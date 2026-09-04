<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\EventRegistration;
use App\Services\Payment\RegistrationPaymentUpdater;
use App\Support\PaymentSettings;
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

    public function __construct(private readonly RegistrationPaymentUpdater $updater)
    {
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
            // Most likely a purchase created outside this site. Acknowledged so
            // CHIP stops retrying, but recorded so it can be looked into.
            Log::info('CHIP webhook ignored: purchase does not match a registration.', [
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
