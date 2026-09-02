<?php

namespace App\Http\Controllers\Admin\Event;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CheckInRequest;
use App\Http\Requests\Admin\RemovePlayerRequest;
use App\Http\Requests\Admin\SwapPlayerRequest;
use App\Models\Event;
use App\Models\EventAttendance;
use App\Models\EventParticipant;
use App\Models\EventParticipantChange;
use App\Models\EventRegistration;
use App\Services\AdminLogger;
use App\Services\Messaging\StaffAlerts;
use App\Support\ParticipantOptions;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    /**
     * Tab slug => label, icon and blurb.
     *
     * Attendance is the counter itself: search for who is at the desk, check the
     * card, let them in. The other three read from what that produced.
     */
    public const TABS = [
        'attendance' => [
            'label' => 'Attendance',
            'icon' => 'clipboard',
            'description' => 'Find a team or a person at the desk, check their identity card, and let them in.',
        ],
        'player-change' => [
            'label' => 'Player Change',
            'icon' => 'users',
            'description' => 'Substitutions, transfers between teams, and players taken off an entry at the counter.',
        ],
        'present' => [
            'label' => 'Present',
            'icon' => 'shield',
            'description' => 'Everyone checked in, most recent first.',
        ],
        'absent' => [
            'label' => 'Absent',
            'icon' => 'power',
            'description' => 'Named on a registration but not yet checked in. Cancelled entries are left out.',
        ],
    ];

    private const PER_PAGE = 20;

    /** Search results shown at the counter before one is opened. */
    private const SEARCH_LIMIT = 12;

    public function __construct(private readonly StaffAlerts $alerts)
    {
    }

    public function index(Request $request)
    {
        $tab = $this->resolveTab($request->query('tab'));

        $search = trim((string) $request->query('q'));
        $eventId = trim((string) $request->query('event'));

        $data = [
            'tabs' => $this->tabsWithCounts(),
            'activeTab' => $tab,
            'definition' => self::TABS[$tab],
            'events' => Event::query()->whereHas('registrations')->orderByDesc('starts_at')->pluck('title', 'id')->all(),
            'search' => $search,
            'eventId' => $eventId,
            'isFiltered' => $search !== '' || $eventId !== '',
            'canUpdate' => $request->user()->hasPermission('attendance.update'),
            'canRemove' => $request->user()->hasPermission('attendance.remove-player'),
            'genders' => ParticipantOptions::GENDERS,
            'races' => ParticipantOptions::RACES,
        ];

        return view('admin.event.attendance', $data + match ($tab) {
            'player-change' => $this->playerChangeData($search, $eventId),
            'present' => $this->presentData($search, $eventId),
            'absent' => $this->absentData($search, $eventId),
            default => $this->counterData($request, $search, $eventId),
        });
    }

    /**
     * Record an arrival.
     */
    public function checkIn(CheckInRequest $request, EventParticipant $participant)
    {
        $participant->loadMissing('registration');
        $registration = $participant->registration;

        if ($registration === null) {
            return back()->withErrors(['participant' => 'That person is no longer attached to a registration.']);
        }

        // updateOrCreate rather than create: the unique index would otherwise
        // turn a double click into a driver error.
        $attendance = EventAttendance::updateOrCreate(
            ['event_participant_id' => $participant->id],
            [
                'event_id' => $registration->event_id,
                'event_registration_id' => $registration->id,
                'checked_in_at' => now(),
                'checked_in_by' => $request->user()->id,
                'ic_verified' => $request->icVerified(),
                'notes' => $request->notes(),
            ],
        );

        AdminLogger::activity(
            'attendance.check-in',
            sprintf(
                'Checked in %s (%s) for %s.',
                $participant->full_name,
                $request->icVerified() ? 'card checked' : 'no card produced',
                $registration->reference,
            ),
        );

        AdminLogger::audit($attendance, 'created', null, [
            'participant' => $participant->full_name,
            'ic_number' => $participant->ic_number,
            'reference' => $registration->reference,
            'ic_verified' => $request->icVerified(),
        ]);

        return $this->backToCounter($registration, sprintf(
            '%s checked in%s.',
            $participant->full_name,
            $request->icVerified() ? '' : ' without an identity card',
        ));
    }

    /**
     * Undo an arrival recorded by mistake.
     */
    public function undoCheckIn(Request $request, EventParticipant $participant)
    {
        $participant->loadMissing(['registration', 'attendance']);
        $attendance = $participant->attendance;

        if ($attendance === null) {
            return back()->withErrors(['participant' => sprintf('%s is not checked in.', $participant->full_name)]);
        }

        AdminLogger::audit($attendance, 'deleted', [
            'participant' => $participant->full_name,
            'checked_in_at' => $attendance->checked_in_at?->toDateTimeString(),
        ], null);

        $attendance->delete();

        AdminLogger::activity(
            'attendance.undo',
            sprintf('Undid the check-in for %s on %s.', $participant->full_name, $participant->registration?->reference ?? 'an entry'),
        );

        return $this->backToCounter($participant->registration, sprintf('Check-in for %s undone.', $participant->full_name));
    }

    /**
     * Hand a squad place to a different person, and record that it happened.
     */
    public function swapPlayer(SwapPlayerRequest $request, EventParticipant $participant)
    {
        $participant->loadMissing('registration');
        $registration = $participant->registration;

        if ($registration === null) {
            return back()->withErrors(['participant' => 'That place is no longer attached to a registration.']);
        }

        // Resolved before the write, because the row it points at is about to be
        // removed when this turns out to be a transfer.
        $incoming = $request->existingEntry();
        $source = $incoming?->registration;

        // The audit row and the rewrite go together: a swap recorded without the
        // change, or a change with no record of it, would both be wrong. On a
        // transfer the other team's place is vacated in the same transaction, so
        // the person can never exist on two entries at once.
        DB::transaction(function () use ($request, $participant, $registration, $incoming, $source) {
            $before = $participant->only([
                'role', 'full_name', 'ic_number', 'ign_player_id', 'ign_server_id',
                'address_line_1', 'address_line_2',
                'postcode', 'city', 'state', 'country', 'phone', 'email', 'gender',
                'race', 'date_of_birth', 'emergency_contact_name', 'emergency_contact_phone',
            ]);

            $previousName = $participant->full_name;
            $previousCard = $participant->ic_number;

            $participant->fill($request->participantAttributes())->save();

            if ($incoming !== null) {
                // They cannot hold two places in one event, so the one they came
                // from goes. Their old team is now a player short and can fill the
                // gap with a substitution of its own.
                $this->releaseSeat($source, 1);
                $incoming->delete();
            }

            EventParticipantChange::create([
                'event_id' => $registration->event_id,
                'event_registration_id' => $registration->id,
                'from_registration_id' => $source?->id,
                'event_participant_id' => $participant->id,
                'type' => $incoming !== null
                    ? EventParticipantChange::TYPE_TRANSFER
                    : EventParticipantChange::TYPE_SWAP,
                'previous_name' => $previousName,
                'previous_ic' => $previousCard,
                'new_name' => $participant->full_name,
                'new_ic' => $participant->ic_number,
                'details_before' => $before,
                'details_after' => $participant->only(array_keys($before)),
                'reason' => $request->reason(),
                'changed_by' => $request->user()->id,
            ]);
        });

        if ($incoming !== null) {
            AdminLogger::activity(
                'attendance.player-transfer',
                sprintf(
                    'Transferred %s (%s) from %s to %s.',
                    $participant->full_name,
                    $participant->ic_number,
                    $source?->reference ?? 'another entry',
                    $registration->reference,
                ),
            );

            $this->alerts->playerTransferred($participant, $registration, $source);

            return $this->backToCounter($registration, sprintf(
                '%s transferred in from %s, which is now a player short. Recorded under Player Change.',
                $participant->full_name,
                $source?->displayName() ?? 'their previous team',
            ));
        }

        AdminLogger::activity(
            'attendance.player-change',
            sprintf(
                'Replaced a player on %s with %s (%s).',
                $registration->reference,
                $participant->full_name,
                $participant->ic_number,
            ),
        );

        return $this->backToCounter($registration, sprintf(
            'Place handed to %s. The change is recorded under Player Change.',
            $participant->full_name,
        ));
    }

    /**
     * Take a player off an entry, because they are not coming.
     *
     * Unlike a substitution nobody arrives in their place: the squad simply plays
     * a player short. Their row goes, which takes their arrival record with it
     * through the cascade, so the audit row written here is the only surviving
     * trace that they were ever named.
     */
    public function removePlayer(RemovePlayerRequest $request, EventParticipant $participant)
    {
        $participant->loadMissing(['registration']);
        $registration = $participant->registration;

        if ($registration === null) {
            return back()->withErrors(['participant' => 'That person is no longer attached to a registration.']);
        }

        $name = $participant->full_name;
        $card = $participant->ic_number;

        $before = $participant->only([
            'role', 'full_name', 'ic_number', 'ign_player_id', 'ign_server_id',
            'address_line_1', 'address_line_2',
            'postcode', 'city', 'state', 'country', 'phone', 'email', 'gender',
            'race', 'date_of_birth', 'emergency_contact_name', 'emergency_contact_phone',
        ]);

        DB::transaction(function () use ($request, $participant, $registration, $before, $name, $card) {
            // Written first: once the row is gone its id cannot be recorded, and
            // the audit is the only thing that will say this person existed.
            EventParticipantChange::create([
                'event_id' => $registration->event_id,
                'event_registration_id' => $registration->id,
                'event_participant_id' => $participant->id,
                'type' => EventParticipantChange::TYPE_REMOVED,
                'previous_name' => $name,
                'previous_ic' => $card,
                // Nobody arrives in their place, which is the difference between
                // this and a substitution.
                'new_name' => null,
                'new_ic' => null,
                'details_before' => $before,
                'details_after' => null,
                'reason' => $request->reason(),
                'changed_by' => $request->user()->id,
            ]);

            // The place is genuinely free now, so the event can sell it again.
            $this->releaseSeat($registration, 1);

            $participant->delete();
        });

        AdminLogger::activity(
            'attendance.player-removed',
            sprintf('Removed %s (%s) from %s at the counter.', $name, $card, $registration->reference),
        );

        $this->alerts->playerRemoved($registration, $name, $card, $request->reason());

        $shortfall = $this->playerShortfall($registration);

        return $this->backToCounter($registration, sprintf(
            '%s removed from %s and the seat released.%s',
            $name,
            $registration->displayName(),
            $shortfall === null ? '' : ' ' . $shortfall,
        ));
    }

    /* ---------------------------------------------------------------------
     | Tab data
     * ------------------------------------------------------------------ */

    /**
     * The counter: a search, and the entry currently open at the desk.
     *
     * @return array<string, mixed>
     */
    private function counterData(Request $request, string $search, string $eventId): array
    {
        $openId = $request->query('registration');

        $open = null;

        if (filled($openId)) {
            $open = EventRegistration::query()
                ->with($this->counterRelations())
                ->whereKey($openId)
                ->first();
        }

        // Searching is only worth doing once something has been typed, so an
        // empty counter shows guidance instead of the whole database.
        $results = collect();

        if ($search !== '') {
            $results = EventRegistration::query()
                ->with(['event', 'participants.attendance'])
                ->when($eventId !== '', fn (Builder $query) => $query->where('event_id', $eventId))
                ->where(function (Builder $query) use ($search) {
                    $query->where('team_name', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%")
                        ->orWhereHas('participants', fn (Builder $people) => $people
                            ->where('full_name', 'like', "%{$search}%")
                            ->orWhere('ic_number', 'like', "%{$search}%"));
                })
                ->orderBy('team_name')
                ->limit(self::SEARCH_LIMIT)
                ->get();

            // One hit and nothing already open: go straight to it, which is what
            // a counter wants when they scan or type a full card number.
            if ($open === null && $results->count() === 1) {
                $open = $results->first()->load($this->counterRelations());
            }
        }

        // Point each person back at the entry they were loaded from. Without this
        // the removal rule, which needs to know how many are left, would fire a
        // count query for every player on screen.
        if ($open !== null) {
            foreach ($open->participants as $person) {
                $person->setRelation('registration', $open);
            }
        }

        return [
            'results' => $results,
            'open' => $open,
        ];
    }

    /**
     * What the counter panel needs, with the manager listed first.
     *
     * The manager is the person standing at the desk with the squad behind them,
     * so they belong at the top rather than wherever their row happened to be
     * inserted. CASE rather than FIELD() so this also runs on SQLite.
     *
     * @return array<string, mixed>
     */
    private function counterRelations(): array
    {
        return [
            'event',
            'participants' => fn ($query) => $query
                ->orderByRaw("CASE WHEN role = '" . ParticipantOptions::ROLE_MANAGER . "' THEN 0 ELSE 1 END")
                ->orderBy('id'),
            'participants.attendance.recordedBy',
            'participants.changes',
            'addonLines',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function playerChangeData(string $search, string $eventId): array
    {
        return [
            'changes' => EventParticipantChange::query()
                ->with(['event', 'registration', 'fromRegistration', 'changedBy'])
                ->when($eventId !== '', fn (Builder $query) => $query->where('event_id', $eventId))
                ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($search) {
                    $inner->where('previous_name', 'like', "%{$search}%")
                        ->orWhere('new_name', 'like', "%{$search}%")
                        ->orWhere('previous_ic', 'like', "%{$search}%")
                        ->orWhere('new_ic', 'like', "%{$search}%")
                        ->orWhereHas('registration', fn (Builder $reg) => $reg
                            ->where('team_name', 'like', "%{$search}%")
                            ->orWhere('reference', 'like', "%{$search}%"));
                }))
                ->latest()
                ->paginate(self::PER_PAGE)
                ->withQueryString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentData(string $search, string $eventId): array
    {
        return [
            'present' => EventAttendance::query()
                ->with(['event', 'registration', 'participant', 'recordedBy'])
                ->when($eventId !== '', fn (Builder $query) => $query->where('event_id', $eventId))
                ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($search) {
                    $inner->whereHas('participant', fn (Builder $person) => $person
                        ->where('full_name', 'like', "%{$search}%")
                        ->orWhere('ic_number', 'like', "%{$search}%"))
                        ->orWhereHas('registration', fn (Builder $reg) => $reg
                            ->where('team_name', 'like', "%{$search}%")
                            ->orWhere('reference', 'like', "%{$search}%"));
                }))
                ->latest('checked_in_at')
                ->paginate(self::PER_PAGE)
                ->withQueryString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function absentData(string $search, string $eventId): array
    {
        return [
            'absent' => $this->absentQuery($eventId)
                ->with(['registration.event'])
                ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($search) {
                    $inner->where('full_name', 'like', "%{$search}%")
                        ->orWhere('ic_number', 'like', "%{$search}%")
                        ->orWhereHas('registration', fn (Builder $reg) => $reg
                            ->where('team_name', 'like', "%{$search}%")
                            ->orWhere('reference', 'like', "%{$search}%"));
                }))
                ->orderBy('full_name')
                ->paginate(self::PER_PAGE)
                ->withQueryString(),
        ];
    }

    /**
     * Everyone expected who has not arrived.
     *
     * Cancelled registrations are excluded: nobody is waiting for them, so
     * listing them as absent would overstate the gap.
     */
    private function absentQuery(string $eventId): Builder
    {
        return EventParticipant::query()
            ->whereDoesntHave('attendance')
            ->whereHas('registration', function (Builder $query) use ($eventId) {
                $query->where('status', '!=', EventRegistration::STATUS_CANCELLED)
                    ->when($eventId !== '', fn (Builder $inner) => $inner->where('event_id', $eventId));
            });
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * Give a place back to the event.
     *
     * Seats are counted per head, so a person leaving frees one. Locked and
     * clamped the same way the public form takes them, so two counters working at
     * once cannot drive the count below zero.
     */
    private function releaseSeat(?EventRegistration $registration, int $count): void
    {
        if ($registration?->event_id === null || $count < 1) {
            return;
        }

        $event = Event::query()->whereKey($registration->event_id)->lockForUpdate()->first();

        if ($event === null) {
            return;
        }

        $event->seats_taken = max(0, $event->seats_taken - $count);
        $event->save();
    }

    /**
     * A note about a squad now being under the event's minimum, or null.
     *
     * Stated rather than enforced: a counter cannot conjure up a player, so
     * refusing the removal would leave the record wrong as well as the squad
     * short. Whoever runs the tournament decides what to do about it.
     */
    private function playerShortfall(EventRegistration $registration): ?string
    {
        $minimum = $registration->event?->min_players;

        if ($minimum === null || $minimum < 1) {
            return null;
        }

        // Counted with playing(), so a manager who also plays keeps the squad above
        // its minimum rather than the count reading one short.
        $remaining = $registration->participants()
            ->playing()
            ->count();

        if ($remaining >= $minimum) {
            return null;
        }

        return sprintf(
            'Note: %s now has %d player(s), below this event\'s minimum of %d.',
            $registration->displayName(),
            $remaining,
            $minimum,
        );
    }

    /**
     * Back to the counter with the same entry still open, so a squad can be
     * checked in one player after another without searching again.
     */
    private function backToCounter(?EventRegistration $registration, string $message)
    {
        return redirect()
            ->route('admin.event.attendance', array_filter([
                'tab' => 'attendance',
                'registration' => $registration?->id,
            ]))
            ->with('status', $message);
    }

    private function resolveTab(?string $tab): string
    {
        return array_key_exists((string) $tab, self::TABS) ? (string) $tab : 'attendance';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function tabsWithCounts(): array
    {
        $counts = [
            'attendance' => null,
            'player-change' => EventParticipantChange::query()->count(),
            'present' => EventAttendance::query()->count(),
            'absent' => $this->absentQuery('')->count(),
        ];

        $tabs = [];

        foreach (self::TABS as $slug => $definition) {
            // The counter tab is a workstation, not a list, so a count on it
            // would be counting nothing in particular.
            $tabs[$slug] = $counts[$slug] === null
                ? $definition
                : $definition + ['count' => $counts[$slug]];
        }

        return $tabs;
    }
}
