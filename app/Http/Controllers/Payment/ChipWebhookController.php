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

        $registration = EventRegistration::query()
            ->where('payment_reference', $purchaseId)
            ->first();

        if ($registration === null) {
            // Most likely a purchase created outside this site. Acknowledged so
            // CHIP stops retrying, but recorded so it can be looked into.
            Log::info('CHIP webhook ignored: purchase does not match a registration.', [
                'event' => $event,
                'purchase_id' => $purchaseId,
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

        // The pushed body is the purchase object itself, so it is kept as the
        // freshest record of the payment for the admin detail screen.
        if (is_string($payload['status'] ?? null)) {
            $this->updater->rememberPayment($registration, $payload);
        }

        $this->updater->apply($registration, $status, $event);

        return response()->json(['message' => 'Acknowledged.']);
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
