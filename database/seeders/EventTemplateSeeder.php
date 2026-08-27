<?php

namespace Database\Seeders;

use App\Models\EventTemplate;
use App\Support\EventTemplates;
use Illuminate\Database\Seeder;

/**
 * Working wording for every template, so the screen is usable straight away
 * rather than presenting four empty boxes.
 *
 * Existing rows are left alone: re-running this must not overwrite wording an
 * organiser has edited.
 */
class EventTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->defaults() as $channel => $templates) {
            foreach ($templates as $key => $template) {
                EventTemplate::firstOrCreate(
                    ['key' => $key, 'channel' => $channel],
                    [
                        'subject' => $template['subject'] ?? null,
                        'body' => $template['body'],
                        'is_active' => true,
                    ],
                );
            }
        }
    }

    /**
     * @return array<string, array<string, array<string, string>>>
     */
    private function defaults(): array
    {
        return [
            EventTemplates::CHANNEL_EMAIL => [
                'registration.manager' => [
                    'subject' => 'Registration received: {{event_name}} ({{reference}})',
                    'body' => <<<'TEXT'
                    Hello {{manager_name}},

                    We have received your registration for {{event_name}}.

                    Reference: {{reference}}
                    Team: {{team_name}}
                    People entered: {{people_count}}

                    Event details
                    {{event_dates}}
                    {{event_time}}
                    {{event_location}}

                    Who you entered
                    {{player_list}}

                    Amount payable: {{amount}}
                    Status: {{payment_status}}

                    Your place is held until payment is settled. Pay here:
                    {{payment_link}}

                    Everyone you named has been emailed separately so they can check their
                    own details are correct.

                    Thank you,
                    {{site_name}}
                    TEXT,
                ],

                /*
                | Written for an address rather than a person, because one
                | address often stands for several players. {{recipient_players}}
                | lists whoever this copy covers, which reads correctly whether
                | that is one person or ten.
                */
                'registration.player' => [
                    'subject' => 'Entered for {{event_name}}',
                    'body' => <<<'TEXT'
                    Hello,

                    {{manager_name}} has entered the team {{team_name}} for {{event_name}}.

                    This message covers the following, who were listed against this email
                    address:

                    {{recipient_players}}

                    Event details
                    {{event_dates}}
                    {{event_time}}
                    {{event_location}}

                    Please check the details above are right. If anything is wrong, or you did
                    not expect to be entered, reply to this email or contact {{manager_name}} at
                    {{manager_email}}.

                    The team's entry is currently {{payment_status}}. The manager handles the
                    payment; there is nothing for you to pay here.

                    Reference: {{reference}}

                    Thank you,
                    {{site_name}}
                    TEXT,
                ],

                /*
                | A chase-up, so it opens with what is owed rather than with
                | thanks. Deliberately plain about the consequence of not
                | paying, since that is the only reason to send it.
                */
                'payment.reminder' => [
                    'subject' => 'Payment still outstanding: {{event_name}} ({{reference}})',
                    'body' => <<<'TEXT'
                    Hello {{manager_name}},

                    Your entry for {{event_name}} is not yet paid, so the place is not
                    confirmed.

                    Reference: {{reference}}
                    Team: {{team_name}}
                    People entered: {{people_count}}
                    Amount due: {{amount}}

                    Pay here
                    {{payment_link}}

                    People on this entry
                    {{player_list}}

                    If you have already paid, or you no longer want the place, reply to this
                    email and tell us so we can sort it out.

                    Thank you,
                    {{site_name}}
                    TEXT,
                ],

                'payment.manager' => [
                    'subject' => 'Payment received: {{event_name}} ({{reference}})',
                    'body' => <<<'TEXT'
                    Hello {{manager_name}},

                    Your payment for {{event_name}} has been received. Your place is confirmed.

                    Reference: {{reference}}
                    Team: {{team_name}}

                    Payment
                    Amount: {{amount}}
                    Event fee: {{registration_fee}}
                    Extras: {{addons_total}}
                    Paid on: {{paid_on}}
                    Method: {{payment_method}}
                    Transaction: {{payment_reference}}

                    Event details
                    {{event_dates}}
                    {{event_time}}
                    {{event_location}}
                    {{event_address}}

                    Your team
                    {{player_list}}

                    Please keep this email as your receipt.

                    Thank you,
                    {{site_name}}
                    TEXT,
                ],

                'payment.player' => [
                    'subject' => 'Place confirmed: {{event_name}}',
                    'body' => <<<'TEXT'
                    Hello,

                    {{team_name}} has settled payment for {{event_name}}, so the place is now
                    confirmed.

                    This message covers the following, who were listed against this email
                    address:

                    {{recipient_players}}

                    Event details
                    {{event_dates}}
                    {{event_time}}
                    {{event_location}}
                    {{event_address}}

                    Bring your identity card. We check it against the name on the entry when
                    you arrive, so the two must match.

                    Paid on: {{paid_on}}
                    Reference: {{reference}}

                    See you there,
                    {{site_name}}
                    TEXT,
                ],
            ],

            /*
            | Deliberately terse. One GSM segment is 160 characters, and these
            | are measured after substitution, not before: a 41 character event
            | name eats a quarter of the budget on its own.
            |
            | {{site_name}} is left out because the sender ID already identifies
            | who sent it, and repeating a 22 character brand name is the
            | difference between one segment and two.
            */
            EventTemplates::CHANNEL_SMS => [
                'registration.manager' => [
                    'body' => '{{team_name}} registered for {{event_name}}. Ref {{reference}}. {{amount}} due, payment link emailed.',
                ],

                'registration.player' => [
                    'body' => '{{manager_name}} entered you for {{event_name}} with {{team_name}}. Ref {{reference}}. Check your email.',
                ],

                'payment.reminder' => [
                    'body' => '{{team_name}}: {{amount}} still due for {{event_name}}, ref {{reference}}. Place not confirmed until paid. See your email to pay.',
                ],

                'payment.manager' => [
                    'body' => 'Payment {{amount}} received for {{team_name}}, {{event_name}}. Ref {{reference}}. Place confirmed.',
                ],

                'payment.player' => [
                    'body' => '{{team_name}} is paid up for {{event_name}} on {{event_dates}}. Place confirmed. Bring your IC.',
                ],
            ],

            /*
            | Telegram. A staff group, so these are terse and carry detail no
            | participant is shown. Sent as HTML, and <b> is the only markup
            | Telegram needs for a heading.
            */
            EventTemplates::CHANNEL_TELEGRAM => [
                'staff.enquiry' => [
                    'body' => <<<'TEXT'
                    <b>New enquiry</b>
                    From: {{enquiry_name}}
                    Email: {{enquiry_email}}
                    Phone: {{enquiry_phone}}
                    About: {{enquiry_service}}

                    {{enquiry_message}}
                    TEXT,
                ],

                'staff.registration' => [
                    'body' => <<<'TEXT'
                    <b>New registration</b>
                    Event: {{event_name}}
                    Entry: {{team_name}} ({{reference}})
                    People: {{people_count}}
                    Amount: {{amount}} — {{payment_status}}
                    Contact: {{contact_name}}
                    TEXT,
                ],

                'staff.payment' => [
                    'body' => <<<'TEXT'
                    <b>Payment received</b>
                    Event: {{event_name}}
                    Entry: {{team_name}} ({{reference}})
                    Amount: {{amount}}
                    Paid on: {{paid_on}}
                    Gateway ref: {{payment_reference}}
                    TEXT,
                ],

                'staff.counter' => [
                    'body' => <<<'TEXT'
                    <b>Counter change: {{change_type}}</b>
                    Event: {{event_name}}
                    Entry: {{team_name}} ({{reference}})
                    Player: {{player_name}} ({{player_ic}})
                    From: {{from_team}}
                    Reason: {{change_reason}}
                    TEXT,
                ],
            ],
        ];
    }
}
