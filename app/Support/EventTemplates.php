<?php

namespace App\Support;

/**
 * What message templates exist, and which placeholders each one may use.
 *
 * Kept here rather than in the database because the set of moments the system
 * can speak at is decided by code: adding a row would create a template nothing
 * ever sends. The database holds the wording; this holds the shape.
 *
 * Placeholders are scoped per template on purpose. A player's notice must not
 * offer {{payment_link}}, because that link can pay the invoice and only the
 * manager should hold it.
 */
class EventTemplates
{
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS = 'sms';
    public const CHANNEL_TELEGRAM = 'telegram';

    public const CHANNELS = [
        self::CHANNEL_EMAIL => 'Email Template',
        self::CHANNEL_SMS => 'SMS Template',
        self::CHANNEL_TELEGRAM => 'Telegram Template',
    ];

    /**
     * Channels that speak to participants, and so share one set of moments.
     *
     * Telegram is not among them. It posts into one staff group, which makes its
     * moments different: an office does not need the manager told one thing and
     * the players another, and it does want to hear about enquiries and counter
     * changes, which no participant ever receives.
     */
    private const PARTICIPANT_CHANNELS = [self::CHANNEL_EMAIL, self::CHANNEL_SMS];

    /* ---------------------------------------------------------------------
     | Placeholders
     * ------------------------------------------------------------------ */

    /**
     * Every placeholder the renderer understands, with what it stands for.
     *
     * @var array<string, string>
     */
    public const PLACEHOLDERS = [
        'site_name' => 'Name of this site',

        'event_name' => 'Event title',
        'event_category' => 'Event category, for example E-Sport',
        'event_dates' => 'Event dates, already formatted',
        'event_time' => 'Event time as entered on the event',
        'event_location' => 'Venue name',
        'event_address' => 'Full venue address',

        'reference' => 'Registration reference, for example REG-2026-0003',
        'team_name' => 'Team name, or the person\'s name on a solo entry',
        'people_count' => 'How many people are on the registration',
        'player_list' => 'The people on the registration, one per line',

        'manager_name' => 'Name of whoever registered',
        'manager_email' => 'Email of whoever registered',
        'manager_phone' => 'Telephone of whoever registered',

        'amount' => 'Total payable, for example RM 120.00',
        'registration_fee' => 'Event fee portion',
        'addons_total' => 'Extras portion',
        'payment_status' => 'Payment status in words',

        'payment_link' => 'Link for the manager to pay. Never put this in a player message',
        'paid_on' => 'When payment was received',
        'payment_method' => 'How it was paid, for example FPX',
        'payment_reference' => 'The gateway\'s own reference',

        // One address can stand for several people, because a manager often
        // enters their own email for players who have none. These cover that
        // case correctly whether it is one person or ten.
        'recipient_players' => 'Every person this address covers, one per line with their card and game ID',
        'recipient_count' => 'How many people this address covers',

        // Singular forms. Safe only where one address means one person; when an
        // address covers several they fall back to the first of them.
        'participant_name' => 'Name of the person. With several at one address, the first of them',
        'participant_ic' => 'Their identity card number in full',
        'participant_ic_masked' => 'Their identity card with the last digits hidden',
        'participant_ign' => 'Their in-game Player ID and Server ID',
        'participant_role' => 'Manager or Player',

        // Staff only. These describe things no participant is ever told about,
        // so they appear on the Telegram templates and nowhere else.
        'enquiry_name' => 'Name the enquiry was sent under',
        'enquiry_email' => 'Their email address',
        'enquiry_phone' => 'Their telephone number, or a dash',
        'enquiry_service' => 'The service they enquired about',
        'enquiry_message' => 'The message itself, trimmed to fit',
        'contact_name' => 'Name and telephone of whoever registered',
        'change_type' => 'Removed, or Transfer',
        'player_name' => 'The player the change is about',
        'player_ic' => 'Their identity card number',
        'from_team' => 'The team a transferred player came from',
        'change_reason' => 'Reason the counter gave, or a dash',
    ];

    /** Placeholders that describe the entry itself, available everywhere. */
    private const SHARED = [
        'site_name', 'event_name', 'event_category', 'event_dates', 'event_time',
        'event_location', 'event_address', 'reference', 'team_name', 'people_count',
        'manager_name', 'payment_status',
    ];

    /**
     * Placeholders about whoever this copy is addressed to.
     *
     * The group forms come first because they are the ones a template should
     * normally reach for: an address may stand for more than one person.
     */
    private const RECIPIENT = [
        'recipient_players', 'recipient_count',
        'participant_name', 'participant_ic', 'participant_ic_masked',
        'participant_ign', 'participant_role',
    ];

    /** Money, which only the person paying needs spelled out. */
    private const MONEY = ['amount', 'registration_fee', 'addons_total'];

    /** Details of a payment that has already happened. */
    private const RECEIPT = ['paid_on', 'payment_method', 'payment_reference'];

    /**
     * What a staff alert about an entry can say.
     *
     * Wider than a participant's set because the office is allowed to see the
     * whole picture, including money and unmasked contact details. It stops short
     * of the payment link: that link pays the invoice, and it belongs to the
     * person who owes it rather than to a group chat.
     */
    private const STAFF_ENTRY = [
        'site_name', 'event_name', 'event_dates', 'event_location',
        'reference', 'team_name', 'people_count', 'player_list',
        'manager_name', 'manager_email', 'manager_phone', 'contact_name',
        'amount', 'payment_status',
    ];

    /* ---------------------------------------------------------------------
     | The templates
     * ------------------------------------------------------------------ */

    /**
     * key => definition.
     *
     * `audience` is who receives it, which decides the placeholder set and is
     * shown on screen so an operator knows who they are writing to.
     *
     * @var array<string, array<string, mixed>>
     */
    public const TEMPLATES = [
        'registration.manager' => [
            'label' => 'Registration Received — Manager',
            'audience' => 'The person who submitted the registration',
            'description' => 'Sent as soon as a registration is submitted. This is the only message that carries the payment link.',
            'placeholders' => [
                ...self::SHARED,
                ...self::MONEY,
                'player_list',
                'manager_email',
                'manager_phone',
                'payment_link',
            ],
        ],

        'registration.player' => [
            'label' => 'Registration Received — Player',
            'audience' => 'Each person named on the registration, other than the manager',
            'description' => 'Tells someone their details were entered by a manager, so they can check the record is right. Carries no payment link.',
            'placeholders' => [
                ...self::SHARED,
                ...self::RECIPIENT,
                'manager_email',
            ],
        ],

        /*
        | Sent by hand from the Participants screen, not automatically. Chasing
        | somebody is a judgement call about timing, so it stays a decision an
        | operator makes rather than something on a schedule.
        |
        | Addressed to whoever registered, because they hold the payment. A squad
        | player cannot settle it and telling them it is outstanding would only
        | worry them about something they cannot act on.
        */
        'payment.reminder' => [
            'label' => 'Payment Reminder — Registrant',
            'audience' => 'Whoever registered: the manager of a squad, or the person on a solo entry',
            'description' => 'A chase-up for an entry that is still unpaid. Sent from the Participants list. Carries the payment link.',
            'placeholders' => [
                ...self::SHARED,
                ...self::MONEY,
                'player_list',
                'manager_email',
                'manager_phone',
                'payment_link',
            ],
        ],

        'payment.manager' => [
            'label' => 'Payment Received — Manager',
            'audience' => 'The person who paid',
            'description' => 'Receipt sent once the gateway confirms payment.',
            'placeholders' => [
                ...self::SHARED,
                ...self::MONEY,
                ...self::RECEIPT,
                'player_list',
            ],
        ],

        'payment.player' => [
            'label' => 'Entry Confirmed — Player',
            'audience' => 'Each person named on the registration, other than the manager',
            'description' => 'Confirms the team is paid up and the place is secured.',
            'placeholders' => [
                ...self::SHARED,
                ...self::RECIPIENT,
                'paid_on',
            ],
        ],
    ];

    /**
     * The staff alerts, posted into one Telegram group.
     *
     * Keyed under `staff.` so they never collide with a participant template and
     * so definition() can look both sets up in one place. One message per moment,
     * because the office is one audience.
     *
     * @var array<string, array<string, mixed>>
     */
    public const STAFF_TEMPLATES = [
        'staff.enquiry' => [
            'label' => 'Contact Enquiry',
            'audience' => 'The staff group',
            'description' => 'Posted when somebody sends the contact form. Governed by the Contact enquiry switch under Integration > Telegram.',
            'placeholders' => [
                'site_name',
                'enquiry_name', 'enquiry_email', 'enquiry_phone',
                'enquiry_service', 'enquiry_message',
            ],
        ],

        'staff.registration' => [
            'label' => 'New Registration',
            'audience' => 'The staff group',
            'description' => 'Posted when a registration is submitted, paid or not.',
            'placeholders' => self::STAFF_ENTRY,
        ],

        'staff.payment' => [
            'label' => 'Payment Received',
            'audience' => 'The staff group',
            'description' => 'Posted when the gateway confirms a payment.',
            'placeholders' => [
                ...self::STAFF_ENTRY,
                ...self::RECEIPT,
            ],
        ],

        'staff.counter' => [
            'label' => 'Counter Change',
            'audience' => 'The staff group',
            'description' => 'Posted when a player is removed from an entry or transferred between teams. Routine substitutions are not posted.',
            'placeholders' => [
                'site_name', 'event_name', 'reference', 'team_name',
                'change_type', 'player_name', 'player_ic', 'from_team', 'change_reason',
            ],
        ],
    ];

    /**
     * The participant template keys.
     *
     * No channel argument, and deliberately still participant only: callers that
     * offer a resend button or read a notification label mean these. Staff keys
     * are asked for by name through keysFor().
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::TEMPLATES);
    }

    /**
     * The moments one channel has templates for.
     *
     * @return array<int, string>
     */
    public static function keysFor(string $channel): array
    {
        return array_keys(self::templatesFor($channel));
    }

    /**
     * The template definitions one channel has, keyed by template key.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function templatesFor(string $channel): array
    {
        return $channel === self::CHANNEL_TELEGRAM
            ? self::STAFF_TEMPLATES
            : self::TEMPLATES;
    }

    /** Whether this channel carries a subject line as well as a body. */
    public static function hasSubject(string $channel): bool
    {
        return $channel === self::CHANNEL_EMAIL;
    }

    /** Whether this channel speaks to participants rather than to staff. */
    public static function isParticipantChannel(string $channel): bool
    {
        return in_array($channel, self::PARTICIPANT_CHANNELS, true);
    }

    /**
     * The key in a form safe shape.
     *
     * Template keys carry a dot, and Laravel reads a dot in a validation rule as
     * array nesting, so "templates.registration.manager.body" would be looked
     * for three levels deep. Swapping the dot avoids escaping games in every
     * rule and message.
     */
    public static function formKey(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /**
     * A placeholder wrapped in its braces, ready to display or insert.
     *
     * Built here rather than in a view because Blade reads a literal '{{' in a
     * template as the start of an echo and fails to compile the file.
     */
    public static function token(string $placeholder): string
    {
        return '{{' . $placeholder . '}}';
    }

    public static function fromFormKey(string $formKey): string
    {
        return str_replace('__', '.', $formKey);
    }

    /**
     * One template definition, from whichever set holds it.
     *
     * Both sets are searched because the keys are distinct, so a caller holding
     * only a key does not have to know which audience it belongs to.
     *
     * @return array<string, mixed>|null
     */
    public static function definition(string $key): ?array
    {
        return self::TEMPLATES[$key] ?? self::STAFF_TEMPLATES[$key] ?? null;
    }

    /**
     * Placeholders allowed in one template, with their descriptions.
     *
     * @return array<string, string>
     */
    public static function placeholdersFor(string $key): array
    {
        $allowed = self::definition($key)['placeholders'] ?? [];
        $map = [];

        foreach ($allowed as $placeholder) {
            $map[$placeholder] = self::PLACEHOLDERS[$placeholder] ?? '';
        }

        return $map;
    }

    /**
     * Whether a template speaks to a single named participant rather than to
     * the registration as a whole.
     */
    public static function isPerParticipant(string $key): bool
    {
        return in_array('participant_name', self::definition($key)['placeholders'] ?? [], true);
    }

    public static function isChannel(?string $channel): bool
    {
        return array_key_exists((string) $channel, self::CHANNELS);
    }
}
