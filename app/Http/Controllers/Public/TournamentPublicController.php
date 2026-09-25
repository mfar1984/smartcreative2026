<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Tournament;
use App\Models\TournamentChampion;
use App\Models\TournamentEntrant;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchEntrant;
use App\Models\TournamentPlayerAward;
use App\Models\TournamentStage;

/**
 * What the public sees.
 *
 * Two pages, and the difference between them matters.
 *
 * The Hall of Fame reads tournament_champions, which is frozen. It shows what was
 * announced, and it does not move when a score is corrected.
 *
 * The event ranking reads tournament_standings, which is live. It moves as results
 * come in, and it says how far through the tournament is so a visitor does not take a
 * half-played table for a final result.
 *
 * The archive lists the tournaments that are over, whether or not a podium was ever
 * announced, and counts them from the match rows rather than storing a summary.
 *
 * Neither page shows anything beyond a competitor's name and the figures making up
 * their score. No telephone number, no identity card, no email.
 */
class TournamentPublicController extends Controller
{
    /**
     * Champions, newest first, grouped by year.
     */
    public function hallOfFame()
    {
        $champions = TournamentChampion::query()
            ->with(['tournament:id,name,event_id,published_at', 'tournament.event:id,title,slug,starts_at'])
            ->orderByDesc('published_at')
            ->orderBy('rank')
            ->get()
            ->groupBy(fn (TournamentChampion $champion) => $champion->tournament?->id)
            ->map(fn ($rows) => [
                'tournament' => $rows->first()->tournament,
                'podium' => $rows->sortBy('rank')->values(),
            ])
            ->values()
            ->groupBy(fn (array $entry) => $entry['tournament']?->event?->starts_at?->format('Y')
                ?? $entry['tournament']?->published_at?->format('Y')
                ?? 'Undated')
            ->sortKeysDesc();

        /*
         | Published individual awards, keyed by tournament so the page can show them
         | under the podium they belong to. Frozen at publish for the same reason the
         | champions are: an announced MVP must not change when a match is corrected.
         |
         | Read separately from the champions, so a tournament may appear with a podium
         | and no awards, or with awards and no podium.
         */
        $awards = TournamentPlayerAward::query()
            ->whereNotNull('published_at')
            ->orderBy('award_key')
            ->orderBy('rank')
            ->get([
                'id', 'tournament_id', 'award_key', 'award_label', 'rank',
                'display_name', 'ign', 'entrant_name', 'total_points',
            ])
            ->groupBy('tournament_id');

        return view('pages.hall-of-fame', [
            'years' => $champions,
            'awards' => $awards,
        ]);
    }

    /**
     * Every tournament that is over, newest first.
     *
     * The third of the three results pages, and the one that answers "what have you
     * run before". The Hall of Fame shows three names per tournament and only once a
     * podium has been announced. This shows every finished tournament, announced or
     * not, with the size of it and a way into the full table.
     *
     * Keyed on the tournament being finished rather than on the event's dates. An
     * organiser closing a tournament is a decision; a date passing is not, and keying
     * on the date would file a tournament still being played under "past".
     *
     * Every figure on this page is counted from rows that already exist. Nothing here
     * is entered a second time, so the archive cannot disagree with the tournament it
     * describes.
     */
    public function archive()
    {
        $tournaments = Tournament::query()
            ->whereIn('status', [Tournament::STATUS_COMPLETED, Tournament::STATUS_PUBLISHED])
            // No draw means no fixtures and no standings, so the entry would open onto
            // an empty table.
            ->whereHas('stages', fn ($query) => $query->whereNotNull('drawn_at'))
            ->whereHas('event')
            ->with([
                'event:id,slug,title,category,location,starts_at,ends_at',
                'champions',
                'pointRule:id,name,track_players',
            ])
            ->withCount([
                'entrants',
                'matches as played_count' => fn ($query) => $query->whereIn('status', [
                    TournamentMatch::STATUS_COMPLETED,
                    TournamentMatch::STATUS_WALKOVER,
                ]),
                /*
                 | Overall player rows only, which is why the stage filter is here: a
                 | tournament that keeps per-stage player tables as well would otherwise
                 | count the same person once per stage.
                 */
                'playerStandings as players_count' => fn ($query) => $query->whereNull('tournament_stage_id'),
            ])
            ->get()
            ->sortByDesc(fn (Tournament $tournament) => [
                $tournament->event?->ends_at?->timestamp ?? 0,
                $tournament->id,
            ])
            ->values();

        $entries = $tournaments->map(function (Tournament $tournament) {
            /*
             | The winner is read from the frozen champions and nowhere else.
             |
             | Live standings would also name a leader, and for a finished tournament
             | that leader is almost always the winner. Almost is the problem: until an
             | organiser publishes, no result has been announced, and a page calling
             | somebody champion before that is putting words in their mouth.
             */
            $champion = $tournament->champions
                ->whereNotNull('published_at')
                ->firstWhere('rank', 1);

            /*
             | Whether the full table is reachable, decided by the same rules the
             | ranking page itself applies. Linking without checking produced a card
             | leading to a page that had quietly withheld everything.
             */
            $open = $tournament->isPublished()
                || (bool) $tournament->setting('public_rankings_live', true);

            return [
                'tournament' => $tournament,
                'event' => $tournament->event,
                'champion' => $champion,
                'is_announced' => $champion !== null,
                'teams' => (int) $tournament->entrants_count,
                'played' => (int) $tournament->played_count,
                'players' => $tournament->tracksPlayers() ? (int) $tournament->players_count : 0,
                'standings_url' => $open && $tournament->event
                    ? route('events.ranking', $tournament->event->slug)
                    : null,
            ];
        });

        return view('pages.archive', [
            'years' => $entries->groupBy(
                fn (array $entry) => $entry['event']?->starts_at?->format('Y')
                    ?? $entry['tournament']->published_at?->format('Y')
                    ?? 'Undated',
            )->sortKeysDesc(),
            'totals' => [
                'tournaments' => $entries->count(),
                'teams' => $entries->sum('teams'),
                'matches' => $entries->sum('played'),
            ],
        ]);
    }

    /**
     * Live standings for one event's tournaments.
     */
    public function ranking(string $slug)
    {
        $event = Event::where('slug', $slug)->firstOrFail();

        $tournaments = Tournament::query()
            ->where('event_id', $event->id)
            ->whereIn('status', [
                Tournament::STATUS_ONGOING,
                Tournament::STATUS_COMPLETED,
                Tournament::STATUS_PUBLISHED,
            ])
            ->with(['pointRule:id,name,components,track_players,player_components', 'champions'])
            ->orderBy('id')
            ->get();

        $boards = [];

        foreach ($tournaments as $tournament) {
            /*
             | Whether a tournament still being played is shown at all is the organiser's
             | setting. When it is off, a visitor sees nothing until the podium is
             | published, which is what an organiser who does not want a half-finished
             | table quoted back at them would choose.
             */
            $live = (bool) $tournament->setting('public_rankings_live', true);

            if (! $live && ! $tournament->isPublished()) {
                continue;
            }

            /*
             | The stage being played, not the last one in the list.
             |
             | Taking the last stage showed a visitor the Grand Final's table while the
             | qualifiers were being played, which is empty, because the final has not
             | been drawn yet and nobody has qualified for it. What somebody opening
             | this page wants is whatever is happening now: the earliest drawn stage
             | that still has a fixture outstanding, falling back to the last drawn one
             | once everything has been played.
             */
            $drawn = $tournament->stages()
                ->whereNotNull('drawn_at')
                ->orderBy('sequence')
                ->get();

            $finalStage = $drawn->first(fn (TournamentStage $stage) => ! $stage->isPlayedOut())
                ?? $drawn->last();

            if ($finalStage === null) {
                continue;
            }

            /*
             | Counted over the stage on screen rather than the whole tournament, so
             | "match 2 of 5" refers to the table underneath it. Counting every fixture
             | in the tournament made the qualifiers read as two of ten while five were
             | all that had been drawn.
             */
            $total = $finalStage->matches()->count();
            $done = $finalStage->matches()->whereIn('status', [
                TournamentMatch::STATUS_COMPLETED,
                TournamentMatch::STATUS_WALKOVER,
            ])->count();

            $nextFixture = $finalStage->matches()
                ->whereIn('status', [TournamentMatch::STATUS_SCHEDULED, TournamentMatch::STATUS_AWAITING])
                ->orderBy('scheduled_at')
                ->orderBy('position')
                ->first();

            $boards[] = [
                'tournament' => $tournament,
                'stage' => $finalStage,

                // Where the cut is, so the page can draw the line somebody has to
                // finish above. Zero on a last stage, where there is nothing to
                // qualify for.
                'advance_count' => (int) $finalStage->advance_count,

                'next_fixture' => $nextFixture,
                'columns' => collect($tournament->pointRule?->components ?? [])
                    ->map(fn (array $c) => [
                        'key' => $c['key'],
                        'label' => $c['label'] ?? $c['key'],
                        'counted' => in_array($c['type'] ?? '', ['per_unit', 'bonus'], true),
                    ])
                    ->all(),
                'groups' => $tournament->standings()
                    ->where('tournament_stage_id', $finalStage->id)
                    ->with(['entrant.registration:id,team_name,reference', 'group:id,name'])
                    ->orderBy('rank')
                    ->get()
                    ->groupBy(fn ($s) => $s->group?->name ?? 'Overall'),
                'matches_done' => $done,
                'matches_total' => $total,
                'is_final' => $tournament->isPublished(),

                /*
                 | The player leaderboard, a second table under the team one. Only the
                 | name, the in-game name, the team and the figures behind the score are
                 | selected. No identity card number, address, telephone, email or date
                 | of birth ever reaches this array, let alone the page.
                 */
                'player_columns' => collect($tournament->pointRule?->player_components ?? [])
                    ->map(fn (array $c) => [
                        'key' => $c['key'],
                        'label' => $c['label'] ?? $c['key'],
                        'counted' => ($c['type'] ?? '') === 'per_unit',
                    ])
                    ->all(),
                'players' => $tournament->tracksPlayers()
                    ? $tournament->playerStandings()
                        ->whereNull('tournament_stage_id')
                        ->with('entrant.registration:id,team_name')
                        ->orderBy('rank')
                        ->limit(20)
                        ->get([
                            'id', 'tournament_id', 'tournament_entrant_id', 'display_name',
                            'ign', 'matches_played', 'component_totals', 'component_counts',
                            'total_points', 'rank', 'entrant_is_disqualified',
                        ])
                    : collect(),
            ];
        }

        return view('pages.event-ranking', [
            'event' => $event,
            'boards' => $boards,
        ]);
    }

    /**
     * One team's record in one event.
     *
     * Reached by tapping a name on the ranking table. Everything here is read from the
     * match rows that already exist, so it fills itself in as the tournament is
     * scored rather than needing anything entered twice.
     *
     * Keyed on the registration rather than the team name, because a name is typed by
     * whoever registered and two squads may well choose the same one.
     */
    public function team(string $slug, EventRegistration $registration)
    {
        $event = Event::where('slug', $slug)->firstOrFail();

        // The team has to belong to the event in the URL, or the page would present
        // one event's squad as though it played in another.
        abort_if((int) $registration->event_id !== (int) $event->id, 404);

        $registration->load(['participants', 'event']);

        $entrant = TournamentEntrant::query()
            ->where('event_registration_id', $registration->id)
            ->with(['tournament.pointRule', 'tournament.event'])
            ->first();

        // Registered, but never entered into a tournament. Nothing to show and no
        // reason to pretend otherwise.
        abort_if($entrant === null, 404);

        $tournament = $entrant->tournament;

        abort_if($tournament === null, 404);

        /*
         | Hidden for the same reason the ranking is: an organiser who does not want a
         | half-played table quoted back at them does not want one team's running total
         | quoted either.
         */
        abort_if(
            ! (bool) $tournament->setting('public_rankings_live', true) && ! $tournament->isPublished(),
            404,
        );

        $columns = collect($tournament->pointRule?->components ?? [])
            ->map(fn (array $component) => [
                'key' => $component['key'],
                'label' => $component['label'] ?? $component['key'],
                'counted' => in_array($component['type'] ?? '', ['per_unit', 'bonus'], true),
            ])
            ->all();

        /*
         | Every fixture this team has a settled result in, oldest first, so it reads as
         | the story of the day rather than a leaderboard.
         */
        $lines = TournamentMatchEntrant::query()
            ->where('tournament_entrant_id', $entrant->id)
            ->whereHas('match', fn ($query) => $query->whereIn('status', [
                TournamentMatch::STATUS_COMPLETED,
                TournamentMatch::STATUS_WALKOVER,
            ]))
            ->with(['match:id,position,round,map,scheduled_at,status,winner_entrant_id,tournament_stage_id', 'match.stage:id,name'])
            ->get()
            ->sortBy([
                fn ($a, $b) => ($a->match?->scheduled_at <=> $b->match?->scheduled_at),
                fn ($a, $b) => ($a->match?->position <=> $b->match?->position),
            ])
            ->values();

        return view('pages.event-team', [
            'event' => $event,
            'registration' => $registration,
            'tournament' => $tournament,
            'entrant' => $entrant,
            'columns' => $columns,
            'lines' => $lines,

            // Where they stand, taken from the stage they are in rather than recomputed.
            'standing' => $tournament->standings()
                ->where('tournament_entrant_id', $entrant->id)
                ->with('stage:id,name,advance_count')
                ->orderByDesc('tournament_stage_id')
                ->first(),
        ]);
    }
}
