<?php

namespace App\Support\Tournament;

use App\Models\EventRegistration;
use App\Models\Tournament;
use App\Models\TournamentEntrant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns an event's registrations into tournament entrants.
 *
 * The point of importing rather than typing is that the squads are already in the
 * system with their names, logos and players. Retyping them would produce a second
 * spelling of every team.
 *
 * Only confirmed and paid entries are taken. A team that has not paid may still be
 * added, but deliberately and one at a time, so nobody ends up in a bracket by
 * accident and has to be removed from it later.
 */
final class EntrantImporter
{
    /**
     * Every registration on the event that could enter, and why the rest cannot.
     *
     * @return array{eligible: Collection<int, EventRegistration>,
     *               excluded: Collection<int, EventRegistration>,
     *               already: Collection<int, EventRegistration>,
     *               reasons: array<string, int>}
     */
    public function survey(Tournament $tournament): array
    {
        $taken = $tournament->entrants()->pluck('event_registration_id')->all();

        $registrations = EventRegistration::query()
            ->where('event_id', $tournament->event_id)
            ->with(['participants'])
            ->orderBy('id')
            ->get();

        $eligible = collect();
        $excluded = collect();
        $already = collect();
        $reasons = [];

        foreach ($registrations as $registration) {
            if (in_array($registration->id, $taken, true)) {
                $already->push($registration);

                continue;
            }

            $reason = $this->ineligibleReason($registration);

            if ($reason === null) {
                $eligible->push($registration);

                continue;
            }

            $excluded->push($registration);
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
        }

        return [
            'eligible' => $eligible,
            'excluded' => $excluded,
            'already' => $already,
            'reasons' => $reasons,
        ];
    }

    /**
     * Import every eligible registration.
     *
     * Seeds are left null. Seeding is its own step with its own decision, and
     * guessing one here would look like a draw nobody made.
     *
     * @return array{imported: int, skipped: int, reasons: array<string, int>}
     */
    public function import(Tournament $tournament): array
    {
        $survey = $this->survey($tournament);

        if ($survey['eligible']->isEmpty()) {
            return [
                'imported' => 0,
                'skipped' => $survey['excluded']->count(),
                'reasons' => $survey['reasons'],
            ];
        }

        DB::transaction(function () use ($tournament, $survey) {
            foreach ($survey['eligible'] as $registration) {
                $tournament->entrants()->create([
                    'event_registration_id' => $registration->id,
                    'status' => TournamentEntrant::STATUS_ACTIVE,
                    'added_by_hand' => false,
                ]);
            }
        });

        return [
            'imported' => $survey['eligible']->count(),
            'skipped' => $survey['excluded']->count(),
            'reasons' => $survey['reasons'],
        ];
    }

    /**
     * Add one registration the operator picked out, whatever its payment state.
     *
     * Recorded as added by hand so a later question about why an unpaid team is in
     * the bracket has an answer on the row itself.
     */
    public function addByHand(Tournament $tournament, EventRegistration $registration): TournamentEntrant
    {
        return $tournament->entrants()->create([
            'event_registration_id' => $registration->id,
            'status' => TournamentEntrant::STATUS_ACTIVE,
            'added_by_hand' => true,
        ]);
    }

    /**
     * Why a registration cannot be imported, or null when it can.
     */
    private function ineligibleReason(EventRegistration $registration): ?string
    {
        return match (true) {
            $registration->status === EventRegistration::STATUS_CANCELLED => 'Cancelled',
            $registration->status === EventRegistration::STATUS_WAITLISTED => 'On the waiting list',
            $registration->status !== EventRegistration::STATUS_CONFIRMED => 'Not confirmed yet',
            $registration->payment_status !== EventRegistration::PAYMENT_PAID => 'Not paid',
            default => null,
        };
    }
}
