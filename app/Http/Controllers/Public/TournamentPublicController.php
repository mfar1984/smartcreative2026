<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Tournament;
use App\Models\TournamentChampion;
use App\Models\TournamentMatch;
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
}
