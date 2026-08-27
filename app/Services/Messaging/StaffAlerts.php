<?php

namespace App\Services\Messaging;

use App\Models\ContactMessage;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\EventTemplate;
use App\Services\EventTemplateRenderer;
use App\Support\EventTemplates;
use App\Support\ParticipantOptions;
use App\Support\TelegramSettings;

/**
 * The staff alerts posted into one Telegram group.
 *
 * The wording lives in editable templates under Event > Settings > Telegram
 * Template, the same as the participant channels. What each alert can say is
 * fixed by its placeholder list; what it does say is not.
 *
 * Every method returns whether anything was posted, and none of them throw: an
 * office notification is worth less than the thing it reports, so a broken bot
 * token must not cost a registration.
 *
 * Values are escaped on the way in. These messages go out as HTML and carry team
 * names and text typed by the public, so an unescaped angle bracket would have
 * Telegram reject the whole post.
 */
class StaffAlerts
{
    public function __construct(
        private readonly TelegramNotifier $telegram,
        private readonly EventTemplateRenderer $renderer,
    ) {
    }

    public function registrationReceived(EventRegistration $registration): bool
    {
        $template = $this->template('staff.registration', 'notify_registration');

        if ($template === null) {
            return false;
        }

        $registration->loadMissing(['event', 'participants']);

        return $this->post($template, $this->entryValues($registration), 'notify_registration');
    }

    public function paymentReceived(EventRegistration $registration): bool
    {
        $template = $this->template('staff.payment', 'notify_payment');

        if ($template === null) {
            return false;
        }

        $registration->loadMissing(['event', 'participants']);

        return $this->post($template, $this->entryValues($registration), 'notify_payment');
    }

    public function enquiryReceived(ContactMessage $message): bool
    {
        $template = $this->template('staff.enquiry', 'notify_enquiry');

        if ($template === null) {
            return false;
        }

        return $this->post($template, [
            'enquiry_name' => (string) $message->name,
            'enquiry_email' => (string) $message->email,
            // Cast to string because the map is typed as strings; a null phone
            // becomes '' here and a dash in escaped().
            'enquiry_phone' => (string) $message->phone,
            // The form asks which service they want, not for a free subject line.
            'enquiry_service' => $message->serviceLabel(),
            // Trimmed rather than sent whole: the group is for noticing, and
            // anyone who needs the full text opens the admin.
            'enquiry_message' => \Illuminate\Support\Str::limit((string) $message->message, 400),
        ], 'notify_enquiry');
    }

    /**
     * A player taken off an entry at the counter.
     *
     * Posted because it is the one counter action with no undo, and it changes
     * who is expected to turn up after the lists have been printed.
     */
    public function playerRemoved(EventRegistration $registration, string $name, string $card, ?string $reason): bool
    {
        $template = $this->template('staff.counter', 'notify_attendance');

        if ($template === null) {
            return false;
        }

        $registration->loadMissing('event');

        return $this->post($template, $this->entryValues($registration) + [
            'change_type' => 'Removed',
            'player_name' => $name,
            'player_ic' => $card,
            // Nobody arrived from anywhere, so there is no origin team to name.
            'from_team' => '—',
            'change_reason' => filled($reason) ? $reason : '—',
        ], 'notify_attendance');
    }

    /**
     * A player moved from one team to another in the same event.
     */
    public function playerTransferred(
        EventParticipant $participant,
        EventRegistration $to,
        ?EventRegistration $from,
    ): bool {
        $template = $this->template('staff.counter', 'notify_attendance');

        if ($template === null) {
            return false;
        }

        $to->loadMissing('event');

        return $this->post($template, $this->entryValues($to) + [
            'change_type' => 'Transfer',
            'player_name' => (string) $participant->full_name,
            'player_ic' => (string) $participant->ic_number,
            'from_team' => $from?->displayName() ?? 'an entry since removed',
            'change_reason' => 'The team they left is now a player short.',
        ], 'notify_attendance');
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * The template for this alert, or null when it should not be posted.
     *
     * Two gates, matching the other channels: the alert switch on the Integration
     * screen, and the template's own active flag on Event > Settings.
     */
    private function template(string $key, string $alert): ?EventTemplate
    {
        if (! TelegramSettings::alerts($alert)) {
            return null;
        }

        $template = EventTemplate::lookup($key, EventTemplates::CHANNEL_TELEGRAM);

        return $template !== null && $template->is_active ? $template : null;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function post(EventTemplate $template, array $values, string $alert): bool
    {
        $rendered = $this->renderer->renderWith($template, $this->escaped($values));

        return $this->telegram->post($rendered['body'], $alert);
    }

    /**
     * What an entry can tell the office.
     *
     * Wider than a participant sees, on purpose: staff get the unmasked contact
     * details, because chasing somebody is the reason they are reading this.
     *
     * @return array<string, string>
     */
    private function entryValues(EventRegistration $registration): array
    {
        $manager = $registration->participants->firstWhere('role', ParticipantOptions::ROLE_MANAGER)
            ?? $registration->participants->sortBy('id')->first();

        return $this->renderer->valuesFor($registration) + [
            'contact_name' => $manager === null
                ? '—'
                : trim($manager->full_name . ' ' . ($manager->phone ?? '')),
        ];
    }

    /**
     * Escaped for HTML, because that is the parse mode these are sent with.
     *
     * Done here rather than in the template so an operator can still use <b> for
     * a heading: their markup is trusted, the values substituted into it are not.
     *
     * Blanks become a dash on the way through. A line reading "Paid on:" with
     * nothing after it looks like a fault; a dash reads as "not recorded", which
     * is what it means.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function escaped(array $values): array
    {
        return array_map(
            fn (string $value) => filled(trim($value)) ? e($value) : '—',
            $values,
        );
    }
}
