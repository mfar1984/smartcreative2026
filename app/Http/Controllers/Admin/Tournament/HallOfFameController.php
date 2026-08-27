<?php

namespace App\Http\Controllers\Admin\Tournament;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\TournamentStanding;
use App\Services\AdminLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Publishing a podium to the public site.
 *
 * The podium is copied and frozen when Publish is pressed. Nothing on the public
 * pages reads it back through standings, so correcting a kill count three months
 * after the prizes were handed out cannot quietly change who won.
 *
 * Changing a published result means withdrawing it first, which leaves a trail.
 */
class HallOfFameController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * @var array<string, array<string, string>>
     */
    private const TAB_INTRO = [
        'awaiting' => [
            'label' => 'Awaiting Publish',
            'icon' => 'lock',
            'title' => 'Awaiting Publish',
            'description' => 'Finished, but not yet on the public site.',
            'accent' => 'amber',
        ],
        'published' => [
            'label' => 'Published',
            'icon' => 'trophy',
            'title' => 'Published',
            'description' => 'Live on the website, with the podium frozen as it was published.',
            'accent' => 'purple',
        ],
    ];

    public function index(Request $request)
    {
        $tab = array_key_exists((string) $request->query('tab'), self::TAB_INTRO)
            ? (string) $request->query('tab')
            : 'awaiting';

        $status = $tab === 'published' ? Tournament::STATUS_PUBLISHED : Tournament::STATUS_COMPLETED;

        $tournaments = Tournament::query()
            ->where('status', $status)
            ->with(['event:id,title,starts_at', 'champions', 'playerAwards', 'pointRule:id,name,track_players,player_components'])
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        /*
         | For a tournament not yet published, the podium shown is the live standings,
         | because there is nothing frozen to show. For a published one it is the frozen
         | copy, which is the whole point of the table.
         */
        $previews = [];

        if ($tab === 'awaiting') {
            foreach ($tournaments as $tournament) {
                $previews[$tournament->id] = $this->podiumFrom($tournament);
            }
        }

        /*
         | Award previews are worked out for both tabs, because the two ledgers publish
         | independently: a tournament can be on the Published tab with its champions
         | frozen and its awards still waiting.
         */
        $awardPreviews = [];

        foreach ($tournaments as $tournament) {
            if ($tournament->tracksPlayers() && $tournament->playerAwards->isEmpty()) {
                $awardPreviews[$tournament->id] = $this->awardsFrom($tournament);
            }
        }

        $counts = Tournament::selectRaw('status, COUNT(*) as total')
            ->whereIn('status', [Tournament::STATUS_COMPLETED, Tournament::STATUS_PUBLISHED])
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.tournament.hall-of-fame', [
            'tabs' => [
                'awaiting' => [
                    'label' => self::TAB_INTRO['awaiting']['label'],
                    'icon' => self::TAB_INTRO['awaiting']['icon'],
                    'count' => (int) ($counts[Tournament::STATUS_COMPLETED] ?? 0),
                ],
                'published' => [
                    'label' => self::TAB_INTRO['published']['label'],
                    'icon' => self::TAB_INTRO['published']['icon'],
                    'count' => (int) ($counts[Tournament::STATUS_PUBLISHED] ?? 0),
                ],
            ],

            'activeTab' => $tab,
            'intro' => self::TAB_INTRO[$tab],
            'route' => 'admin.tournaments.hall-of-fame',
            'tournaments' => $tournaments,
            'previews' => $previews,
            'awardPreviews' => $awardPreviews,
            'canPublish' => $request->user()->hasPermission('tournaments.halloffame.publish'),
        ]);
    }

    /**
     * Freeze the podium and put it on the public site.
     */
    public function publish(Request $request, Tournament $tournament)
    {
        if ($tournament->isPublished()) {
            return back()->withErrors(['publish' => 'This podium is already published.']);
        }

        if ($tournament->status !== Tournament::STATUS_COMPLETED) {
            return back()->withErrors([
                'publish' => 'Every match has to be scored before a podium can be published.',
            ]);
        }

        $podium = $this->podiumFrom($tournament);

        if ($podium->isEmpty()) {
            return back()->withErrors([
                'publish' => 'There are no standings to take a podium from.',
            ]);
        }

        DB::transaction(function () use ($tournament, $podium, $request) {
            $tournament->champions()->delete();

            foreach ($podium as $standing) {
                /*
                 | Every meaningful field is copied, not referenced. This row is a record
                 | of what was announced, and it must read the same in a year even if the
                 | match behind it is corrected.
                 */
                $tournament->champions()->create([
                    'tournament_entrant_id' => $standing->tournament_entrant_id,
                    'rank' => $standing->rank,
                    'display_name' => $standing->entrant?->displayName() ?? 'Removed entry',
                    'total_points' => $standing->total_points,
                    'component_totals' => $standing->component_totals,
                    'published_at' => now(),
                    'published_by' => $request->user()->id,
                ]);
            }

            $tournament->update([
                'status' => Tournament::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);
        });

        AdminLogger::activity('tournaments.halloffame.publish', sprintf(
            'Published the podium for tournament %s.',
            $tournament->name,
        ));

        AdminLogger::audit($tournament, 'tournament.podium_published', null, [
            'podium' => $podium->map(fn ($s) => [
                'rank' => $s->rank,
                'entrant' => $s->entrant?->displayName(),
                'points' => $s->total_points,
            ])->all(),
        ]);

        return back()->with('status', sprintf(
            'Podium for %s published and frozen. Correcting a score now will not change it.',
            $tournament->name,
        ));
    }

    /**
     * Take a published podium back off the public site.
     */
    public function withdraw(Request $request, Tournament $tournament)
    {
        if (! $tournament->isPublished()) {
            return back()->withErrors(['publish' => 'This podium is not published.']);
        }

        DB::transaction(function () use ($tournament) {
            // The frozen rows go with it. Leaving them would mean a withdrawn podium
            // still had champions on file, which is a contradiction waiting to be found.
            $tournament->champions()->delete();

            $tournament->update([
                'status' => Tournament::STATUS_COMPLETED,
                'published_at' => null,
            ]);
        });

        AdminLogger::activity('tournaments.halloffame.publish', sprintf(
            'Withdrew the podium for tournament %s.',
            $tournament->name,
        ));

        AdminLogger::audit($tournament, 'tournament.podium_withdrawn', null, [
            'tournament' => $tournament->name,
        ]);

        return back()->with('status', sprintf(
            'Podium for %s withdrawn. It is off the public site and scores can be corrected again.',
            $tournament->name,
        ));
    }

    /**
     * Freeze the individual awards.
     *
     * A separate action from publishing the team podium, and it does not require one.
     * An organiser may announce the MVP without having published the champions, or
     * the other way round, because the two ledgers decide different things.
     */
    public function publishAwards(Request $request, Tournament $tournament)
    {
        $tournament->load('pointRule');

        if (! $tournament->tracksPlayers()) {
            return back()->withErrors([
                'awards' => 'This tournament\'s point rule does not record personal player scores.',
            ]);
        }

        $awards = $this->awardsFrom($tournament);

        if ($awards === []) {
            return back()->withErrors([
                'awards' => 'No personal scores have been recorded, so there are no awards to freeze.',
            ]);
        }

        DB::transaction(function () use ($tournament, $awards, $request) {
            $tournament->playerAwards()->delete();

            foreach ($awards as $award) {
                $tournament->playerAwards()->create($award + [
                    'published_at' => now(),
                    'published_by' => $request->user()->id,
                ]);
            }
        });

        AdminLogger::activity('tournaments.halloffame.publish', sprintf(
            'Published the player awards for tournament %s.',
            $tournament->name,
        ));

        AdminLogger::audit($tournament, 'tournament.player_awards_published', null, [
            'awards' => collect($awards)
                ->map(fn (array $a) => $a['award_label'] . ' ' . $a['rank'] . ': ' . $a['display_name'])
                ->all(),
        ]);

        return back()->with('status', sprintf(
            'Player awards for %s published and frozen. Correcting a score now will not change them.',
            $tournament->name,
        ));
    }

    /**
     * Take the individual awards back off the public site.
     */
    public function withdrawAwards(Request $request, Tournament $tournament)
    {
        if ($tournament->playerAwards()->doesntExist()) {
            return back()->withErrors(['awards' => 'There are no published player awards to withdraw.']);
        }

        $tournament->playerAwards()->delete();

        AdminLogger::activity('tournaments.halloffame.publish', sprintf(
            'Withdrew the player awards for tournament %s.',
            $tournament->name,
        ));

        AdminLogger::audit($tournament, 'tournament.player_awards_withdrawn', null, [
            'tournament' => $tournament->name,
        ]);

        return back()->with('status', sprintf('Player awards for %s withdrawn.', $tournament->name));
    }

    /**
     * The individual awards, worked out from the whole-tournament player leaderboard.
     *
     * Two kinds, and neither is written into the code:
     *
     *  - MVP, top three by personal points in the profile's own tie-break order.
     *  - One winner for each counted player component, so a profile that records kills
     *    produces a Top Kills award and one that records damage produces a Top Damage
     *    award. Adding a stat to the profile adds an award; nothing here lists them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function awardsFrom(Tournament $tournament): array
    {
        $rows = $tournament->playerStandings()
            ->whereNull('tournament_stage_id')
            ->with('entrant.registration:id,team_name,reference')
            ->orderBy('rank')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $awards = [];

        foreach ($rows->take(3) as $row) {
            $awards[] = $this->awardRow($row, 'mvp', 'MVP', $row->rank);
        }

        foreach ($tournament->pointRule?->player_components ?? [] as $component) {
            $key = $component['key'] ?? null;

            if ($key === null || ($component['type'] ?? '') !== 'per_unit') {
                continue;
            }

            $best = $rows->sortByDesc(fn ($row) => $row->componentCount($key))->first();

            if ($best === null || $best->componentCount($key) <= 0) {
                continue;
            }

            $awards[] = $this->awardRow(
                $best,
                'top_' . $key,
                'Top ' . ($component['label'] ?? $key),
                1,
            );
        }

        return $awards;
    }

    /**
     * One frozen award row. Every field copied.
     *
     * @return array<string, mixed>
     */
    private function awardRow($row, string $key, string $label, int $rank): array
    {
        return [
            'event_participant_id' => $row->event_participant_id,
            'award_key' => $key,
            'award_label' => $label,
            'rank' => $rank,
            'display_name' => $row->display_name,
            'ign' => $row->ign,
            'entrant_name' => $row->entrant?->displayName() ?? 'Removed entry',
            'total_points' => $row->total_points,
            'component_totals' => $row->component_counts ?: $row->component_totals,
        ];
    }

    /**
     * The top three of the final stage.
     *
     * The final stage, not the whole tournament, because that is where the result was
     * decided: a group stage table says nothing about who won.
     *
     * @return \Illuminate\Support\Collection<int, TournamentStanding>
     */
    private function podiumFrom(Tournament $tournament)
    {
        $finalStage = $tournament->stages()->orderByDesc('sequence')->first();

        if ($finalStage === null) {
            return collect();
        }

        return $tournament->standings()
            ->where('tournament_stage_id', $finalStage->id)
            ->where('is_disqualified', false)
            ->with('entrant.registration:id,team_name,reference')
            ->orderBy('rank')
            ->limit(3)
            ->get();
    }
}
