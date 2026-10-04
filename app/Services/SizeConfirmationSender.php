<?php

namespace App\Services;

use App\Models\EventRegistration;
use App\Support\ParticipantOptions;
use App\Support\ParticipantSizes;

/**
 * Asking one registrant for a shirt size, and the rules about when that is allowed.
 *
 * Shaped after ShopPaymentLinkSender and for the same reason: the envelope on a row
 * and the "ask everybody on this list" button must not be able to disagree about who
 * may be written to, when a registrant is left alone, or what the trail says
 * afterwards. Two copies of those rules is two chances for the bulk press to mail
 * somebody the single press would have refused.
 *
 * NOT GATED ON PAYMENT, deliberately. A paid entry needs a size just as much as an
 * unpaid one — more so, since it is definitely getting a shirt. The only money-shaped
 * condition here is a cancelled entry, which is not getting one at all.
 */
class SizeConfirmationSender
{
    /* Why an entry was passed over. Counted by the bulk action, so each one is a
       bucket a registration falls in exactly once. */
    public const SKIP_CANCELLED = 'cancelled';
    public const SKIP_NO_SIZES = 'no_sizes';
    public const SKIP_NOTHING_MISSING = 'nothing_missing';
    public const SKIP_NO_EMAIL = 'no_email';
    public const SKIP_COOLDOWN = 'cooldown';
    public const SKIP_QUEUE_FAILED = 'queue_failed';

    /**
     * Reason => the words the operator reads.
     *
     * Ordered the way the breakdown reads best, not the way the checks run.
     *
     * @return array<string, string>
     */
    public static function reasons(): array
    {
        return [
            self::SKIP_NOTHING_MISSING => 'every size already recorded',
            self::SKIP_NO_SIZES => 'the event collects no sizes',
            self::SKIP_CANCELLED => 'cancelled',
            self::SKIP_NO_EMAIL => 'no email address on the registrant',
            self::SKIP_COOLDOWN => sprintf('asked in the last %d hours', EventRegistration::SIZE_LINK_COOLDOWN_HOURS),
            self::SKIP_QUEUE_FAILED => 'the email could not be queued',
        ];
    }

    public function __construct(private readonly EventNotifier $notifier)
    {
    }

    /**
     * Why this entry must not be asked, or null when it may be.
     *
     * The order of the checks decides which bucket an entry failing several of them
     * lands in, and runs from the most informative answer down.
     */
    public function skipReason(EventRegistration $registration): ?string
    {
        $registration->loadMissing(['event.addons.variants', 'participants', 'addonLines']);

        if ($registration->status === EventRegistration::STATUS_CANCELLED) {
            return self::SKIP_CANCELLED;
        }

        if (! ParticipantSizes::collectsSizes($registration->event)) {
            return self::SKIP_NO_SIZES;
        }

        if (! ParticipantSizes::needsSizes($registration)) {
            return self::SKIP_NOTHING_MISSING;
        }

        if (blank($this->registrant($registration)?->email)) {
            return self::SKIP_NO_EMAIL;
        }

        /*
         | Only a link the queue accepted holds anybody back. An attempt that failed, or
         | was skipped for want of an address, told nobody anything and must stay
         | pressable the moment the address is corrected.
         */
        $registration->loadMissing('sizeConfirmations');

        if ($registration->sizeLinkSentRecently()) {
            return self::SKIP_COOLDOWN;
        }

        return null;
    }

    /**
     * Queue the link.
     *
     * Returns false when nothing was handed to the queue, which is almost always a
     * template switched off or missing. Nothing is stamped anywhere: the row the
     * notifier writes in the message log IS the record, and a second stamp beside it
     * is the bug this project keeps re-learning.
     *
     * The caller is expected to have asked skipReason() first. It is not re-asked
     * here: the bulk action needs the reason in order to count it, and asking twice
     * would invite the two answers to drift.
     */
    public function send(EventRegistration $registration, ?int $userId = null): bool
    {
        return $this->notifier->sizeConfirmation($registration, $userId) > 0;
    }

    /**
     * The skip counts as one readable clause: "every size already recorded (4)".
     *
     * Empty string when nothing was skipped, so the caller can append it to a sentence
     * without checking.
     *
     * @param  array<string, int>  $skipped  reason => how many
     */
    public static function breakdown(array $skipped): string
    {
        return collect(self::reasons())
            ->map(fn (string $label, string $reason) => ($skipped[$reason] ?? 0) > 0
                ? sprintf('%s (%d)', $label, $skipped[$reason])
                : null)
            ->filter()
            ->join(', ');
    }

    /**
     * Whoever registered: the manager of a squad, or the single person on a solo entry.
     *
     * The same fallback the notifier applies, so "no email address" here means the
     * address the message would actually have gone to.
     */
    private function registrant(EventRegistration $registration)
    {
        return $registration->participants->firstWhere('role', ParticipantOptions::ROLE_MANAGER)
            ?? $registration->participants->sortBy('id')->first();
    }
}
