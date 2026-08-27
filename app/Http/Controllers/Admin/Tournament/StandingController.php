<?php

namespace App\Http\Controllers\Admin\Tournament;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\TournamentStage;
use App\Services\AdminLogger;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Standings.
 *
 * The only screen whose tabs are not a fixed list: they are the stages of the chosen
 * tournament, so a group stage plus a playoff shows two and a straight knockout shows
 * one. Columns come from the point rule, so a PUBG table shows kills and a badminton
 * table shows sets without either being written into the view.
 */
class StandingController extends Controller
{
    public function index(Request $request)
    {
        $tournaments = Tournament::query()
            ->whereIn('status', [
                Tournament::STATUS_ONGOING,
                Tournament::STATUS_COMPLETED,
                Tournament::STATUS_PUBLISHED,
            ])
            ->with('event:id,title')
            ->orderByDesc('id')
            ->get();

        $tournament = $this->resolveTournament($request, $tournaments);

        if ($tournament === null) {
            return view('admin.tournament.standings', [
                'tournaments' => $tournaments,
                'tournament' => null,
                'tabs' => [],
                'activeTab' => null,
                'stage' => null,
                'groups' => collect(),
                'columns' => [],
                'canExport' => $request->user()->hasPermission('tournaments.standings.export'),
                'onPlayers' => false,
                'tracksPlayers' => false,
                'playerColumns' => [],
                'playerRows' => collect(),
            ]);
        }

        $tournament->load('pointRule');
        $stages = $tournament->stages()->get();

        /*
         | The player leaderboard is a tab alongside the stages rather than a screen of
         | its own, because an operator comparing a squad's total with a player's total
         | should not have to navigate away. It reads its own table, so nothing on the
         | stage tabs changes when it is present.
         */
        $tracksPlayers = $tournament->tracksPlayers();
        $onPlayers = $tracksPlayers && (string) $request->query('tab') === 'players';

        $stage = $onPlayers ? null : $this->resolveStage($request, $stages);

        $groups = collect();
        $columns = [];

        if ($stage !== null) {
            $rule = $tournament->pointRule;

            // Columns are the profile's components, so nothing here knows what a kill
            // or a set is. Counts are shown where the profile keeps one, because a
            // tie-break on kills compares kills rather than the points they earned.
            $columns = collect($rule?->components ?? [])
                ->map(fn (array $component) => [
                    'key' => $component['key'],
                    'label' => $component['label'] ?? $component['key'],
                    'counted' => in_array($component['type'] ?? '', ['per_unit', 'bonus'], true),
                ])
                ->all();

            $groups = $tournament->standings()
                ->where('tournament_stage_id', $stage->id)
                ->with(['entrant.registration:id,team_name,reference', 'group:id,name'])
                ->orderBy('rank')
                ->get()
                ->groupBy(fn ($standing) => $standing->group?->name ?? 'Overall');
        }

        return view('admin.tournament.standings', [
            'tournaments' => $tournaments,
            'tournament' => $tournament,

            'tabs' => $stages->mapWithKeys(fn (TournamentStage $s) => [
                (string) $s->id => [
                    'label' => $s->name,
                    'icon' => match ($s->type) {
                        TournamentStage::TYPE_GROUP => 'users',
                        TournamentStage::TYPE_LOBBY => 'mobile',
                        TournamentStage::TYPE_HEAT => 'activity',
                        default => 'grid',
                    },
                    'count' => $tournament->standings()->where('tournament_stage_id', $s->id)->count(),
                ],
            ])->when($tracksPlayers, fn ($tabs) => $tabs->put('players', [
                'label' => 'Players',
                'icon' => 'users',
                'count' => $tournament->playerStandings()->whereNull('tournament_stage_id')->count(),
            ]))->all(),

            'activeTab' => $onPlayers ? 'players' : (string) ($stage?->id ?? ''),
            'stage' => $stage,
            'groups' => $groups,
            'columns' => $columns,
            'canExport' => $request->user()->hasPermission('tournaments.standings.export'),

            // The player ledger, read only when its tab is open.
            'onPlayers' => $onPlayers,
            'tracksPlayers' => $tracksPlayers,
            'playerColumns' => $onPlayers ? $this->playerColumns($tournament) : [],
            'playerRows' => $onPlayers
                ? $tournament->playerStandings()
                    ->whereNull('tournament_stage_id')
                    ->with('entrant.registration:id,team_name,reference')
                    ->orderBy('rank')
                    ->get()
                : collect(),
        ]);
    }

    /**
     * Leaderboard columns, taken from the profile's player components.
     *
     * Separate from the team columns above and built from a different list, so a
     * profile can record damage for a player without damage appearing on the squad
     * table.
     *
     * @return array<int, array<string, mixed>>
     */
    private function playerColumns(Tournament $tournament): array
    {
        return collect($tournament->pointRule?->player_components ?? [])
            ->map(fn (array $component) => [
                'key' => $component['key'],
                'label' => $component['label'] ?? $component['key'],
                'counted' => ($component['type'] ?? '') === 'per_unit',
            ])
            ->all();
    }

    /**
     * Standings as CSV.
     *
     * Streamed rather than built in memory, because a large field with several stages
     * would otherwise be held twice.
     */
    public function export(Request $request, Tournament $tournament): StreamedResponse
    {
        $tournament->load('pointRule');

        if ((string) $request->query('view') === 'players') {
            return $this->exportPlayers($tournament);
        }

        $components = collect($tournament->pointRule?->components ?? [])
            ->pluck('label', 'key')
            ->all();

        $standings = $tournament->standings()
            ->with(['entrant.registration:id,team_name,reference', 'stage:id,name', 'group:id,name'])
            ->orderBy('tournament_stage_id')
            ->orderBy('rank')
            ->get();

        AdminLogger::activity('tournaments.standings.export', sprintf(
            'Exported standings for tournament %s.',
            $tournament->name,
        ));

        $filename = sprintf('standings-%s-%s.csv', $tournament->id, now()->format('Ymd-His'));

        return response()->streamDownload(function () use ($standings, $components) {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, array_merge(
                ['Stage', 'Group', 'Rank', 'Entrant', 'Played', 'Won', 'Lost'],
                array_values($components),
                ['Total', 'Status'],
            ));

            foreach ($standings as $standing) {
                fputcsv($handle, array_merge(
                    [
                        $standing->stage?->name,
                        $standing->group?->name ?? 'Overall',
                        $standing->rank,
                        $standing->entrant?->displayName(),
                        $standing->played,
                        $standing->won,
                        $standing->lost,
                    ],
                    collect($components)->keys()->map(fn ($key) => $standing->componentTotal($key))->all(),
                    [
                        $standing->total_points,
                        $standing->is_disqualified ? 'Disqualified' : ($standing->advances ? 'Advances' : ''),
                    ],
                ));
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * The player leaderboard as CSV.
     *
     * A separate file with separate columns, because merging the two ledgers into one
     * sheet is exactly the confusion this design exists to avoid. No IC number,
     * address, phone or email: the in-game name and the figures are the record.
     */
    private function exportPlayers(Tournament $tournament): StreamedResponse
    {
        $components = collect($tournament->pointRule?->player_components ?? [])
            ->pluck('label', 'key')
            ->all();

        $rows = $tournament->playerStandings()
            ->with(['entrant.registration:id,team_name,reference', 'stage:id,name'])
            ->orderByRaw('tournament_stage_id is null desc')
            ->orderBy('tournament_stage_id')
            ->orderBy('rank')
            ->get();

        AdminLogger::activity('tournaments.standings.export', sprintf(
            'Exported the player leaderboard for tournament %s.',
            $tournament->name,
        ));

        $filename = sprintf('players-%s-%s.csv', $tournament->id, now()->format('Ymd-His'));

        return response()->streamDownload(function () use ($rows, $components) {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, array_merge(
                ['Stage', 'Rank', 'Player', 'IGN', 'Team', 'Matches'],
                array_values($components),
                ['Total', 'Note'],
            ));

            foreach ($rows as $row) {
                fputcsv($handle, array_merge(
                    [
                        $row->stage?->name ?? 'Whole tournament',
                        $row->rank,
                        $row->display_name,
                        $row->ign,
                        $row->entrant?->displayName(),
                        $row->matches_played,
                    ],
                    collect($components)->keys()
                        ->map(fn ($key) => $row->componentCount($key) ?: $row->componentTotal($key))
                        ->all(),
                    [
                        $row->total_points,
                        $row->entrant_is_disqualified ? 'Team disqualified' : '',
                    ],
                ));
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Tournament>  $tournaments
     */
    private function resolveTournament(Request $request, $tournaments): ?Tournament
    {
        $requested = (string) $request->query('tournament', '');

        if ($requested !== '') {
            return $tournaments->firstWhere('id', (int) $requested);
        }

        return $tournaments->first();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, TournamentStage>  $stages
     */
    private function resolveStage(Request $request, $stages): ?TournamentStage
    {
        $requested = (string) $request->query('tab', '');

        if ($requested !== '') {
            return $stages->firstWhere('id', (int) $requested) ?? $stages->first();
        }

        return $stages->first();
    }
}
