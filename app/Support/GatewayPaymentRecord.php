<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Read only view over a CHIP purchase object.
 *
 * The field names here were taken from a live response rather than inferred, so
 * this class is the one place that knows CHIP's shape. Everything is null safe:
 * a field CHIP stops sending shows as absent instead of breaking the page.
 *
 * Money arrives in cents and timestamps as unix integers, both converted here so
 * the view never does arithmetic.
 */
readonly class GatewayPaymentRecord
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(private array $raw)
    {
    }

    /**
     * @param  array<string, mixed>|null  $raw
     */
    public static function make(?array $raw): ?self
    {
        return $raw === null || $raw === [] ? null : new self($raw);
    }

    /* ---------------------------------------------------------------------
     | Identity
     * ------------------------------------------------------------------ */

    public function id(): ?string
    {
        return $this->string('id');
    }

    public function status(): ?string
    {
        return $this->string('status');
    }

    public function statusLabel(): string
    {
        return str($this->status() ?? 'unknown')->replace('_', ' ')->title()->toString();
    }

    public function reference(): ?string
    {
        return $this->string('reference');
    }

    /** CHIP's own short reference, shown on the payer's statement. */
    public function referenceGenerated(): ?string
    {
        return $this->string('reference_generated');
    }

    /** True while the brand is in test mode, so the page can say so plainly. */
    public function isTest(): bool
    {
        return (bool) data_get($this->raw, 'is_test', false);
    }

    public function platform(): ?string
    {
        return $this->string('platform');
    }

    public function createdFromIp(): ?string
    {
        return $this->string('created_from_ip');
    }

    /* ---------------------------------------------------------------------
     | Money, converted from cents
     * ------------------------------------------------------------------ */

    public function currency(): string
    {
        return $this->string('purchase.currency') ?? $this->string('payment.currency') ?? 'MYR';
    }

    public function amount(): ?float
    {
        return $this->money('payment.amount') ?? $this->money('purchase.total');
    }

    /** What the gateway kept. */
    public function feeAmount(): ?float
    {
        return $this->money('payment.fee_amount');
    }

    /** What reaches the bank account after the fee. */
    public function netAmount(): ?float
    {
        return $this->money('payment.net_amount');
    }

    public function refundableAmount(): ?float
    {
        return $this->money('refundable_amount');
    }

    public function refundAvailability(): ?string
    {
        return $this->string('refund_availability');
    }

    public function markedAsPaid(): bool
    {
        return (bool) data_get($this->raw, 'marked_as_paid', false);
    }

    /* ---------------------------------------------------------------------
     | How it was paid
     * ------------------------------------------------------------------ */

    /** For example FPX, or null before an attempt has been made. */
    public function paymentMethod(): ?string
    {
        $method = $this->string('transaction_data.payment_method');

        return $method === null ? null : strtoupper($method);
    }

    public function flow(): ?string
    {
        return $this->string('transaction_data.flow');
    }

    public function country(): ?string
    {
        return $this->string('transaction_data.country');
    }

    /* ---------------------------------------------------------------------
     | Dates
     * ------------------------------------------------------------------ */

    public function paidOn(): ?Carbon
    {
        return $this->timestamp('payment.paid_on');
    }

    public function createdOn(): ?Carbon
    {
        return $this->timestamp('created_on');
    }

    public function updatedOn(): ?Carbon
    {
        return $this->timestamp('updated_on');
    }

    public function viewedOn(): ?Carbon
    {
        return $this->timestamp('viewed_on');
    }

    public function dueOn(): ?Carbon
    {
        return $this->timestamp('due');
    }

    /** Issue date, sent as a plain date string rather than a timestamp. */
    public function issued(): ?string
    {
        return $this->string('issued');
    }

    /* ---------------------------------------------------------------------
     | Who paid
     * ------------------------------------------------------------------ */

    public function clientName(): ?string
    {
        return $this->string('client.full_name');
    }

    public function clientEmail(): ?string
    {
        return $this->string('client.email');
    }

    public function clientPhone(): ?string
    {
        return $this->string('client.phone');
    }

    /* ---------------------------------------------------------------------
     | Lines and timeline
     * ------------------------------------------------------------------ */

    /**
     * The lines as the gateway recorded them, prices back in ringgit.
     *
     * quantity arrives as a decimal string such as "1.0000", so it is trimmed to
     * a whole number where it is one.
     *
     * @return array<int, array{name: string, quantity: string, price: float, total: float}>
     */
    public function products(): array
    {
        $products = data_get($this->raw, 'purchase.products');

        if (! is_array($products)) {
            return [];
        }

        $lines = [];

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $price = ((int) data_get($product, 'price', 0)) / 100;
            $quantity = (float) data_get($product, 'quantity', 1);

            $lines[] = [
                'name' => (string) data_get($product, 'name', ''),
                'quantity' => rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.') ?: '0',
                'price' => $price,
                'total' => round($price * $quantity, 2),
            ];
        }

        return $lines;
    }

    /**
     * Status changes in the order they happened, newest last.
     *
     * @return array<int, array{status: string, label: string, at: Carbon|null}>
     */
    public function timeline(): array
    {
        $history = data_get($this->raw, 'status_history');

        if (! is_array($history)) {
            return [];
        }

        $entries = [];

        foreach ($history as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $status = (string) data_get($entry, 'status', '');
            $at = data_get($entry, 'timestamp');

            $entries[] = [
                'status' => $status,
                'label' => str($status)->replace('_', ' ')->title()->toString(),
                'at' => is_numeric($at) ? Carbon::createFromTimestamp((int) $at) : null,
            ];
        }

        return $entries;
    }

    public function checkoutUrl(): ?string
    {
        return $this->string('checkout_url');
    }

    public function invoiceUrl(): ?string
    {
        return $this->string('invoice_url');
    }

    /* ---------------------------------------------------------------------
     | The record itself
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    /**
     * Pretty printed for display. Slashes are left unescaped so the URLs in it
     * stay readable.
     */
    public function toJson(): string
    {
        return json_encode($this->raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ?: '{}';
    }

    /* ---------------------------------------------------------------------
     | Readers
     * ------------------------------------------------------------------ */

    /** A non empty string at that path, or null. CHIP sends "" for unset text. */
    private function string(string $path): ?string
    {
        $value = data_get($this->raw, $path);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    /** Cents to ringgit, or null when the field is absent. */
    private function money(string $path): ?float
    {
        $value = data_get($this->raw, $path);

        return is_numeric($value) ? ((int) $value) / 100 : null;
    }

    private function timestamp(string $path): ?Carbon
    {
        $value = data_get($this->raw, $path);

        return is_numeric($value) && (int) $value > 0
            ? Carbon::createFromTimestamp((int) $value)
            : null;
    }
}
