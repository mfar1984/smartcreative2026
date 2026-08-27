<?php

namespace App\Support;

use App\Models\CampaignContact;
use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who a campaign will actually reach.
 *
 * Two things happen here that a naive "select all applicants" would get wrong.
 *
 * A person is counted once, not once per entry. Somebody who has registered for
 * three events is one person and must receive one message; sending three is the
 * fastest way to be reported as spam by somebody who actually likes you.
 *
 * Consent and suppression are applied before counting, not after sending. The
 * number on the screen is the number of messages that will leave, so an operator
 * deciding whether to spend money on an SMS blast is looking at the real figure.
 */
class CampaignAudience
{
    public const ALL = 'all';
    public const EVENT = 'event';
    public const PAID = 'paid';
    public const ATTENDED = 'attended';
    public const ENQUIRIES = 'enquiries';

    /**
     * Segment => what it means on screen.
     *
     * @var array<string, array{label: string, description: string}>
     */
    public const SEGMENTS = [
        self::ALL => [
            'label' => 'Everyone who has registered',
            'description' => 'Every person named on any entry, across every event.',
        ],
        self::EVENT => [
            'label' => 'One event',
            'description' => 'Everyone named on an entry for the event you choose.',
        ],
        self::PAID => [
            'label' => 'Paid entries only',
            'description' => 'People whose entry was actually paid for. The warmest list you have.',
        ],
        self::ATTENDED => [
            'label' => 'People who turned up',
            'description' => 'Checked in at a counter. Better than registered: they came.',
        ],
        self::ENQUIRIES => [
            'label' => 'Contact form enquiries',
            'description' => 'People who asked about something but never registered.',
        ],
    ];

    public static function isSegment(?string $type): bool
    {
        return array_key_exists((string) $type, self::SEGMENTS);
    }

    public static function label(?string $type, ?string $eventTitle = null): string
    {
        if (! self::isSegment($type)) {
            return 'Unknown';
        }

        if ($type === self::EVENT) {
            return $eventTitle === null ? 'One event' : 'Event: ' . $eventTitle;
        }

        return self::SEGMENTS[$type]['label'];
    }

    /**
     * The contacts a segment covers, already narrowed to who can be sent to.
     *
     * @param  string  $channel  email | sms
     */
    public static function query(string $type, ?int $eventId, string $channel): Builder
    {
        return self::narrow(CampaignContact::query()->reachable($channel), $type, $eventId);
    }

    /**
     * Everybody in a segment an operator may choose from by hand.
     *
     * The same segment as query(), but without the consent requirement, because
     * here the operator is looking at named people and deciding one at a time
     * rather than firing at a rule. Suppression still applies: pickable() drops
     * anybody who unsubscribed, bounced or complained, and that is not negotiable
     * from this screen or any other.
     */
    public static function candidates(string $type, ?int $eventId, string $channel): Builder
    {
        return self::narrow(CampaignContact::query()->pickable($channel), $type, $eventId);
    }

    /**
     * The contacts behind a list of ids the operator ticked.
     *
     * Filtered rather than trusted. The ids arrive from a form, so an id that was
     * never offered, or somebody who unsubscribed between the page loading and the
     * button being pressed, is dropped here rather than sent to.
     *
     * @param  array<int, int|string>  $ids
     */
    public static function picked(array $ids, string $channel): Builder
    {
        $clean = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return CampaignContact::query()
            ->pickable($channel)
            // whereIn with an empty array matches nothing, which is the right
            // answer, but it is stated so the intent is not mistaken for a bug.
            ->whereIn('id', $clean === [] ? [0] : $clean);
    }

    /**
     * Apply a segment's restriction to a contact query.
     */
    private static function narrow(Builder $contacts, string $type, ?int $eventId): Builder
    {
        return match ($type) {
            self::EVENT => $contacts->whereIn('id', self::idsForEvent($eventId, null)),
            self::PAID => $contacts->whereIn('id', self::idsForPaid(null)),
            self::ATTENDED => $contacts->whereIn('id', self::idsForAttended(null)),
            self::ENQUIRIES => $contacts->where('consent_source', CampaignContact::SOURCE_ENQUIRY),
            default => $contacts,
        };
    }

    /**
     * How many messages a segment would send, and how many were held back.
     *
     * The held back figure is shown alongside because "412 recipients" invites
     * the question "out of how many", and the honest answer is what stops
     * somebody assuming the list is broken.
     *
     * @return array{reachable: int, total: int, no_address: int, no_consent: int, suppressed: int}
     */
    public static function summarise(string $type, ?int $eventId, string $channel): array
    {
        $ids = self::candidateIds($type, $eventId);

        $scope = fn () => CampaignContact::query()->when($ids !== null, fn (Builder $q) => $q->whereIn('id', $ids));

        $column = $channel === EventTemplates::CHANNEL_SMS ? 'phone' : 'email';
        $consent = $channel === EventTemplates::CHANNEL_SMS ? 'consent_sms' : 'consent_email';

        $total = $scope()->count();
        $noAddress = $scope()->whereNull($column)->count();
        $suppressed = $scope()->whereNotNull($column)->suppressed()->count();

        $noConsent = $scope()
            ->whereNotNull($column)
            ->where($consent, false)
            ->whereNull('unsubscribed_at')
            ->whereNull('bounced_at')
            ->whereNull('complained_at')
            ->count();

        return [
            'reachable' => self::query($type, $eventId, $channel)->count(),
            'total' => $total,
            'no_address' => $noAddress,
            'no_consent' => $noConsent,
            'suppressed' => $suppressed,
        ];
    }

    /**
     * Every contact a segment touches, before consent is considered.
     *
     * Null means "no restriction", which is what the whole list segment wants.
     *
     * @return array<int, int>|null
     */
    private static function candidateIds(string $type, ?int $eventId): ?array
    {
        return match ($type) {
            self::EVENT => self::idsForEvent($eventId, null),
            self::PAID => self::idsForPaid(null),
            self::ATTENDED => self::idsForAttended(null),
            self::ENQUIRIES => CampaignContact::where('consent_source', CampaignContact::SOURCE_ENQUIRY)->pluck('id')->all(),
            default => null,
        };
    }

    /* ---------------------------------------------------------------------
     | Matching participants back to contacts
     |
     | The segments are defined by what somebody did, which lives on the
     | participant rows, while sending is done from the contact list. These join
     | the two by the identifiers they share.
     * ------------------------------------------------------------------ */

    /**
     * @return array<int, int>
     */
    private static function idsForEvent(?int $eventId, ?string $channel): array
    {
        if ($eventId === null) {
            return [];
        }

        return self::contactIdsFor(
            DB::table('event_participants')
                ->join('event_registrations', 'event_registrations.id', '=', 'event_participants.event_registration_id')
                ->where('event_registrations.event_id', $eventId)
                ->where('event_registrations.status', '!=', EventRegistration::STATUS_CANCELLED)
        );
    }

    /**
     * @return array<int, int>
     */
    private static function idsForPaid(?string $channel): array
    {
        return self::contactIdsFor(
            DB::table('event_participants')
                ->join('event_registrations', 'event_registrations.id', '=', 'event_participants.event_registration_id')
                ->where('event_registrations.payment_status', EventRegistration::PAYMENT_PAID)
        );
    }

    /**
     * @return array<int, int>
     */
    private static function idsForAttended(?string $channel): array
    {
        return self::contactIdsFor(
            DB::table('event_participants')
                ->join('event_attendances', 'event_attendances.event_participant_id', '=', 'event_participants.id')
        );
    }

    /**
     * Turn a participant query into the contact ids behind it.
     *
     * Matched on the lowercased email and on the normalised phone, because the
     * contact list stores both in a settled shape while participant rows hold them
     * as they were typed. Doing this in PHP rather than in SQL because the phone
     * normalisation is not something the database can reproduce.
     *
     * @param  \Illuminate\Database\Query\Builder  $participants
     * @return array<int, int>
     */
    private static function contactIdsFor($participants): array
    {
        $rows = $participants
            ->select('event_participants.email', 'event_participants.phone')
            ->get();

        $emails = $rows->pluck('email')->filter()->map(fn ($e) => mb_strtolower(trim($e)))->unique()->values();
        $phones = $rows->pluck('phone')->map(fn ($p) => PhoneNumber::toInternational($p))->filter()->unique()->values();

        if ($emails->isEmpty() && $phones->isEmpty()) {
            return [];
        }

        return CampaignContact::query()
            ->when($emails->isNotEmpty(), fn (Builder $q) => $q->whereIn('email', $emails->all()))
            ->when(
                $phones->isNotEmpty(),
                fn (Builder $q) => $emails->isEmpty()
                    ? $q->whereIn('phone', $phones->all())
                    : $q->orWhereIn('phone', $phones->all())
            )
            ->pluck('id')
            ->all();
    }

    /**
     * Rebuild the contact list from the participant rows and the enquiries.
     *
     * Needed because the list was introduced after people had already registered:
     * without this, every entry made before today would be invisible to a campaign.
     * Safe to run repeatedly, and it never grants consent that was not recorded.
     *
     * @return array{contacts: int, consented: int}
     */
    public static function rebuild(): array
    {
        $before = CampaignContact::count();

        DB::table('event_participants')
            ->join('event_registrations', 'event_registrations.id', '=', 'event_participants.event_registration_id')
            ->select(
                'event_participants.email',
                'event_participants.phone',
                'event_participants.full_name',
                'event_participants.marketing_consent',
                'event_participants.consent_ip',
                'event_registrations.event_id',
            )
            ->orderBy('event_participants.id')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    CampaignContact::absorb(
                        email: $row->email,
                        phone: $row->phone,
                        name: $row->full_name,
                        consented: (bool) $row->marketing_consent,
                        source: CampaignContact::SOURCE_REGISTRATION,
                        ip: $row->consent_ip,
                        eventId: $row->event_id,
                    );
                }
            });

        // Enquiries carry no consent: somebody asking a question has not asked to
        // be marketed at. They land on the list unconsented, which makes them
        // visible as a segment while keeping them unsendable until they agree.
        DB::table('contact_messages')->orderBy('id')->chunk(200, function ($rows) {
            foreach ($rows as $row) {
                CampaignContact::absorb(
                    email: $row->email,
                    phone: $row->phone ?? null,
                    name: $row->name,
                    consented: false,
                    source: CampaignContact::SOURCE_ENQUIRY,
                );
            }
        });

        return [
            'contacts' => CampaignContact::count(),
            'created' => CampaignContact::count() - $before,
            'consented' => CampaignContact::where(fn (Builder $q) => $q
                ->where('consent_email', true)
                ->orWhere('consent_sms', true))->count(),
        ];
    }

    /**
     * Events worth offering as a segment: ones that have somebody on them.
     *
     * @return array<int, string>
     */
    public static function eventOptions(): array
    {
        return Event::query()
            ->whereHas('registrations')
            ->orderByDesc('starts_at')
            ->pluck('title', 'id')
            ->all();
    }
}
