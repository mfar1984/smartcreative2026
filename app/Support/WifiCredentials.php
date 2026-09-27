<?php

namespace App\Support;

use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\WifiCredential;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Issuing Wi-Fi logins, and turning them into something a router can import.
 *
 * Issued when somebody registers rather than when they pay, because an event may well be
 * free and then there is no payment to wait for. What decides it is the flag on the
 * event: no flag, no credentials, whatever else is true.
 *
 * Every entry point here is safe to run twice. Issuing happens on registration, again
 * from the counter when a walk-in is added, and again whenever an organiser presses the
 * backfill for an event that was switched on after people had already signed up. None of
 * those know about each other, so the only workable rule is that a second run changes
 * nothing.
 */
final class WifiCredentials
{
    /**
     * The alphabet these logins are drawn from.
     *
     * Missing i, l, o, 0 and 1 on purpose. These get read off a printed slip or an email
     * and typed into a phone by somebody standing in a queue, and every pair in that
     * list is one people get wrong. Losing five characters costs nothing; a login that
     * cannot be dictated over the noise of a hall costs an afternoon.
     *
     * Lower case only, for the same reason: no explaining which letter was capital.
     */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    private const USERNAME_LENGTH = 6;

    private const PASSWORD_LENGTH = 6;

    /**
     * How many times a colliding username is retried before giving up.
     *
     * With this alphabet there are over 800 million six-character names, so a clash
     * among a few hundred rows is already remote. The retry is here because "remote" is
     * not "impossible" and the alternative is an unhandled constraint violation in the
     * middle of somebody's registration.
     */
    private const COLLISION_RETRIES = 12;

    /* ---------------------------------------------------------------------
     | Issuing
     * ------------------------------------------------------------------ */

    /**
     * Issue for everybody on one registration.
     *
     * Returns how many were created, so a caller can report a real figure rather than
     * claiming success for work that was already done.
     */
    public static function issueFor(EventRegistration $registration): int
    {
        $event = $registration->event;

        if ($event === null || ! $event->offersWifi()) {
            return 0;
        }

        if (in_array($registration->status, [
            EventRegistration::STATUS_CANCELLED,
            EventRegistration::STATUS_WAITLISTED,
        ], true)) {
            return 0;
        }

        $expires = self::expiryFor($event);
        $issued = 0;

        foreach ($registration->participants as $participant) {
            if (self::issueOne($event, $registration, $participant, $expires)) {
                $issued++;
            }
        }

        return $issued;
    }

    /**
     * Issue for every registration on an event that has none yet.
     *
     * The reason this exists: the flag is usually turned on after the fact. An organiser
     * decides on Wi-Fi a week before the day, by which point two hundred people have
     * already registered and none of them tripped the hook that issues on registration.
     * Without this the feature would appear broken on the first event it is used for.
     */
    public static function backfill(Event $event): int
    {
        if (! $event->offersWifi()) {
            return 0;
        }

        $expires = self::expiryFor($event);
        $issued = 0;

        $event->registrations()
            ->whereNotIn('status', [
                EventRegistration::STATUS_CANCELLED,
                EventRegistration::STATUS_WAITLISTED,
            ])
            ->with('participants')
            // Chunked because this runs against every registration on the event and a
            // busy one is hundreds of rows with their rosters attached.
            ->chunkById(50, function (Collection $registrations) use ($event, $expires, &$issued) {
                foreach ($registrations as $registration) {
                    foreach ($registration->participants as $participant) {
                        if (self::issueOne($event, $registration, $participant, $expires)) {
                            $issued++;
                        }
                    }
                }
            });

        return $issued;
    }

    /**
     * One person, one login. True when a row was created.
     *
     * The unique index on the participant is what makes this safe rather than the check
     * that precedes it. Two requests arriving together would both pass the check, and
     * only the database can decide which of them wins; the caught violation is that
     * decision being accepted rather than turned into an error somebody has to read.
     */
    private static function issueOne(
        Event $event,
        EventRegistration $registration,
        EventParticipant $participant,
        string $expires,
    ): bool {
        if (WifiCredential::query()->where('event_participant_id', $participant->id)->exists()) {
            return false;
        }

        for ($attempt = 0; $attempt < self::COLLISION_RETRIES; $attempt++) {
            try {
                WifiCredential::create([
                    'event_id' => $event->id,
                    'event_registration_id' => $registration->id,
                    'event_participant_id' => $participant->id,
                    'username' => self::code(self::USERNAME_LENGTH),
                    'password' => self::code(self::PASSWORD_LENGTH),
                    'expires_on' => $expires,
                ]);

                return true;
            } catch (QueryException $exception) {
                /*
                 | Either the username clashed, in which case another draw fixes it, or
                 | the participant already has one because a parallel request got there
                 | first, in which case there is nothing left to do. Both are the
                 | constraint doing its job, so neither is worth raising.
                 */
                if (WifiCredential::query()->where('event_participant_id', $participant->id)->exists()) {
                    return false;
                }

                if ($attempt === self::COLLISION_RETRIES - 1) {
                    Log::warning('Could not issue a Wi-Fi credential.', [
                        'participant' => $participant->id,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return false;
    }

    /**
     * Never allowed to break a registration.
     *
     * Wi-Fi is a courtesy on top of entering a tournament. If issuing fails for any
     * reason, the entry must still stand: the organiser can press the backfill later,
     * whereas a registration lost at the gateway is gone. So the failure is logged and
     * swallowed, which is the opposite of what the issuing methods above do when called
     * deliberately from an admin screen.
     */
    public static function issueQuietly(EventRegistration $registration): void
    {
        try {
            self::issueFor($registration);
        } catch (Throwable $exception) {
            Log::warning('Wi-Fi credentials could not be issued for a registration.', [
                'registration' => $registration->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /* ---------------------------------------------------------------------
     | What the router fetches
     * ------------------------------------------------------------------ */

    /**
     * How accounts belonging to one event are tagged on the router.
     *
     * Every account this application creates carries this in its comment, and the script
     * removes by matching it before adding anything. That is what makes a fetch safe to
     * repeat and what keeps it from touching hotspot users the venue set up by hand:
     * staff logins, a test account, last month's event. We only ever clear up after
     * ourselves, and only for the event being provisioned.
     */
    public static function tag(Event $event): string
    {
        return 'scwifi:' . $event->id . ':';
    }

    /**
     * The RouterOS script for one event.
     *
     * Remove first, then add. Fetching twice is normal — a scheduler runs on a timer and
     * an organiser will press it by hand as well — so importing the same file again has
     * to end with the same accounts rather than a screen of "already exists" errors.
     *
     * The removal line is emitted even when there is nothing to add, and that is doing
     * real work rather than being tidy. The export only returns credentials that have
     * not expired, so the morning after the event the script is a removal and nothing
     * else: a scheduler left running cleans the router out on its own, with no second
     * job to write and nothing for anybody to remember.
     *
     * Turning the event's flag off has the same effect for the same reason.
     */
    public static function scriptFor(Event $event): string
    {
        $tag = self::tag($event);

        $credentials = $event->offersWifi()
            ? WifiCredential::query()
                ->where('event_id', $event->id)
                ->live()
                ->forAttending()
                ->orderBy('id')
                ->get()
            : collect();

        $lines = [
            '# ' . config('app.name') . ' - venue Wi-Fi',
            '# Event: ' . self::sanitiseComment((string) $event->title),
            '# Generated: ' . now()->toDateTimeString(),
            '# Accounts: ' . $credentials->count(),
            '#',
            '# Accounts tagged ' . rtrim($tag, ':') . ' are removed and rewritten every time this',
            '# runs, so importing it twice is safe. Hotspot users you created yourself are not',
            '# touched. Once the event is over this file carries no accounts, so leaving the',
            '# scheduled fetch in place is what clears the router out.',
            '',
            '/ip hotspot user',
            'remove [find comment~"^' . $tag . '"]',
        ];

        foreach ($credentials as $credential) {
            /*
             | Checked against the alphabet before being written into a script.
             |
             | These values are generated here, so today they cannot contain a quote or a
             | newline. This guards the day somebody widens the alphabet or imports
             | credentials from elsewhere: a password with a quote in it would not be a
             | broken password, it would be arbitrary commands running on the router.
             */
            if (! self::isSafeForScript($credential->username) || ! self::isSafeForScript((string) $credential->password)) {
                Log::warning('Skipped a Wi-Fi credential that could not be written to a script.', [
                    'credential' => $credential->id,
                ]);

                continue;
            }

            $lines[] = sprintf(
                'add name="%s" password="%s" comment="%s%s"',
                $credential->username,
                $credential->password,
                $tag,
                $credential->expires_on->toDateString(),
            );
        }

        // RouterOS reads this as a file, and a file of commands ends with a newline.
        return implode("\n", $lines) . "\n";
    }

    /**
     * Record that the router has taken a copy.
     *
     * Separate from building the script so the marking only happens once the response has
     * actually been handed over. Marking while composing would claim a fetch that a
     * dropped connection never completed, and the admin screen would then insist the
     * logins are live while the router has never heard of them.
     */
    public static function markProvisioned(Event $event): int
    {
        return WifiCredential::query()
            ->where('event_id', $event->id)
            ->live()
            ->forAttending()
            ->update(['provisioned_at' => now()]);
    }

    /**
     * Mint the secret in the fetch URL, once, and keep it.
     *
     * Kept rather than regenerated so a scheduled fetch pasted into the router keeps
     * working. Rotating is a deliberate act from the admin screen, not a side effect of
     * opening a page.
     */
    public static function tokenFor(Event $event): string
    {
        if (blank($event->wifi_token)) {
            $event->forceFill(['wifi_token' => bin2hex(random_bytes(24))])->save();
        }

        return (string) $event->wifi_token;
    }

    public static function rotateToken(Event $event): string
    {
        $event->forceFill(['wifi_token' => bin2hex(random_bytes(24))])->save();

        return (string) $event->wifi_token;
    }

    /** Only characters that cannot end a quoted RouterOS string early. */
    private static function isSafeForScript(string $value): bool
    {
        return $value !== '' && preg_match('/^[a-z0-9]+$/', $value) === 1;
    }

    /** A title is free text, and it is going into a script comment. */
    private static function sanitiseComment(string $value): string
    {
        return trim(preg_replace('/[^\x20-\x7E]/', '', str_replace(['"', "\r", "\n"], ' ', $value)) ?? '');
    }

    /* ---------------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------------ */

    /**
     * The day these logins stop working.
     *
     * The last day of the event, not today and not a fixed number of hours. A two-day
     * tournament means somebody who registered on the first morning still needs to get
     * online on the second, and an expiry measured from issue would have run out
     * overnight.
     */
    private static function expiryFor(Event $event): string
    {
        return ($event->ends_at ?? $event->starts_at ?? now())->toDateString();
    }

    /**
     * A random code from the unambiguous alphabet.
     *
     * random_int rather than rand or str_shuffle: these are credentials, and a
     * predictable sequence would let somebody who holds one work out the others.
     */
    private static function code(int $length): string
    {
        $alphabet = self::ALPHABET;
        $ceiling = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $ceiling)];
        }

        return $code;
    }
}
