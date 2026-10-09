<?php

namespace App\Http\Middleware;

use App\Models\Tournament;
use App\Models\TournamentMatch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A handler only ever reaches the tournaments assigned to it.
 *
 * Declared once on the whole tournament route group rather than checked in each
 * controller, because the thing being guarded against is a URL typed by hand. The
 * listings are narrowed where they are queried, but narrowing a list only hides a
 * link: Matches, Standings, the stage actions, score entry and publishing are all
 * reachable by changing a number, and every one of them has to refuse. One
 * declaration cannot be forgotten on a route added later, which a per-controller
 * check can.
 *
 * Does nothing at all unless the signed in user is a handler, so no administrator
 * and no existing screen behaves differently.
 */
class ScopeTournamentToHandler
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isRestrictedToAssignedTournaments()) {
            return $next($request);
        }

        foreach ($this->tournamentsNamedIn($request) as $tournament) {
            if (! $user->runsTournament($tournament)) {
                throw new AccessDeniedHttpException('That tournament is not assigned to you.');
            }
        }

        return $next($request);
    }

    /**
     * Every tournament this request points at, however it names it.
     *
     * @return array<int, Tournament>
     */
    private function tournamentsNamedIn(Request $request): array
    {
        $named = [];

        // The path: /tournaments/{tournament} and everything hanging off it,
        // including the entrant, seeding and stage actions.
        $bound = $request->route('tournament');

        if ($bound instanceof Tournament) {
            $named[] = $bound;
        }

        // Score entry and the fixture actions are keyed by the match, so the
        // tournament is reached through it.
        $match = $request->route('match');

        if ($match instanceof TournamentMatch && $match->tournament !== null) {
            $named[] = $match->tournament;
        }

        // The query string: Matches and Standings choose their tournament with
        // ?tournament=, which is as easy to edit as a path segment.
        $requested = $request->query('tournament');

        if (is_scalar($requested) && (int) $requested > 0) {
            $tournament = Tournament::find((int) $requested);

            if ($tournament !== null) {
                $named[] = $tournament;
            }
        }

        return $named;
    }
}
