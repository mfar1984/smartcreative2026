<?php

namespace App\Services\Payment;

use App\Models\EventRegistration;
use App\Support\PaymentSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CHIP (chip-in.asia) Purchases API.
 *
 * A purchase is created server side, and CHIP answers with a hosted checkout
 * URL to send the payer to. The redirect back is only a hint: the payment is
 * marked paid by the signed webhook, which ChipWebhookController handles.
 *
 * Reference: https://docs.chip-in.asia/ (Purchases)
 */
class ChipGateway implements PaymentGateway
{
    private const BASE_URL = 'https://gate.chip-in.asia/api/v1';

    private const TIMEOUT_SECONDS = 20;

    /** Language of the hosted checkout page. */
    private const LANGUAGE = 'en';

    /**
     * The status field on a purchase object => the payment status it means.
     *
     * Distinct from the webhook's map, which keys off event names. Anything not
     * listed leaves the registration as it is rather than guessing.
     */
    private const PURCHASE_STATUS_MAP = [
        'paid' => EventRegistration::PAYMENT_PAID,
        'settled' => EventRegistration::PAYMENT_PAID,
        'captured' => EventRegistration::PAYMENT_PAID,
        'refunded' => EventRegistration::PAYMENT_REFUNDED,
        'partially_refunded' => EventRegistration::PAYMENT_REFUNDED,
        'error' => EventRegistration::PAYMENT_FAILED,
        'expired' => EventRegistration::PAYMENT_FAILED,
        'cancelled' => EventRegistration::PAYMENT_FAILED,
        'blocked' => EventRegistration::PAYMENT_FAILED,
        'created' => EventRegistration::PAYMENT_PENDING,
        'pending_execute' => EventRegistration::PAYMENT_PENDING,
        'pending_charge' => EventRegistration::PAYMENT_PENDING,
        'pending_capture' => EventRegistration::PAYMENT_PENDING,
        'pending_release' => EventRegistration::PAYMENT_PENDING,
        'hold' => EventRegistration::PAYMENT_PENDING,
        'preauthorized' => EventRegistration::PAYMENT_PENDING,
    ];

    public function key(): string
    {
        return PaymentSettings::PROVIDER_CHIP;
    }

    public function label(): string
    {
        return PaymentSettings::PROVIDERS[PaymentSettings::PROVIDER_CHIP];
    }

    public function isConfigured(): bool
    {
        return filled(PaymentSettings::chipBrandId()) && filled(PaymentSettings::chipApiKey());
    }

    public function createCheckout(EventRegistration $registration, CheckoutUrls $urls): CheckoutSession
    {
        if (! $this->isConfigured()) {
            throw PaymentGatewayException::notConfigured($this->label());
        }

        $payload = $this->payload($registration, $urls);

        try {
            $response = Http::withToken(PaymentSettings::chipApiKey())
                ->acceptJson()
                ->asJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(self::BASE_URL . '/purchases/', $payload);
        } catch (ConnectionException $e) {
            throw new PaymentGatewayException('Could not reach CHIP: ' . $e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            // Logged rather than shown: the body can quote ids and settings.
            Log::warning('CHIP purchase creation failed.', [
                'registration' => $registration->reference,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new PaymentGatewayException(sprintf(
                'CHIP returned HTTP %d creating a purchase for %s.',
                $response->status(),
                $registration->reference,
            ));
        }

        $id = $response->json('id');
        $checkoutUrl = $response->json('checkout_url');

        if (! is_string($id) || $id === '' || ! is_string($checkoutUrl) || $checkoutUrl === '') {
            throw new PaymentGatewayException(
                'CHIP accepted the purchase but returned no id or checkout URL for ' . $registration->reference . '.'
            );
        }

        return new CheckoutSession($id, $checkoutUrl);
    }

    /**
     * Read a purchase back from CHIP and translate its status.
     *
     * Failures return null rather than throwing: the caller is showing a page
     * to someone who has just paid, and an unreachable gateway should leave the
     * status alone instead of breaking the page.
     */
    public function fetchStatus(string $gatewayReference): ?string
    {
        $payment = $this->fetchPayment($gatewayReference);

        return $payment === null ? null : $this->statusFromPayment($payment);
    }

    /**
     * The purchase object, exactly as CHIP returned it.
     *
     * Failures return null rather than throwing: the caller is usually drawing a
     * page, and an unreachable gateway should leave that page working on what it
     * already has instead of breaking.
     *
     * @return array<string, mixed>|null
     */
    public function fetchPayment(string $gatewayReference): ?array
    {
        if (! $this->isConfigured() || $gatewayReference === '') {
            return null;
        }

        try {
            $response = Http::withToken(PaymentSettings::chipApiKey())
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->get(self::BASE_URL . '/purchases/' . urlencode($gatewayReference) . '/');
        } catch (ConnectionException $e) {
            Log::info('CHIP purchase lookup could not connect.', [
                'purchase_id' => $gatewayReference,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::info('CHIP purchase lookup failed.', [
                'purchase_id' => $gatewayReference,
                'status' => $response->status(),
            ]);

            return null;
        }

        $payment = $response->json();

        return is_array($payment) ? $payment : null;
    }

    /**
     * The merchant account balance, per currency, exactly as CHIP returned it.
     *
     * Uses the same key as the Purchases calls above; CHIP accepts it on this
     * endpoint too, so no extra credential is needed.
     *
     * Every figure comes back in the minor unit, so 11600 is RM 116.00. The
     * translation is left to the caller because this class deliberately hands
     * back CHIP's own shape and nothing else.
     *
     * Failures return null rather than throwing. The caller is drawing a sidebar
     * on every page, and an unreachable gateway must not take the admin down with
     * it.
     *
     * @return array<string, array<string, mixed>>|null
     */
    public function fetchBalance(): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::withToken(PaymentSettings::chipApiKey())
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->get(self::BASE_URL . '/account/json/balance/');
        } catch (ConnectionException $e) {
            Log::info('CHIP balance lookup could not connect.', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            /*
             | Status only. A 400 body is shaped {"__all__": {"message": ..., "code": ...}}
             | and that message can quote account details, so it is not carried
             | anywhere the operator could see it.
             */
            Log::warning('CHIP balance lookup failed.', [
                'status' => $response->status(),
            ]);

            return null;
        }

        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        // Keep only well shaped currency entries, so one odd key cannot break a
        // caller that trusted the array.
        return array_filter($body, fn ($figures) => is_array($figures));
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    public function statusFromPayment(array $payment): ?string
    {
        $status = $payment['status'] ?? null;

        if (! is_string($status)) {
            return null;
        }

        return self::PURCHASE_STATUS_MAP[$status] ?? null;
    }

    /* ---------------------------------------------------------------------
     | Request body
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function payload(EventRegistration $registration, CheckoutUrls $urls): array
    {
        $products = $this->products($registration);

        // CHIP totals the product lines itself, so a mismatch here would charge
        // an amount the invoice never showed.
        $lineTotal = array_sum(array_map(
            fn (array $product) => $product['price'] * (int) $product['quantity'],
            $products,
        ));

        $expected = $this->cents((float) $registration->amount);

        if ($lineTotal !== $expected) {
            throw new PaymentGatewayException(sprintf(
                'Line items for %s total %d cents but the registration is %d cents.',
                $registration->reference,
                $lineTotal,
                $expected,
            ));
        }

        $payload = [
            'brand_id' => PaymentSettings::chipBrandId(),
            'reference' => $registration->reference,

            'client' => $this->client($registration),

            'purchase' => [
                'currency' => PaymentSettings::currency(),
                'language' => self::LANGUAGE,
                'products' => $products,
            ],

            'success_redirect' => $urls->success,
            'failure_redirect' => $urls->failure,
            'cancel_redirect' => $urls->cancel,

            // The webhook is what actually settles the payment; the redirects
            // above only decide what the payer sees. Omitted when CHIP would
            // refuse it, see acceptableCallback().
            'success_callback' => $this->acceptableCallback($urls->callback, $registration),
        ];

        // The official SDK drops empty values before sending (Purchase and
        // Product both jsonSerialize through array_filter), so an absent
        // callback has to be absent rather than null.
        return array_filter($payload, fn ($value) => $value !== null && $value !== '');
    }

    /**
     * The callback URL if CHIP will accept it, otherwise null.
     *
     * CHIP validates this field and rejects the entire purchase when the URL
     * carries a non default port:
     *
     *     success_callback: You can't use custom ports in callback
     *     (only 80/443, as defined by HTTP(S) scheme are supported).
     *
     * A dev server on :8000 would therefore make every payment impossible. The
     * field is optional, so it is dropped instead, and the return page falls
     * back to reading the purchase through fetchStatus().
     *
     * That fallback is fine while developing but not in production, where a
     * payer who closes the tab would leave a paid registration sitting unpaid.
     * So a live site refuses to proceed rather than run blind.
     */
    private function acceptableCallback(string $url, EventRegistration $registration): ?string
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? '';
        $port = $parts['port'] ?? null;

        $isDefaultPort = $port === null
            || ($scheme === 'https' && $port === 443)
            || ($scheme === 'http' && $port === 80);

        if ($isDefaultPort) {
            return $url;
        }

        if (app()->isProduction()) {
            throw new PaymentGatewayException(
                sprintf('Webhook URL %s uses a port CHIP will not call. Serve the site on 80 or 443.', $url),
                'Payment cannot be taken because this site is not reachable by the payment provider. Please contact the organiser.',
            );
        }

        Log::warning('CHIP callback dropped: the URL uses a port CHIP refuses to call.', [
            'reference' => $registration->reference,
            'url' => $url,
            'note' => 'Payment status will be read back from CHIP on return instead of pushed by webhook.',
        ]);

        return null;
    }

    /**
     * The fee and every add-on line, so the CHIP receipt itemises what was
     * bought rather than showing one lump sum.
     *
     * price is in cents. quantity is a string, matching the official SDK, whose
     * Product model declares it as a string and casts on the way in.
     *
     * @return array<int, array<string, mixed>>
     */
    private function products(EventRegistration $registration): array
    {
        $registration->loadMissing(['event', 'addonLines']);

        $products = [];

        if ((float) $registration->registration_fee > 0) {
            $products[] = [
                'name' => $this->trim($registration->event?->title ?? 'Event registration'),
                'price' => $this->cents((float) $registration->registration_fee),
                'quantity' => '1',
            ];
        }

        foreach ($registration->addonLines as $line) {
            $products[] = [
                'name' => $this->trim($line->describe()),
                'price' => $this->cents((float) $line->unit_price),
                'quantity' => (string) (int) $line->quantity,
            ];
        }

        // A zero fee event with only add-ons is possible, but a purchase with no
        // lines at all is not something to send.
        if ($products === []) {
            throw new PaymentGatewayException(
                'Nothing to charge on ' . $registration->reference . '.',
                'There is nothing to pay on this registration.',
            );
        }

        return $products;
    }

    /**
     * @return array<string, string>
     */
    private function client(EventRegistration $registration): array
    {
        $payer = $registration->loadMissing('participants')->participants
            ->sortBy(fn ($participant) => $participant->id)
            ->first();

        $client = [
            // CHIP requires an email to send the receipt to. The first person on
            // the registration is the one who filled the form in.
            'email' => $payer?->email ?? '',
        ];

        if (filled($payer?->full_name)) {
            $client['full_name'] = $this->trim($payer->full_name, 128);
        }

        if (filled($payer?->phone)) {
            $client['phone'] = $this->trim($payer->phone, 32);
        }

        if ($client['email'] === '') {
            throw new PaymentGatewayException(
                'No payer email on ' . $registration->reference . '.',
                'We need an email address to raise the payment. Please contact the organiser.',
            );
        }

        return $client;
    }

    /**
     * Ringgit to cents.
     *
     * Rounded before casting, because (int) truncates and 45.00 can arrive as
     * 44.999999 from a float multiplication.
     */
    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function trim(string $value, int $limit = 256): string
    {
        return mb_substr(trim($value), 0, $limit);
    }
}
