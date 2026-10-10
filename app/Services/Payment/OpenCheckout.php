<?php

namespace App\Services\Payment;

use App\Support\GatewayPaymentRecord;
use Carbon\CarbonInterface;

/**
 * Whether somebody may be handed back the checkout they already have open at the
 * gateway, must be asked to wait, or needs a fresh purchase.
 *
 * THE FAULT THIS EXISTS TO FIX
 *
 * A registrant reached CHIP, pressed the wrong bank, pressed Back, and from then on
 * every route to payment — the Pay button, the link in his confirmation email — gave
 * him the same purchase, which CHIP had already closed to new attempts. All he could
 * see was "Payment is being processed", and he asked the office whether he was
 * supposed to just wait. Somebody had to send him a fresh gate.chip-in.asia link by
 * hand before he could pay at all.
 *
 * The rule he fell through treated four CHIP states as reusable:
 *
 *     created, viewed, pending_execute, pending_charge
 *
 * The first two are a payer sitting on the hosted page with the bank list in front of
 * him: there is somewhere to go back to. The other two are not. A purchase enters
 * them once an attempt has been STARTED at a bank, which is precisely the moment
 * there is nowhere to go back to — CHIP will not take a second attempt on it.
 *
 * WHY THOSE TWO STATES ARE NOT SIMPLY DELETED FROM THE LIST
 *
 * The reuse is itself a fix. Pressing Pay twice used to open two purchases at the
 * gateway; whichever one settled, the registration was left pointing at the other,
 * and a real RM 250.00 payment went unmatched. Opening a fresh purchase whenever the
 * old one is pending brings that straight back: a payer genuinely standing at his
 * bank, seconds from authorising, would have his money land on a purchase nobody is
 * watching any more.
 *
 * So the state on its own cannot decide this. The AGE of the attempt is what tells
 * the two cases apart, and that is the whole of this class.
 *
 * Shared by the registration controller and the shop's checkout starter. Both had
 * their own copy of the four-state list, which is how one of them ends up fixed and
 * the other forgotten.
 */
readonly class OpenCheckout
{
    /** Nobody has committed to a bank. The page still works, so hand it back. */
    public const REUSE = 'reuse';

    /** An attempt is in flight at a bank. Nothing to hand out, nothing to open. */
    public const IN_PROGRESS = 'in_progress';

    /** Nothing usable at the gateway. Open a new purchase. */
    public const FRESH = 'fresh';

    /**
     * How long an attempt that has reached a bank is treated as still live.
     *
     * It has to comfortably outlast a real FPX hop on a Malaysian bank, because that
     * is a human sequence rather than a machine one: the bank's own login, a TAC that
     * can take minutes to arrive on a congested network, typing it in, confirming.
     * Five minutes does not cover that, and cutting it short is the expensive
     * direction to be wrong in — it reinstates the double-purchase fault above, and
     * the money would be in flight while it happened.
     *
     * It also has to be short enough that the man who pressed the wrong bank is not
     * stranded for an afternoon. He knows within seconds that he is in the wrong
     * place, and the honest thing is to let him try again shortly.
     *
     * A quarter of an hour sits between the two with room on both sides, and the cost
     * of being wrong in this direction is bounded: if the old attempt does settle
     * later, markPending() has kept it on record, the webhook matches on the purchase
     * id it was sent, and the receipt is keyed on that same id. The money lands on the
     * right entry whichever purchase took it.
     */
    public const STALE_AFTER_MINUTES = 15;

    /** CHIP states where the payer is still on the hosted page. */
    private const UNCOMMITTED = ['created', 'viewed'];

    /** CHIP states that mean an attempt has been started at a bank. */
    private const IN_FLIGHT = ['pending_execute', 'pending_charge'];

    private function __construct(
        public string $verdict,
        public ?string $checkoutUrl = null,
    ) {
    }

    /**
     * Decide what to do with the most recent attempt, by asking the gateway about it.
     *
     * The gateway is asked rather than the stored status trusted, because the stored
     * one is only as fresh as the last webhook that got through.
     *
     * Any problem reaching the gateway, or a purchase it does not recognise, answers
     * FRESH. That keeps the worst case as the old behaviour — a new checkout — rather
     * than a payer stuck at an error on a page that cannot help him.
     *
     * @param  string|null  $purchaseId  the gateway's id for the attempt, from our own record of it
     * @param  string|null  $checkoutUrl  where that attempt sent the payer
     * @param  CarbonInterface|null  $openedAt  when this site opened it, used only as a last resort
     */
    public static function at(
        PaymentGatewayManager $gateways,
        ?string $purchaseId,
        ?string $checkoutUrl,
        ?CarbonInterface $openedAt = null,
    ): self {
        if (blank($purchaseId) || blank($checkoutUrl)) {
            return new self(self::FRESH);
        }

        try {
            $payment = $gateways->active()->fetchPayment($purchaseId);
        } catch (PaymentGatewayException) {
            return new self(self::FRESH);
        }

        $record = GatewayPaymentRecord::make($payment);
        $status = $record?->status();

        if ($record === null || $status === null) {
            return new self(self::FRESH);
        }

        if (in_array($status, self::UNCOMMITTED, true)) {
            return new self(self::REUSE, $checkoutUrl);
        }

        /*
         | Settled, failed, expired, refunded, or one of the card hold states this
         | site never opens. Finished with, exactly as before.
         */
        if (! in_array($status, self::IN_FLIGHT, true)) {
            return new self(self::FRESH);
        }

        $startedAt = self::startedAt($record, $openedAt);

        /*
         | The gateway told us nothing about when this started and we have no record
         | of opening it either. Treated as live, which is the conservative direction:
         | the cost of waiting is a few minutes, and the cost of guessing wrong the
         | other way is a second purchase opened under a payment that is in flight.
         */
        if ($startedAt === null) {
            return new self(self::IN_PROGRESS);
        }

        return $startedAt->greaterThan(now()->subMinutes(self::STALE_AFTER_MINUTES))
            ? new self(self::IN_PROGRESS)
            : new self(self::FRESH);
    }

    /** Send them back to the page they already have. */
    public function mayReuse(): bool
    {
        return $this->verdict === self::REUSE && filled($this->checkoutUrl);
    }

    /** An attempt is at a bank. Do not open another, and do not hand out the old one. */
    public function isInProgress(): bool
    {
        return $this->verdict === self::IN_PROGRESS;
    }

    /**
     * What to tell somebody whose attempt is still in flight.
     *
     * Said in one place so the registration page and the shop's confirmation page
     * cannot end up telling the same person two different things. It names what is
     * happening, what to do about it either way, and that they are not being charged
     * twice — which is the first thing anybody in this position actually wants to
     * know.
     */
    public static function holdingMessage(string $reference): string
    {
        return sprintf(
            'A payment for %s is already in progress at the bank, so we have not started a second one. '
                . 'If you are part way through it, finish it in your banking app or page and it will be recorded against this reference. '
                . 'If you pressed the wrong bank or closed the page, wait about %d minutes and press Pay again — '
                . 'a fresh payment page will be opened for you. Nothing is being charged twice.',
            $reference,
            self::STALE_AFTER_MINUTES,
        );
    }

    /**
     * When the attempt reached a bank, as well as that can be known.
     *
     * CHIP's own status history first, newest entry wins. That is the figure that
     * matters rather than when this site opened the purchase: a payer can sit on the
     * hosted page for ten minutes before choosing a bank, and ageing him out from the
     * moment of opening would abandon an attempt he had only just started.
     *
     * Then the purchase's last update, then our own record of opening it.
     */
    private static function startedAt(GatewayPaymentRecord $record, ?CarbonInterface $openedAt): ?CarbonInterface
    {
        foreach (array_reverse($record->timeline()) as $entry) {
            if ($entry['at'] !== null && in_array($entry['status'], self::IN_FLIGHT, true)) {
                return $entry['at'];
            }
        }

        return $record->updatedOn() ?? $openedAt;
    }
}
