<?php

namespace App\Services\Payment;

use App\Models\EventRegistration;

/**
 * A checkout for what is still owed on a registration, rather than for the whole fee.
 *
 * The registration checkout itemises the fee and every add-on line, and CHIP totals
 * those lines itself, so it can only ever charge the full amount. That is right for an
 * entry that has paid nothing and wrong for one that has paid part: offering it the
 * ordinary checkout would take the whole fee a second time.
 *
 * This exists for the entry whose charge was corrected after it had already paid. Six
 * people owed RM 240.00 and RM 40.00 arrived against the old figure, so RM 200.00 is
 * outstanding and RM 200.00 is what the link has to ask for.
 *
 * The amount is recomputed from the row every time this is built. Nothing a request, a
 * link or an email carries contributes to it.
 */
class RegistrationBalanceCharge
{
    /**
     * @throws PaymentGatewayException
     */
    public function build(EventRegistration $registration): GatewayCharge
    {
        $registration->loadMissing(['event', 'participants']);

        $outstanding = $registration->outstandingAmount();
        $amountCents = $this->cents($outstanding);

        if ($amountCents <= 0) {
            throw new PaymentGatewayException(
                'Nothing outstanding on ' . $registration->reference . '.',
                'There is nothing left to pay on this registration.',
            );
        }

        /*
         | One line, deliberately. The entry's own invoice lines add up to the full
         | charge, so quoting them here would put the whole fee back on the CHIP page;
         | what this purchase is for is the balance, and that is what it says.
         */
        $products = [[
            'name' => $this->trim(sprintf(
                'Outstanding balance · %s%s',
                $registration->reference,
                filled($registration->event?->title) ? ' · ' . $registration->event->title : '',
            )),
            'price' => $amountCents,
            'quantity' => '1',
        ]];

        return new GatewayCharge(
            reference: $registration->reference,
            amountCents: $amountCents,
            products: $products,
            client: $this->client($registration),
        );
    }

    /**
     * Who the receipt goes to: whoever filled the form in, which is the first person
     * named on the entry, the same way the full checkout picks them.
     *
     * @return array<string, string>
     */
    private function client(EventRegistration $registration): array
    {
        $payer = $registration->participants->sortBy('id')->first();

        if (blank($payer?->email)) {
            throw new PaymentGatewayException(
                'No payer email on ' . $registration->reference . '.',
                'We need an email address to raise the payment. Please contact the organiser.',
            );
        }

        $client = ['email' => (string) $payer->email];

        if (filled($payer->full_name)) {
            $client['full_name'] = $this->trim($payer->full_name, 128);
        }

        if (filled($payer->phone)) {
            $client['phone'] = $this->trim($payer->phone, 32);
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
