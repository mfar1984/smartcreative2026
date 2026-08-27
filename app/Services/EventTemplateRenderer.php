<?php

namespace App\Services;

use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\EventTemplate;
use App\Support\EventTemplates;
use App\Support\GatewayPaymentRecord;
use Illuminate\Support\Carbon;

/**
 * Turns a saved template into a finished message.
 *
 * Placeholders are replaced with values read from the registration, never from
 * anything the recipient controls. The body stays plain text: the email layout
 * escapes it on the way out, so a template cannot smuggle markup into an inbox.
 *
 * A placeholder the renderer does not know is left exactly as typed rather than
 * blanked, so a typo shows up as {{amout}} in a preview instead of silently
 * disappearing and leaving a sentence that reads wrong.
 */
class EventTemplateRenderer
{
    /**
     * Fill in a template for a registration.
     *
     * $recipients is who this particular copy is addressed to. It is a list
     * rather than one person because a single address often stands for several
     * players: a manager enters their own email for anyone who has none, and
     * that address should receive one message covering all of them rather than
     * one message each.
     *
     * @param  iterable<EventParticipant>  $recipients
     * @return array{subject: string, body: string}
     */
    public function render(
        EventTemplate $template,
        EventRegistration $registration,
        iterable $recipients = [],
        ?string $paymentLink = null,
    ): array {
        $values = $this->values($registration, collect($recipients), $paymentLink);

        return [
            'subject' => $this->substitute((string) $template->subject, $values),
            'body' => $this->substitute($template->body, $values),
        ];
    }

    /**
     * Fill in a template from a map of values worked out by the caller.
     *
     * Needed by the staff alerts, which are not all about a registration: a
     * contact enquiry has no event and no participants, so there is nothing for
     * render() to read values off. Registration-based alerts pass the values from
     * valuesFor() plus their own extras.
     *
     * @param  array<string, string>  $values
     * @return array{subject: string, body: string}
     */
    public function renderWith(EventTemplate $template, array $values): array
    {
        $values = ['site_name' => (string) config('app.name')] + $values;

        return [
            'subject' => $this->substitute((string) $template->subject, $values),
            'body' => $this->substitute($template->body, $values),
        ];
    }

    /**
     * Everything a registration can fill in, for callers assembling their own
     * value map through renderWith().
     *
     * @return array<string, string>
     */
    public function valuesFor(EventRegistration $registration): array
    {
        return $this->values($registration, collect(), null);
    }

    /**
     * Fill in a template with invented data, for the preview screen.
     *
     * @return array{subject: string, body: string}
     */
    public function renderSample(EventTemplate $template): array
    {
        $values = $this->sampleValues();

        return [
            'subject' => $this->substitute((string) $template->subject, $values),
            'body' => $this->substitute($template->body, $values),
        ];
    }

    /* ---------------------------------------------------------------------
     | Values
     * ------------------------------------------------------------------ */

    /**
     * @param  \Illuminate\Support\Collection<int, EventParticipant>  $recipients
     * @return array<string, string>
     */
    private function values(
        EventRegistration $registration,
        $recipients,
        ?string $paymentLink,
    ): array {
        $registration->loadMissing(['event', 'participants']);
        $event = $registration->event;

        $payment = GatewayPaymentRecord::make($registration->payment_details);

        $values = [
            'site_name' => (string) config('app.name'),

            'event_name' => $event?->title ?? '',
            'event_category' => $event?->category ?? '',
            'event_dates' => $this->eventDates($registration),
            'event_time' => $event?->time ?? '',
            'event_location' => $event?->location ?? '',
            'event_address' => $event?->address ?? '',

            'reference' => $registration->reference,
            'team_name' => $registration->displayName(),
            'people_count' => (string) $registration->participants->count(),
            'player_list' => $this->playerList($registration),

            'manager_name' => $this->manager($registration)?->full_name ?? '',
            'manager_email' => $this->manager($registration)?->email ?? '',
            'manager_phone' => $this->manager($registration)?->phone ?? '',

            'amount' => $registration->amountLabel(),
            'registration_fee' => $registration->registrationFeeLabel(),
            'addons_total' => $registration->addonsTotalLabel(),
            'payment_status' => $registration->paymentStatusLabel(),

            // Empty rather than absent when no link applies, so a template that
            // asks for one on a player message produces nothing instead of the
            // raw placeholder.
            'payment_link' => $paymentLink ?? '',

            'paid_on' => $payment?->paidOn()?->format('d M Y, g:i a') ?? '',
            'payment_method' => $payment?->paymentMethod() ?? '',
            'payment_reference' => $registration->payment_reference ?? '',
        ];

        if ($recipients->isNotEmpty()) {
            $values += [
                'recipient_players' => $this->recipientBlock($recipients),
                'recipient_count' => (string) $recipients->count(),
            ];

            // The singular forms describe the first of them, which is the only
            // sensible answer when a template asks for one name and the address
            // covers several. Documented as such on the settings screen.
            $first = $recipients->first();

            $values += [
                'participant_name' => $first->full_name,
                'participant_ic' => $first->ic_number,
                'participant_ic_masked' => $this->maskCard($first->ic_number),
                'participant_ign' => $first->ignLabel(),
                'participant_role' => $first->roleLabel(),
            ];
        }

        return $values;
    }

    /**
     * Each person at this address, with what was recorded about them.
     *
     * @param  \Illuminate\Support\Collection<int, EventParticipant>  $recipients
     */
    private function recipientBlock($recipients): string
    {
        return $recipients
            ->map(function (EventParticipant $person) {
                $parts = [$person->roleLabel() . ': ' . $person->full_name];
                $parts[] = 'IC ' . $this->maskCard($person->ic_number);

                if ($person->hasIgn()) {
                    $parts[] = 'In-game ' . $person->ignLabel();
                }

                return implode(', ', $parts);
            })
            ->implode("\n");
    }

    /**
     * @return array<string, string>
     */
    private function sampleValues(): array
    {
        return [
            'site_name' => (string) config('app.name'),

            'event_name' => 'PUBG MOBILE SIBU ESPORT CHAMPIONSHIP 2026',
            'event_category' => 'E-Sport',
            'event_dates' => '26 – 27 Sep 2026',
            'event_time' => '08:00 am - 17:00 pm',
            'event_location' => 'RH Hotel Sibu',
            'event_address' => 'RH Hotel Sibu, Jalan Kampung Nyabor, 96000 Sibu, Sarawak',

            'reference' => 'REG-2026-0003',
            'team_name' => 'HARIMAU SIBU ESPORT',
            'people_count' => '5',
            'player_list' => "Manager: MOHD RIZAL BIN ABDULLAH\nPlayer: AZHAR BIN SULAIMAN\nPlayer: KENNY LAU CHEE MING\nPlayer: SYAFIQ BIN RAMLI\nPlayer: DAYANG NURUL BINTI AWANG",

            'manager_name' => 'MOHD RIZAL BIN ABDULLAH',
            'manager_email' => 'rizal.harimau@example.test',
            'manager_phone' => '0138801201',

            'amount' => 'RM 120.00',
            'registration_fee' => 'RM 120.00',
            'addons_total' => 'RM 0.00',
            'payment_status' => 'Awaiting Payment',

            'payment_link' => url('/registration/payment/REG-2026-0003?expires=0&signature=sample'),

            'paid_on' => '26 Aug 2026, 5:49 pm',
            'payment_method' => 'FPX',
            'payment_reference' => 'a5b39d8f-f03a-4b1a-95f5-00a29ea49382',

            // Two people at one address, which is the case worth previewing:
            // a manager who entered their own email for players without one.
            'recipient_players' => "Player: AZHAR BIN SULAIMAN, IC 01041513****, In-game 5211480932 on Asia\nPlayer: KENNY LAU CHEE MING, IC 02082013****, In-game 5211480933 on Asia",
            'recipient_count' => '2',

            'participant_name' => 'AZHAR BIN SULAIMAN',
            'participant_ic' => '010415135502',
            'participant_ic_masked' => $this->maskCard('010415135502'),
            'participant_ign' => '5211480932 on Asia',
            'participant_role' => 'Player',

            // Staff only, so the Telegram templates preview as something real.
            'contact_name' => 'MOHD RIZAL BIN ABDULLAH 0138801201',
            'enquiry_name' => 'Tan Wei Ling',
            'enquiry_email' => 'weiling@example.test',
            'enquiry_phone' => '0122334455',
            'enquiry_service' => 'Online Registration Solutions',
            'enquiry_message' => 'Good afternoon, we would like to ask about sponsorship packages for the tournament in September. Who should we speak to?',
            'change_type' => 'Transfer',
            'player_name' => 'AZHAR BIN SULAIMAN',
            'player_ic' => '010415135502',
            'from_team' => 'NAGA RAJANG GAMING',
            'change_reason' => 'Moving to a different squad',
        ];
    }

    /* ---------------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------------ */

    /**
     * Replace every {{token}} the renderer knows about.
     *
     * Whitespace inside the braces is tolerated, because someone typing a
     * template by hand will write {{ event_name }} sooner or later.
     *
     * @param  array<string, string>  $values
     */
    private function substitute(string $text, array $values): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            fn (array $match) => $values[strtolower($match[1])] ?? $match[0],
            $text,
        );
    }

    private function manager(EventRegistration $registration): ?EventParticipant
    {
        $participants = $registration->participants;

        // Falls back to the first person, which covers an individual entry where
        // nobody carries the manager role.
        return $participants->firstWhere('role', \App\Support\ParticipantOptions::ROLE_MANAGER)
            ?? $participants->sortBy('id')->first();
    }

    private function playerList(EventRegistration $registration): string
    {
        return $registration->participants
            ->sortBy('id')
            ->map(fn (EventParticipant $person) => $person->roleLabel() . ': ' . $person->full_name)
            ->implode("\n");
    }

    private function eventDates(EventRegistration $registration): string
    {
        $event = $registration->event;

        if ($event?->starts_at === null) {
            return '';
        }

        if ($event->ends_at === null || $event->starts_at->isSameDay($event->ends_at)) {
            return $event->starts_at->format('d M Y');
        }

        return $event->starts_at->format('d M Y') . ' – ' . $event->ends_at->format('d M Y');
    }

    /**
     * Hide the last four digits of an identity card.
     *
     * Offered as a separate placeholder so an organiser can choose: the full
     * number lets a player verify every digit, the masked one is safer if the
     * message is forwarded or read over someone's shoulder.
     */
    private function maskCard(?string $card): string
    {
        $card = (string) $card;

        if (mb_strlen($card) <= 4) {
            return $card;
        }

        return mb_substr($card, 0, mb_strlen($card) - 4) . '****';
    }
}
