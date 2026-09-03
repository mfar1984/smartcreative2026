<?php

namespace App\Mail;

use App\Models\ShopOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Where and when to collect, and to bring an identity card.
 *
 * Sent once a collected order is paid, whichever way the money arrived. Paid is the
 * right moment rather than placed: an order that is never paid for is not a
 * collection anybody should be told to turn up for.
 *
 * The place and time come from the order's own snapshot, so this repeats exactly
 * what the buyer agreed to at checkout even if the event has since been changed.
 */
class ShopOrderCollectionReady extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ShopOrder $order)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Order %s is ready to collect', $this->order->reference),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.shop-order-collection');
    }
}
