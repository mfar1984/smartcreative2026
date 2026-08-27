<?php

namespace App\Services;

use App\Http\Controllers\Payment\RegistrationPaymentController;
use App\Jobs\SendTemplateSms;
use App\Mail\EventTemplateMail;
use App\Models\EventNotification;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\EventTemplate;
use App\Support\EventTemplates;
use App\Support\ParticipantOptions;
use App\Support\PhoneNumber;
use App\Support\SmsSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Decides who hears about a registration, and what each of them is told.
 *
 * Two audiences, two messages. The manager gets the transactional copy with the
 * payment link. Everyone else gets a notice that their details were entered by
 * somebody else, which is the only place a player finds that out, so it carries
 * what was recorded about them and no way to pay.
 *
 * Players are grouped by email address. A manager commonly enters their own
 * address for players who have none, and that address should receive one message
 * covering all of them rather than one message per player.
 */
class EventNotifier
{
    public function __construct(private readonly EventTemplateRenderer $renderer)
    {
    }

    /**
     * Announce a new registration.
     *
     * @return int how many messages were queued
     */
    public function registrationSubmitted(EventRegistration $registration): int
    {
        return $this->dispatch($registration, 'registration.manager', 'registration.player');
    }

    /**
     * Announce that payment has been settled.
     */
    public function paymentReceived(EventRegistration $registration): int
    {
        return $this->dispatch($registration, 'payment.manager', 'payment.player');
    }

    /**
     * Chase an entry that has not been paid for.
     *
     * Goes to the registrant alone. A squad player holds no means to pay, so
     * telling them there is money outstanding would only alarm them about
     * something they cannot act on.
     */
    public function paymentReminder(EventRegistration $registration, ?int $userId = null): int
    {
        $registration->loadMissing(['event', 'participants']);

        return $this->queueForManager($registration, 'payment.reminder', $userId)
            + $this->textForManager($registration, 'payment.reminder', $userId);
    }

    /**
     * Send one template again, by hand, from the admin.
     */
    public function resend(EventRegistration $registration, string $templateKey, ?int $userId = null): int
    {
        $definition = EventTemplates::definition($templateKey);

        if ($definition === null) {
            return 0;
        }

        $registration->loadMissing(['event', 'participants']);

        // Email only, on purpose. Resend is a corrective action for a message
        // that did not arrive, and the reason it did not arrive is almost always
        // a wrong address. Texting people again as a side effect of fixing an
        // email would be a surprise, and a text cannot carry the payment link
        // that makes the manager copy useful anyway.
        return EventTemplates::isPerParticipant($templateKey)
            ? $this->queueForPlayers($registration, $templateKey, $userId)
            : $this->queueForManager($registration, $templateKey, $userId);
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    private function dispatch(EventRegistration $registration, string $managerKey, string $playerKey): int
    {
        $registration->loadMissing(['event', 'participants']);

        return $this->queueForManager($registration, $managerKey)
            + $this->queueForPlayers($registration, $playerKey)
            + $this->textForManager($registration, $managerKey)
            + $this->textForPlayers($registration, $playerKey);
    }

    /* ---------------------------------------------------------------------
     | SMS
     |
     | Deliberately a separate path from email rather than a shared loop. The two
     | channels group their recipients differently, hold different wording, and
     | fail in different ways, and a single method trying to serve both ended up
     | saying "address or number" everywhere.
     * ------------------------------------------------------------------ */

    /**
     * Whether a text may be sent for this template at all.
     *
     * Three gates: the channel is switched on and has working credentials, the
     * operator has enabled this alert group, and the wording itself is active.
     * The first two live on the Integration screen, the third on Event > Settings.
     */
    private function smsTemplate(string $key): ?EventTemplate
    {
        if (! SmsSettings::canSend() || ! SmsSettings::allowsTemplate($key)) {
            return null;
        }

        return $this->activeTemplate($key, EventTemplates::CHANNEL_SMS);
    }

    private function textForManager(EventRegistration $registration, string $key, ?int $userId = null): int
    {
        $template = $this->smsTemplate($key);

        if ($template === null) {
            return 0;
        }

        $manager = $this->manager($registration);
        $number = PhoneNumber::toInternational($manager?->phone);

        if ($manager === null || $number === null) {
            $this->record(
                $registration,
                $key,
                '',
                $manager === null ? [] : [$manager->id],
                EventNotification::STATUS_SKIPPED,
                $manager === null
                    ? 'Nobody on the entry to text.'
                    : sprintf('No usable telephone number for %s.', $manager->full_name),
                $userId,
                EventTemplates::CHANNEL_SMS,
            );

            return 0;
        }

        return $this->text($registration, $template, $number, collect([$manager]), $userId);
    }

    private function textForPlayers(EventRegistration $registration, string $key, ?int $userId = null): int
    {
        $template = $this->smsTemplate($key);

        if ($template === null) {
            return 0;
        }

        $manager = $this->manager($registration);

        $players = $registration->participants
            ->reject(fn (EventParticipant $person) => $manager !== null && $person->id === $manager->id);

        if ($players->isEmpty()) {
            return 0;
        }

        // Grouped by the normalised number, so 017-859 1411 and 0178591411 are
        // recognised as one handset and it is not texted twice. Stronger than the
        // email grouping, which can only lowercase.
        $groups = [];
        $unreachable = collect();

        foreach ($players as $person) {
            $number = PhoneNumber::toInternational($person->phone);

            if ($number === null) {
                $unreachable->push($person);

                continue;
            }

            $groups[$number][] = $person;
        }

        if ($unreachable->isNotEmpty()) {
            $this->record(
                $registration,
                $key,
                '',
                $unreachable->pluck('id')->all(),
                EventNotification::STATUS_SKIPPED,
                sprintf(
                    'No usable telephone number for %s.',
                    $unreachable->pluck('full_name')->join(', ', ' and '),
                ),
                $userId,
                EventTemplates::CHANNEL_SMS,
            );
        }

        $queued = 0;

        foreach ($groups as $number => $people) {
            $queued += $this->text(
                $registration,
                $template,
                (string) $number,
                collect($people)->sortBy('id')->values(),
                $userId,
            );
        }

        return $queued;
    }

    /**
     * Render, record, and hand one text to the queue.
     *
     * No payment link is ever passed: a signed URL is far too long for a text
     * message, and truncating one would produce a link that fails signature
     * checks. The SMS wording tells people to look at their email instead.
     *
     * @param  Collection<int, EventParticipant>  $recipients
     */
    private function text(
        EventRegistration $registration,
        EventTemplate $template,
        string $number,
        Collection $recipients,
        ?int $userId,
    ): int {
        $rendered = $this->renderer->render($template, $registration, $recipients, null);

        $notification = $this->record(
            $registration,
            $template->key,
            $number,
            $recipients->pluck('id')->all(),
            EventNotification::STATUS_QUEUED,
            null,
            $userId,
            EventTemplates::CHANNEL_SMS,
        );

        try {
            SendTemplateSms::dispatch($number, $rendered['body'], $notification->id);
        } catch (Throwable $exception) {
            Log::error('Template SMS could not be queued.', [
                'registration' => $registration->reference,
                'template' => $template->key,
                'error' => $exception->getMessage(),
            ]);

            $notification->update([
                'status' => EventNotification::STATUS_FAILED,
                'reason' => $exception->getMessage(),
            ]);

            return 0;
        }

        return 1;
    }

    private function queueForManager(EventRegistration $registration, string $key, ?int $userId = null): int
    {
        $template = $this->activeTemplate($key);

        if ($template === null) {
            return 0;
        }

        $manager = $this->manager($registration);

        if ($manager === null || blank($manager->email)) {
            $this->record($registration, $key, '', [], EventNotification::STATUS_SKIPPED, 'No email address on the registrant.', $userId);

            return 0;
        }

        // The payment link only ever goes here. A player copy is rendered with an
        // empty link, so a template that asks for one produces nothing.
        $link = $registration->awaitingPayment()
            ? RegistrationPaymentController::urlFor($registration)
            : null;

        return $this->queue($registration, $template, $manager->email, collect([$manager]), $link, $userId);
    }

    private function queueForPlayers(EventRegistration $registration, string $key, ?int $userId = null): int
    {
        $template = $this->activeTemplate($key);

        if ($template === null) {
            return 0;
        }

        $manager = $this->manager($registration);

        $players = $registration->participants
            ->reject(fn (EventParticipant $person) => $manager !== null && $person->id === $manager->id);

        if ($players->isEmpty()) {
            // An individual entry has nobody besides the registrant, so there is
            // no player notice to send and nothing has gone wrong.
            return 0;
        }

        // Anyone without an address is recorded rather than dropped silently: a
        // counter swap collects only name, card and phone, so this happens.
        $unreachable = $players->filter(fn (EventParticipant $person) => blank($person->email));

        if ($unreachable->isNotEmpty()) {
            $this->record(
                $registration,
                $key,
                '',
                $unreachable->pluck('id')->all(),
                EventNotification::STATUS_SKIPPED,
                sprintf(
                    'No email address on file for %s.',
                    $unreachable->pluck('full_name')->join(', ', ' and '),
                ),
                $userId,
            );
        }

        $queued = 0;

        // Grouped case insensitively, because Ali@x.com and ali@x.com are one
        // mailbox and should not receive the same notice twice.
        $groups = $players
            ->filter(fn (EventParticipant $person) => filled($person->email))
            ->groupBy(fn (EventParticipant $person) => mb_strtolower(trim($person->email)));

        foreach ($groups as $address => $group) {
            $queued += $this->queue($registration, $template, (string) $address, $group->sortBy('id')->values(), null, $userId);
        }

        return $queued;
    }

    /**
     * Render, record, and hand to the queue.
     *
     * @param  Collection<int, EventParticipant>  $recipients
     */
    private function queue(
        EventRegistration $registration,
        EventTemplate $template,
        string $address,
        Collection $recipients,
        ?string $paymentLink,
        ?int $userId,
    ): int {
        $rendered = $this->renderer->render($template, $registration, $recipients, $paymentLink);

        $notification = $this->record(
            $registration,
            $template->key,
            $address,
            $recipients->pluck('id')->all(),
            EventNotification::STATUS_QUEUED,
            null,
            $userId,
        );

        try {
            // queue() rather than send(): the form must not wait on SMTP, and a
            // squad of eight is nine messages.
            Mail::to($address)->queue(new EventTemplateMail(
                renderedSubject: $rendered['subject'],
                renderedBody: $rendered['body'],
                notificationId: $notification->id,
            ));
        } catch (Throwable $exception) {
            // Failing to queue is different from failing to send. This is a
            // broken queue connection, so it is recorded and swallowed rather
            // than losing a registration that is already saved.
            Log::error('Event notification could not be queued.', [
                'registration' => $registration->reference,
                'template' => $template->key,
                'error' => $exception->getMessage(),
            ]);

            $notification->update([
                'status' => EventNotification::STATUS_FAILED,
                'reason' => $exception->getMessage(),
            ]);

            return 0;
        }

        return 1;
    }

    /**
     * @param  array<int, int>  $participantIds
     */
    private function record(
        EventRegistration $registration,
        string $templateKey,
        string $address,
        array $participantIds,
        string $status,
        ?string $reason,
        ?int $userId,
        string $channel = EventTemplates::CHANNEL_EMAIL,
    ): EventNotification {
        return EventNotification::create([
            'event_registration_id' => $registration->id,
            'template_key' => $templateKey,
            'channel' => $channel,
            'recipient' => $address,
            'participant_ids' => $participantIds,
            'status' => $status,
            'reason' => $reason,
            'queued_at' => $status === EventNotification::STATUS_QUEUED ? now() : null,
            'triggered_by' => $userId,
        ]);
    }

    /**
     * The saved template, or null when it does not exist or is switched off.
     *
     * A switched off template is a deliberate choice on the settings screen, so
     * nothing is recorded for it.
     */
    private function activeTemplate(string $key, string $channel = EventTemplates::CHANNEL_EMAIL): ?EventTemplate
    {
        $template = EventTemplate::lookup($key, $channel);

        return $template !== null && $template->is_active ? $template : null;
    }

    /**
     * Whoever registered. Falls back to the first person, which covers an
     * individual entry where nobody carries the manager role.
     */
    private function manager(EventRegistration $registration): ?EventParticipant
    {
        return $registration->participants->firstWhere('role', ParticipantOptions::ROLE_MANAGER)
            ?? $registration->participants->sortBy('id')->first();
    }
}
