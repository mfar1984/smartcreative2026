<?php

namespace App\Mail;

use App\Models\ShopOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A link to pay for an order online.
 *
 * Sent when somebody in the admin decides this order is worth chasing: a card or
 * online-banking order the buyer never completed. Nothing here charges anything — the
 * link opens the order's own page, which carries the Pay Now button.
 *
 * The property is orderUrl, not payUrl, and the name is doing work: it is the GET
 * confirmation page, which is the only kind of URL an email may carry. The POST pay
 * route would be a 405 for every recipient whose mail client follows links.
 */
class ShopOrderPaymentLink extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ShopOrder $order,
        public string $orderUrl,
        public ?string $collectionSummary = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            // The reference leads, because it is what the buyer searches their inbox
            // for later.
            subject: sprintf('Order %s: pay %s online', $this->order->reference, $this->order->grandTotalLabel()),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.shop-order-payment-link');
    }
}
