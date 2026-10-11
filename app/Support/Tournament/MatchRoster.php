<?php

namespace App\Support\Tournament;

use App\Models\EventParticipant;
use App\Models\PointRule;
use App\Models\TournamentMatch;
use Illuminate\Support\Collection;

/**
 * Who each entrant is allowed to field in one fixture, by entrant id.
 *
 * Lifted out of MatchController when the award reader arrived and needed the same
 * answer. One source of truth matters here: the reader matches a nickname off a
 * screenshot to a person, and the score form offers a dropdown of people. If those
 * two lists were built by two methods, the reader could propose somebody the
 * dropdown does not offer, and applying it would set a value that cannot be saved.
 */
class MatchRoster
{
    /**
     * Only participants on that entrant's own registration, and only those taking
     * part. A person on another team's registration cannot appear here at all.
     *
     * @return array<int, Collection<int, EventParticipant>>
     */
    public static function forMatch(TournamentMatch $match, ?PointRule $rule): array
    {
        if ($rule === null || ! $rule->tracksPlayers()) {
            return [];
        }

        $registrationIds = $match->entrants
            ->pluck('entrant.event_registration_id')
            ->filter()
            ->unique()
            ->all();

        if ($registrationIds === []) {
            return [];
        }

        // playing(), not role = 'player': a manager who also holds a place is on
        // the roster, and leaving them out here would keep them off every match
        // line-up while they are named on the entry.
        $byRegistration = EventParticipant::query()
            ->whereIn('event_registration_id', $registrationIds)
            ->playing()
            ->orderBy('full_name')
            // ign_name is selected because the pick-a-player dropdown leads with the
            // handle that appears on the results screen being copied from, and the
            // award reader matches a card's nickname against that same handle.
            ->get(['id', 'event_registration_id', 'full_name', 'ign_name', 'ign_player_id'])
            ->groupBy('event_registration_id');

        $rosters = [];

        foreach ($match->entrants as $line) {
            $registrationId = $line->entrant?->event_registration_id;

            if ($line->tournament_entrant_id === null || $registrationId === null) {
                continue;
            }

            $rosters[$line->tournament_entrant_id] = $byRegistration->get($registrationId, collect());
        }

        return $rosters;
    }
}
