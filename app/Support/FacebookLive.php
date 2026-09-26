<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Whether the organiser's Facebook Page is broadcasting, and what to embed if it is.
 *
 * The point is that nobody has to do anything on the day. Facebook already knows when
 * a Page goes live, so the site asks Facebook rather than asking an operator to paste
 * a link while a match is being played. Going live is the trigger; the stream appearing
 * on the ranking page is the consequence.
 *
 * Read-only against Facebook. Nothing here publishes, schedules, or ends a broadcast,
 * so the worst a wrong token can do is leave the page without a video.
 *
 * Never throws. A page of standings is the reason somebody opened the site, and a
 * timeout on somebody else's API is not a reason to fail it: every failure path here
 * ends in "no video", logged, and the table renders as it always did.
 */
final class FacebookLive
{
    private const GROUP = 'integration.facebook';

    /**
     * How long an answer from Facebook is trusted.
     *
     * A minute, because "we are live" is worth knowing quickly but not worth an
     * outbound HTTP call on every visit. At a venue this page is refreshed hard, and
     * without the cache a busy moment would mean thousands of calls and a rate limit
     * at the exact point the stream matters most.
     *
     * The negative is cached too. A Page that is not live is the normal state, and
     * caching only the positive would mean every visit on an ordinary day paid for a
     * call that returns nothing.
     */
    private const TTL = 60;

    private const CACHE_KEY = 'facebook.live.current';

    /**
     * Graph API version.
     *
     * Pinned rather than left off, because an unversioned call is served by the oldest
     * version still alive and that floor moves without warning. Overridable from the
     * environment so a sunset can be answered by editing .env, not by a deploy.
     */
    private const VERSION = 'v23.0';

    /** @var array<string, mixed>|null|false */
    private static array|null|false $current = false;

    /* ---------------------------------------------------------------------
     | The saved profile
     * ------------------------------------------------------------------ */

    public static function get(string $key, ?string $default = null): ?string
    {
        return Setting::read(self::GROUP . '.' . $key, $default);
    }

    /** The master switch. Off means the ranking page never carries a video. */
    public static function isEnabled(): bool
    {
        return self::get('enabled') === '1';
    }

    public static function pageId(): ?string
    {
        return self::get('page_id');
    }

    public static function pageToken(): ?string
    {
        return self::get('page_token');
    }

    /**
     * A video to embed when there is no API profile to ask.
     *
     * Only used while `isReady()` is false, so there is exactly one behaviour in force
     * at a time. Once a Page id and token are saved, what is on screen is whatever
     * Facebook says is live, and a stale link cannot outlive the broadcast.
     */
    public static function fallbackUrl(): ?string
    {
        return self::get('fallback_url');
    }

    /** Whether Facebook can be asked at all. */
    public static function isReady(): bool
    {
        return filled(self::pageId()) && filled(self::pageToken());
    }

    public static function version(): string
    {
        return (string) env('FACEBOOK_GRAPH_VERSION', self::VERSION);
    }

    /* ---------------------------------------------------------------------
     | What to show
     * ------------------------------------------------------------------ */

    /**
     * The broadcast to put on the page right now, or null for none.
     *
     * Memoised for the request as well as cached, because the ranking page asks once
     * for the hero and once for the panel and one visit should cost one lookup.
     *
     * @return array{id: string|null, title: string|null, permalink: string, embed: string, source: string}|null
     */
    public static function current(): ?array
    {
        if (self::$current !== false) {
            return self::$current;
        }

        return self::$current = self::resolve();
    }

    public static function isBroadcasting(): bool
    {
        return self::current() !== null;
    }

    /** @return array<string, mixed>|null */
    private static function resolve(): ?array
    {
        if (! self::isEnabled()) {
            return null;
        }

        if (self::isReady()) {
            // `false` rather than null for "not live", because Cache::remember treats a
            // null as a miss and would call Facebook again on every single visit.
            $found = Cache::remember(self::CACHE_KEY, self::TTL, fn () => self::ask()['live'] ?? false);

            return is_array($found) ? $found : null;
        }

        $url = self::fallbackUrl();

        if (blank($url)) {
            return null;
        }

        return [
            'id' => null,
            'title' => null,
            'permalink' => $url,
            'embed' => self::embedUrl($url),
            'source' => 'manual',
        ];
    }

    /**
     * Ask Facebook, bypassing the cache, and report what happened.
     *
     * Used by the Test button on the settings screen, where "nothing appeared" has to
     * be separable into "the token is wrong" and "the Page is simply not live". Those
     * two need opposite responses from the operator, and a single boolean would leave
     * them guessing which one they were looking at.
     *
     * @return array{live: array<string, mixed>|null, error: string|null}
     */
    public static function probe(): array
    {
        $result = self::ask();

        // The public page should see whatever this just learned, rather than serving
        // the previous answer for up to another minute.
        self::flush();

        return $result;
    }

    /**
     * @return array{live: array<string, mixed>|null, error: string|null}
     */
    private static function ask(): array
    {
        $pageId = self::pageId();
        $token = self::pageToken();

        if (! filled($pageId) || ! filled($token)) {
            return ['live' => null, 'error' => 'No Page ID and access token are saved.'];
        }

        try {
            /*
             | The token goes in the Authorization header, not the query string.
             |
             | A Page token is a long-lived credential. In a query string it would be
             | copied into any connection error Guzzle raises, and from there into the
             | log, where it would sit in plain text for anybody who can read the log.
             */
            $response = Http::timeout(6)
                ->connectTimeout(4)
                ->withToken($token)
                ->get(sprintf('https://graph.facebook.com/%s/%s/live_videos', self::version(), $pageId), [
                    'fields' => 'id,status,title,permalink_url',
                    'limit' => 5,
                ]);

            if ($response->failed()) {
                $message = (string) ($response->json('error.message')
                    ?? 'Facebook answered with HTTP ' . $response->status() . '.');

                self::note('Facebook live check refused.', $message, $token);

                return ['live' => null, 'error' => $message];
            }

            foreach ((array) $response->json('data', []) as $video) {
                if (! is_array($video) || ($video['status'] ?? null) !== 'LIVE') {
                    continue;
                }

                $permalink = self::absolute((string) ($video['permalink_url'] ?? ''));

                if ($permalink === null) {
                    continue;
                }

                return [
                    'live' => [
                        'id' => (string) ($video['id'] ?? ''),
                        'title' => filled($video['title'] ?? null) ? (string) $video['title'] : null,
                        'permalink' => $permalink,
                        'embed' => self::embedUrl($permalink),
                        'source' => 'graph',
                    ],
                    'error' => null,
                ];
            }

            // Reachable, answered, nothing broadcasting. Not an error.
            return ['live' => null, 'error' => null];
        } catch (Throwable $exception) {
            self::note('Facebook live check failed.', $exception->getMessage(), $token);

            return ['live' => null, 'error' => 'Facebook could not be reached.'];
        }
    }

    /* ---------------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------------ */

    /**
     * The iframe source for a Facebook video permalink.
     *
     * Muted, because a browser refuses to autoplay a video with sound and the whole
     * embed would sit there showing a still. The player's own control turns it back
     * on, which is the visitor's decision rather than ours.
     */
    public static function embedUrl(string $permalink): string
    {
        return 'https://www.facebook.com/plugins/video.php?' . http_build_query([
            'href' => $permalink,
            'show_text' => 'false',
            'autoplay' => 'true',
            'mute' => '1',
        ]);
    }

    /**
     * Graph returns permalinks relative to facebook.com, which no iframe can load.
     */
    private static function absolute(string $permalink): ?string
    {
        if ($permalink === '') {
            return null;
        }

        if (str_starts_with($permalink, 'https://') || str_starts_with($permalink, 'http://')) {
            return $permalink;
        }

        return 'https://www.facebook.com/' . ltrim($permalink, '/');
    }

    /**
     * Log a failure with the credential taken out of it.
     *
     * Belt and braces next to sending the token as a header: a message assembled by
     * somebody else's library is not ours to trust with a secret.
     */
    private static function note(string $context, string $message, string $token): void
    {
        Log::warning($context, [
            'message' => filled($token) ? str_replace($token, '[redacted]', $message) : $message,
        ]);
    }

    /** One honest sentence for the settings screen. */
    public static function summary(): string
    {
        if (! self::isReady()) {
            return filled(self::fallbackUrl())
                ? 'No Page is connected, so the video link saved below is what gets embedded.'
                : 'No Page is connected and no video link is saved, so nothing is embedded.';
        }

        if (! self::isEnabled()) {
            return 'A Page is connected but the switch above is off, so nothing is embedded.';
        }

        return 'Connected. The ranking page carries the stream whenever this Page goes live.';
    }

    /** Forget what was read, for tests and for the moment the settings change. */
    public static function flush(): void
    {
        self::$current = false;
        Cache::forget(self::CACHE_KEY);
    }
}
