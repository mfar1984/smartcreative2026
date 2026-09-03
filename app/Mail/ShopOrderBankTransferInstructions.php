<?php

namespace App\Mail;

use App\Models\ShopOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * What to pay, where to pay it, and how to send us the proof.
 *
 * Sent when an order is placed against a manual bank transfer. Nothing observes a
 * transfer arriving, so the buyer has to tell us it happened and somebody has to
 * check it against the account. This is the first half of that: the account details
 * and a link that lets them upload the receipt.
 *
 * @param  array{name: string, bank: string, number: string}|null  $bankAccount
 */
class ShopOrderBankTransferInstructions extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ShopOrder $order,
        public ?array $bankAccount,
        public ?string $bankNote,
        public string $receiptUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            // The reference leads, because it is what the buyer has to quote on the
            // transfer and what they will search their inbox for later.
            subject: sprintf('Order %s: complete your bank transfer', $this->order->reference),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.shop-order-bank-transfer');
    }
}
