<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The session-table housekeeping the Security tab adds.
 *
 * The owner's complaint was that logging out left a stale row in the sessions
 * table carrying user_id NULL, because Laravel's invalidate() only clears the
 * payload and rotates the id — it never deletes the old row. These helpers delete
 * the underlying row so a logout, an inactivity timeout and a one-session login
 * all leave no debris behind.
 *
 * Everything here no-ops unless the session driver is 'database', because there
 * is no sessions table to clean on the file or array driver, and the one-session
 * sweep is skipped unless the owner turned it on.
 */
final class SessionGuard
{
    /** Whether the active session driver stores rows we can delete. */
    public static function usesDatabase(): bool
    {
        return config('session.driver') === 'database';
    }

    private static function table(): string
    {
        return config('session.table', 'sessions');
    }

    /**
     * Delete the row for one session id.
     *
     * Returns the number of rows removed, which the tests assert on. Safe to call
     * on a non-database driver: it reports zero without touching anything.
     */
    public static function deleteSession(string $id): int
    {
        if (! self::usesDatabase() || $id === '') {
            return 0;
        }

        return DB::table(self::table())->where('id', $id)->delete();
    }

    /**
     * Log the current user out and DESTROY the session row behind it.
     *
     * The row is captured before invalidate() rotates the id, then deleted after,
     * so the stale user_id NULL row the owner saw never gets left behind. Honours
     * the destroy_session_on_logout toggle: when it is off the behaviour falls
     * back to Laravel's default (invalidate only), which is the pre-fix state.
     */
    public static function logoutAndDestroy(Request $request): void
    {
        // The id and the user before invalidate(): the row the user was signed in
        // on, and the account whose stale rows must not survive the logout.
        $previousId = $request->session()->getId();
        $userId = Auth::id();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if (! SecuritySettings::destroySessionOnLogout()) {
            return;
        }

        // Delete the row the user was on straight away.
        self::deleteSession($previousId);

        // The session middleware writes the (now anonymous) session row again when
        // the response is sent, so the only reliable moment to clear the debris is
        // after that write. Deleting on terminate removes both the freshly written
        // row and any other row still carrying this user_id — the stale rows the
        // owner is complaining about — so logging out leaves nothing behind.
        $currentId = $request->session()->getId();

        app()->terminating(function () use ($currentId, $userId): void {
            self::deleteSession($currentId);

            if ($userId !== null && self::usesDatabase()) {
                DB::table(self::table())->where('user_id', $userId)->delete();
            }
        });
    }

    /**
     * When one-session-per-user is on, delete every other session row for a user.
     *
     * Called right after a successful login once the id has been regenerated, so
     * the just-created row is kept and every earlier one for the same user_id is
     * removed. This is the chosen mechanism for the database session driver: a
     * direct delete of the other rows, rather than Laravel's logoutOtherDevices,
     * which rewrites a password hash into the session and does not itself delete
     * the stale rows the owner is complaining about.
     *
     * Returns the number of rows removed.
     */
    public static function enforceSingleSession(Request $request, int $userId): int
    {
        if (! self::usesDatabase() || ! SecuritySettings::singleSession()) {
            return 0;
        }

        $currentId = $request->session()->getId();

        return DB::table(self::table())
            ->where('user_id', $userId)
            ->where('id', '!=', $currentId)
            ->delete();
    }
}
