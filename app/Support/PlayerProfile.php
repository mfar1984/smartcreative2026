<?php

namespace App\Support;

use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Tournament;
use App\Models\TournamentChampion;
use App\Models\TournamentEntrant;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchAward;
use App\Models\TournamentPlayerAward;
use App\Models\TournamentPlayerStanding;
use App\Models\TournamentStanding;
use App\Support\Tournament\PlayerStandingsCalculator;
use Illuminate\Support\Collection;

/**
 * One competitor, across every event they have entered.
 *
 * Registration is per event, so the same person who plays two competitions has two
 * rows in event_participants with nothing joining them. The identity card number is
 * what joins them: it is the one value a person cannot have two of, it is collected
 * on every entry, and it is already indexed.
 *
 * That number never appears in a URL and is never published whole. A profile is
 * reached through the id of the row somebody tapped, and the card is shown through
 * PublicIdentity, so the key used to assemble the page is not the key used to
 * request it.
 *
 * Nothing is shown for an appearance the ranking pages would not show either. An
 * organiser who has hidden a tournament's table has hidden this too, and a person
 * whose every appearance is hidden has no profile at all rather than an empty one.
 */
final class PlayerProfile
{
    /**
     * Assemble the profile, or null when there is nothing that may be shown.
     *
     * @return array<string, mixed>|null
     */
    public static function build(EventParticipant $participant): ?array
    {
        $people = self::samePerson($participant);

        $entrants = self::entrants($people);

        if ($entrants->isEmpty()) {
            return null;
        }

        $appearances = self::appearances($people, $entrants);

        if ($appearances === []) {
            return null;
        }

        /*
         | Newest first for reading, but the identity details are taken from the most
         | recent appearance that actually has them: somebody who left gender blank on
         | a counter entry last week should still show what they gave the year before.
         */
        $recent = collect($appearances);

        $latest = fn (string $field) => $recent
            ->map(fn (array $a) => $a['person']->{$field})
            ->first(fn ($value) => filled($value));

        $phone = $latest('phone');
        $email = $latest('email');

        return [
            'label' => self::label($recent),
            'categories' => $recent
                ->map(fn (array $a) => $a['event']?->category)
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'games' => $recent
                ->map(fn (array $a) => $a['tournament']->name)
                ->filter()
                ->unique()
                ->values()
                ->all(),

            'card' => PublicIdentity::card($participant->ic_number),
            'accounts' => self::accounts($recent),
            'gender' => $recent->map(fn (array $a) => $a['person'])
                ->first(fn (EventParticipant $p) => filled($p->gender))?->genderLabel() ?? PublicIdentity::NONE,
            'race' => $recent->map(fn (array $a) => $a['person'])
                ->first(fn (EventParticipant $p) => filled($p->race))?->raceLabel() ?? PublicIdentity::NONE,
            'phone' => PublicIdentity::phone($phone),
            'email' => PublicIdentity::email($email),

            /*
             | Whether the message form is offered at all. Without an address on file
             | there is nobody for the office to pass a message on to, and a form that
             | cannot be acted on is worse than none.
             */
            'can_message' => filled($email),

            'appearances' => $appearances,
            'podiums' => self::podiums($entrants),
            'awards' => self::awards($people),
            'match_awards' => self::matchAwards($people),
            'totals' => self::totals($appearances),
        ];
    }

    /**
     * Every Star of the Match award this person has been given, newest first.
     *
     * Only from fixtures with a result, and only where the tournament's table would
     * be public too: an organiser who hid the ranking hid what happened in its
     * matches as well.
     *
     * Each is numbered in the order it was earned, so the first award a player ever
     * received stays number one however many follow it.
     *
     * @param  Collection<int, EventParticipant>  $people
     * @return Collection<int, array{award: TournamentMatchAward, number: int}>
     */
    private static function matchAwards(Collection $people): Collection
    {
        return TournamentMatchAward::query()
            ->whereIn('event_participant_id', $people->pluck('id'))
            ->whereHas('match', fn ($query) => $query->whereIn('status', [
                TournamentMatch::STATUS_COMPLETED,
                TournamentMatch::STATUS_WALKOVER,
            ]))
            ->with([
                'match:id,tournament_id,tournament_stage_id,round,position,bracket_side,map,scheduled_at,scored_at,status',
                'match.stage:id,name',
                'tournament:id,name,event_id,status,settings,published_at',
                'tournament.event:id,title,slug',
                'entrant:id,event_registration_id',
                'entrant.registration:id,team_name,logo_path',
            ])
            ->get()
            ->filter(fn (TournamentMatchAward $award) => $award->tournament !== null && self::visible($award->tournament))
            ->sortBy(fn (TournamentMatchAward $award) => [
                ($award->match?->scheduled_at ?? $award->match?->scored_at)?->timestamp ?? 0,
                $award->match?->position ?? 0,
                $award->award_position,
            ])
            ->values()
            ->map(fn (TournamentMatchAward $award, int $index) => ['award' => $award, 'number' => $index + 1])
            ->reverse()
            ->values();
    }

    /**
     * Every registration row belonging to the same human being.
     *
     * Falls back to the single row when the card number is blank, which is possible
     * on an entry taken at a counter. One row is still a profile; it just has no
     * history behind it.
     *
     * @return Collection<int, EventParticipant>
     */
    private static function samePerson(EventParticipant $participant): Collection
    {
        return EventParticipant::query()
            ->when(
                filled($participant->ic_number),
                fn ($query) => $query->where('ic_number', $participant->ic_number),
                fn ($query) => $query->whereKey($participant->getKey()),
            )
            ->with(['registration:id,event_id,mode,team_name,logo_path,reference,status', 'registration.event'])
            ->get();
    }

    /**
     * The tournament entries behind those registrations, grouped by registration.
     *
     * Grouped rather than keyed, because one entry can be drawn into more than one
     * tournament on the same event and keying would silently drop all but the last.
     *
     * @param  Collection<int, EventParticipant>  $people
     * @return Collection<int, Collection<int, TournamentEntrant>>
     */
    private static function entrants(Collection $people): Collection
    {
        $registrationIds = $people->pluck('event_registration_id')->filter()->unique()->values();

        if ($registrationIds->isEmpty()) {
            return collect();
        }

        return TournamentEntrant::query()
            ->whereIn('event_registration_id', $registrationIds)
            ->with(['tournament.event', 'tournament.pointRule:id,name,components,track_players,player_components'])
            ->get()
            ->groupBy('event_registration_id');
    }

    /**
     * One entry per person-row and tournament pair, newest event first.
     *
     * @param  Collection<int, EventParticipant>  $people
     * @param  Collection<int, Collection<int, TournamentEntrant>>  $entrants
     * @return array<int, array<string, mixed>>
     */
    private static function appearances(Collection $people, Collection $entrants): array
    {
        $rows = [];

        foreach ($people as $person) {
            foreach ($entrants->get($person->event_registration_id) ?? [] as $entrant) {
                $tournament = $entrant->tournament;

                if ($tournament === null || ! self::visible($tournament)) {
                    continue;
                }

                $rows[] = [
                    'person' => $person,
                    'entrant' => $entrant,
                    'tournament' => $tournament,
                    'event' => $tournament->event,
                    'registration' => $person->registration,
                    'team' => $entrant->displayName(),
                    'standing' => self::teamStanding($entrant),
                    'personal' => self::personalStanding($tournament, $person),

                    /*
                     | Which personal figures this competition kept, in its own order and
                     | under its own labels. Read per appearance rather than once for the
                     | page, because two games record different things and merging them
                     | would print one game's label over the other's number.
                     */
                    'player_columns' => collect($tournament->pointRule?->player_components ?? [])
                        ->map(fn (array $component) => [
                            'key' => $component['key'],
                            'label' => $component['label'] ?? $component['key'],
                        ])
                        ->all(),
                ];
            }
        }

        usort(
            $rows,
            fn (array $a, array $b) => ($b['event']?->starts_at?->timestamp ?? 0)
                <=> ($a['event']?->starts_at?->timestamp ?? 0),
        );

        return $rows;
    }

    /**
     * The same rule the ranking and team pages apply.
     *
     * A tournament still being played is only public when the organiser left live
     * rankings on. Once the podium is published it is public regardless, because
     * publishing is the announcement.
     */
    private static function visible(Tournament $tournament): bool
    {
        return $tournament->isPublished()
            || (bool) $tournament->setting('public_rankings_live', true);
    }

    /**
     * How the team finished, taken from the furthest stage they reached.
     *
     * Ordered by the stage's own sequence rather than by id, because stages can be
     * added out of order and the last row inserted is not necessarily the last one
     * played.
     */
    private static function teamStanding(TournamentEntrant $entrant): ?TournamentStanding
    {
        return TournamentStanding::query()
            ->where('tournament_entrant_id', $entrant->id)
            ->with('stage:id,name,sequence,advance_count')
            ->get()
            ->sortByDesc(fn (TournamentStanding $standing) => $standing->stage?->sequence ?? 0)
            ->first();
    }

    /**
     * Their own figures for that tournament, where it kept any.
     *
     * The overall row only. A per-stage row would be a subset of it, and showing both
     * would read as two separate performances.
     */
    private static function personalStanding(Tournament $tournament, EventParticipant $person): ?TournamentPlayerStanding
    {
        if (! $tournament->tracksPlayers()) {
            return null;
        }

        return TournamentPlayerStanding::query()
            ->where('tournament_id', $tournament->id)
            ->where('event_participant_id', $person->id)
            ->whereNull('tournament_stage_id')
            ->first();
    }

    /**
     * What to call them.
     *
     * An in-game name if any event ever asked for one, because that is what a
     * scoreboard shows and what a team-mate would recognise. Otherwise the account
     * id. Never the name on the identity card, which is the whole point of the
     * shared helper rather than a second rule written here.
     *
     * @param  Collection<int, array<string, mixed>>  $appearances
     */
    private static function label(Collection $appearances): string
    {
        $named = $appearances
            ->map(fn (array $appearance) => $appearance['person'])
            ->first(fn (EventParticipant $person) => filled($person->ign_name));

        return PlayerStandingsCalculator::publicLabel(
            $named ?? $appearances->first()['person'],
        );
    }

    /**
     * Their game accounts, labelled by the competition each one belongs to.
     *
     * Which lines appear is decided by what the event asked for, not by what happens
     * to be stored. A battle royale asks for an in-game name and an account id; a
     * five-a-side asks for an account id and a server. Hard-coding either would put
     * the wrong label on the other one's figures.
     *
     * @param  Collection<int, array<string, mixed>>  $appearances
     * @return array<int, array<string, string>>
     */
    private static function accounts(Collection $appearances): array
    {
        $rows = [];
        $seen = [];

        foreach ($appearances as $appearance) {
            $person = $appearance['person'];
            $event = $appearance['event'];
            $game = $appearance['tournament']->name;

            if (! $event instanceof Event) {
                continue;
            }

            /*
             | The in-game name carries the account id with it, the way a scoreboard
             | shows both. Where an event never asked for a name, the id stands as its
             | own line instead of being hidden behind one that does not exist.
             */
            if ($event->asks_ign_name && filled($person->ign_name)) {
                $rows[] = [
                    'label' => 'In-Game Name',
                    'game' => $game,
                    'value' => $person->ign_name . (
                        $event->asks_player_id && filled($person->ign_player_id)
                            ? ' (' . $person->ign_player_id . ')'
                            : ''
                    ),
                ];
            } elseif ($event->asks_player_id && filled($person->ign_player_id)) {
                $rows[] = [
                    'label' => 'In-Game Player ID',
                    'game' => $game,
                    'value' => $person->ign_player_id,
                ];
            }

            if ($event->asks_server_id && filled($person->ign_server_id)) {
                $rows[] = [
                    'label' => 'Server ID',
                    'game' => $game,
                    'value' => $person->ign_server_id,
                ];
            }
        }

        // The same person entering the same game twice would otherwise list the same
        // account on two lines.
        return array_values(array_filter($rows, function (array $row) use (&$seen) {
            $key = $row['label'] . '|' . $row['game'] . '|' . $row['value'];

            if (isset($seen[$key])) {
                return false;
            }

            $seen[$key] = true;

            return true;
        }));
    }

    /**
     * Podium places, read from the frozen champions.
     *
     * Announced results only. A team sitting first in a half-played table has not won
     * anything yet, and this is the section a visitor reads as a record.
     *
     * @param  Collection<int, Collection<int, TournamentEntrant>>  $entrants
     * @return Collection<int, TournamentChampion>
     */
    private static function podiums(Collection $entrants): Collection
    {
        $ids = $entrants->flatten()->pluck('id');

        if ($ids->isEmpty()) {
            return collect();
        }

        return TournamentChampion::query()
            ->whereIn('tournament_entrant_id', $ids)
            ->whereNotNull('published_at')
            ->with(['tournament:id,name,event_id,published_at', 'tournament.event:id,title,slug,starts_at'])
            ->orderBy('rank')
            ->get();
    }

    /**
     * Individual awards they have been given, such as most valuable player.
     *
     * Keyed on the person rather than on the team, so an award follows them even
     * where they played for somebody else the following year.
     *
     * @param  Collection<int, EventParticipant>  $people
     * @return Collection<int, TournamentPlayerAward>
     */
    private static function awards(Collection $people): Collection
    {
        return TournamentPlayerAward::query()
            ->whereIn('event_participant_id', $people->pluck('id'))
            ->whereNotNull('published_at')
            ->with(['tournament:id,name,event_id', 'tournament.event:id,title,slug,starts_at'])
            ->orderBy('rank')
            ->get();
    }

    /**
     * The headline figures, summed from the appearances on screen.
     *
     * Counted from the same rows the table below renders, so the two cannot disagree.
     * Best finish is withheld until something has been played, because before that
     * every entrant is level on nil and the ranking reads them all as first.
     *
     * @param  array<int, array<string, mixed>>  $appearances
     * @return array<string, int|float|null>
     */
    private static function totals(array $appearances): array
    {
        $rows = collect($appearances);

        $played = $rows->sum(fn (array $a) => (int) ($a['standing']?->played ?? 0));

        return [
            'events' => $rows->map(fn (array $a) => $a['event']?->id)->filter()->unique()->count(),
            'teams' => $rows->map(fn (array $a) => $a['registration']?->id)->filter()->unique()->count(),
            'matches' => $played,
            'points' => $rows->sum(fn (array $a) => (float) ($a['standing']?->total_points ?? 0)),

            /*
             | The player's own points, where any game they entered kept them. This is
             | the figure the heading shows on a page about one person; the squad's
             | points stay in the career rows, labelled as the squad's.
             */
            'own_points' => $rows->sum(fn (array $a) => (float) ($a['personal']?->total_points ?? 0)),
            'has_own' => $rows->contains(fn (array $a) => $a['personal'] !== null),
            'best' => $played > 0
                ? $rows->map(fn (array $a) => $a['standing']?->rank)->filter()->min()
                : null,
        ];
    }
}
