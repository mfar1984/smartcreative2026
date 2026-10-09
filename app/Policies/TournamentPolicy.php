<?php

namespace App\Policies;

use App\Models\Tournament;
use App\Models\User;

/**
 * Who may see and act on one tournament.
 *
 * Two questions, asked in order. The permission decides what kind of act the role
 * is trusted with at all, which is unchanged from how every tournament screen has
 * always been gated. The assignment then decides which tournament that act may land
 * on, and it only ever narrows a handler: everybody else runs every tournament, so
 * no existing administrator role loses a row or a button because of this file.
 *
 * The super admin is never narrowed. If an assignment is wrong, that account is how
 * it gets put right.
 */
class TournamentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('tournaments.view');
    }

    public function view(User $user, Tournament $tournament): bool
    {
        return $user->hasPermission('tournaments.view')
            && $user->runsTournament($tournament);
    }

    public function update(User $user, Tournament $tournament): bool
    {
        return $user->hasPermission('tournaments.update')
            && $user->runsTournament($tournament);
    }

    public function delete(User $user, Tournament $tournament): bool
    {
        return $user->hasPermission('tournaments.delete')
            && $user->runsTournament($tournament);
    }

    /**
     * Whether this user may decide who runs a tournament.
     *
     * Never a handler, not even to add itself: being trusted to run a tournament is
     * not being trusted to decide who else does. It is the same capability as
     * editing the tournament, so it carries the permission that already owns the
     * form rather than a new slug no role would hold until it was seeded.
     */
    public function assignHandlers(User $user, Tournament $tournament): bool
    {
        // Refused for a handler before the permission is even read, so granting the
        // role tournaments.update one day could not turn into granting it the right
        // to hand itself another tournament.
        if ($user->isRestrictedToAssignedTournaments()) {
            return false;
        }

        return $user->hasPermission($tournament->exists
            ? 'tournaments.update'
            : 'tournaments.create');
    }
}
