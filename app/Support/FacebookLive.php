<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
    private const VERSION = 'v26.0';

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

    public static function appId(): ?string
    {
        return self::get('app_id');
    }

    public static function appSecret(): ?string
    {
        return self::get('app_secret');
    }

    /**
     * Whether the app's own credentials are known.
     *
     * Needed for two things a Page token cannot do for itself: being told how long it
     * has left, and being traded for one that lasts.
     */
    public static function hasApp(): bool
    {
        return filled(self::appId()) && filled(self::appSecret());
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
     * @return array{live: array<string, mixed>|null, error: string|null, route: string|null}
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
     * Is this Page broadcasting, asked two different ways.
     *
     * The obvious endpoint, live_videos, sits behind Facebook's Live Video API feature.
     * That feature exists to let an app *publish* a broadcast, which this one never does,
     * yet it gates reading as well — so an integration that only looks can be refused by
     * a permission it has no business holding, and would have to justify publishing at
     * App Review to get it.
     *
     * So there is a second route. A live broadcast is also a video on the Page, and the
     * ordinary videos edge carries a live_status field saying whether it is running. That
     * edge asks only for pages_read_engagement, which is what we already have and all
     * that reading should ever have needed.
     *
     * Tried in that order, because live_videos is the endpoint built for the question and
     * gives a direct answer where it is available. The fallback is not a workaround for a
     * bug; it is the same fact read off a gate we are entitled to walk through.
     *
     * @return array{live: array<string, mixed>|null, error: string|null, route: string|null}
     */
    private static function ask(): array
    {
        $pageId = self::pageId();
        $token = self::pageToken();

        if (! filled($pageId) || ! filled($token)) {
            return ['live' => null, 'error' => 'No Page ID and access token are saved.', 'route' => null];
        }

        $direct = self::askLiveVideos((string) $pageId, (string) $token);

        // Answered, whether or not anything was on air. Nothing to fall back to.
        if ($direct['error'] === null) {
            return $direct;
        }

        $viaVideos = self::askVideos((string) $pageId, (string) $token);

        if ($viaVideos['error'] === null) {
            return $viaVideos;
        }

        /*
         | Both refused. The videos edge only needs a permission we hold, so its refusal
         | describes the real problem, while live_videos would blame a feature that is
         | beside the point. Both are carried so neither has to be guessed at.
         */
        return [
            'live' => null,
            'error' => $viaVideos['error'] . ' (The live_videos endpoint also refused: ' . $direct['error'] . ')',
            'route' => null,
        ];
    }

    /**
     * @return array{live: array<string, mixed>|null, error: string|null, route: string|null}
     */
    private static function askLiveVideos(string $pageId, string $token): array
    {
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

                return ['live' => null, 'error' => $message, 'route' => null];
            }

            foreach ((array) $response->json('data', []) as $video) {
                if (! is_array($video) || ($video['status'] ?? null) !== 'LIVE') {
                    continue;
                }

                $found = self::describeVideo($video, 'live_videos');

                if ($found !== null) {
                    return ['live' => $found, 'error' => null, 'route' => 'live_videos'];
                }
            }

            // Reachable, answered, nothing broadcasting. Not an error.
            return ['live' => null, 'error' => null, 'route' => 'live_videos'];
        } catch (Throwable $exception) {
            self::note('Facebook live check failed.', $exception->getMessage(), $token);

            return ['live' => null, 'error' => 'Facebook could not be reached.', 'route' => null];
        }
    }

    /**
     * The same question put to the Page's ordinary videos edge.
     *
     * A broadcast is a video while it runs, and `live_status` says so. Only
     * pages_read_engagement is needed here, so this route stays open whether or not the
     * Live Video API feature was ever granted.
     *
     * @return array{live: array<string, mixed>|null, error: string|null, route: string|null}
     */
    private static function askVideos(string $pageId, string $token): array
    {
        try {
            $response = Http::timeout(6)
                ->connectTimeout(4)
                ->withToken($token)
                ->get(sprintf('https://graph.facebook.com/%s/%s/videos', self::version(), $pageId), [
                    'fields' => 'id,title,description,permalink_url,live_status',
                    'limit' => 10,
                ]);

            if ($response->failed()) {
                $message = (string) ($response->json('error.message')
                    ?? 'Facebook answered with HTTP ' . $response->status() . '.');

                self::note('Facebook video check refused.', $message, $token);

                return ['live' => null, 'error' => $message, 'route' => null];
            }

            foreach ((array) $response->json('data', []) as $video) {
                if (! is_array($video) || ($video['live_status'] ?? null) !== 'LIVE') {
                    continue;
                }

                $found = self::describeVideo($video, 'videos');

                if ($found !== null) {
                    return ['live' => $found, 'error' => null, 'route' => 'videos'];
                }
            }

            return ['live' => null, 'error' => null, 'route' => 'videos'];
        } catch (Throwable $exception) {
            self::note('Facebook video check failed.', $exception->getMessage(), $token);

            return ['live' => null, 'error' => 'Facebook could not be reached.', 'route' => null];
        }
    }

    /**
     * One Graph video row turned into what the page needs, or null if it cannot be shown.
     *
     * Null rather than a half-filled row when there is no permalink: without one there is
     * nothing to embed and nothing to link to, so carrying it forward would only produce
     * an empty frame.
     *
     * @param  array<string, mixed>  $video
     * @return array<string, mixed>|null
     */
    private static function describeVideo(array $video, string $route): ?array
    {
        $permalink = self::absolute((string) ($video['permalink_url'] ?? ''));

        if ($permalink === null) {
            return null;
        }

        // The videos edge often carries the caption in `description` where live_videos
        // uses `title`, so whichever is there is used rather than showing nothing.
        $title = $video['title'] ?? $video['description'] ?? null;

        return [
            'id' => (string) ($video['id'] ?? ''),
            'title' => filled($title) ? Str::limit(trim((string) $title), 120) : null,
            'permalink' => $permalink,
            'embed' => self::embedUrl($permalink),
            'source' => $route,
        ];
    }

    /* ---------------------------------------------------------------------
     | Making the token last
     * ------------------------------------------------------------------ */

    /**
     * Trade the token that was pasted in for a Page token that does not expire.
     *
     * This exists because the obvious way round is a trap. A token copied out of the
     * Graph API Explorer lives about an hour, and a Page token derived from it inherits
     * that. Saved as-is, the stream works while it is being set up and has stopped by
     * the next morning, with nothing on screen to say why. Setting it up the day before
     * an event is the exact case that breaks.
     *
     * So the pasted token is treated as raw material rather than the answer: traded for
     * a long-lived login, then used to ask which Pages that login administers and to
     * take the Page's own token, which is the one that does not expire. What gets saved
     * is the end of that chain, and what gets reported is whatever Facebook says the
     * expiry now is rather than an assurance from us.
     *
     * @return array{ok: bool, message: string}
     */
    public static function connect(): array
    {
        if (! self::hasApp()) {
            return [
                'ok' => false,
                'message' => 'Save the App ID and App Secret first. Facebook will not trade a short-lived token for a lasting one without them.',
            ];
        }

        $pasted = self::pageToken();

        if (blank($pasted)) {
            return [
                'ok' => false,
                'message' => 'Paste the token from the Graph API Explorer into the Access Token box and save, then press this.',
            ];
        }

        /*
         | Pressed a second time, when the first press already did the job.
         |
         | After a successful connect the saved token is the Page's own. Running the rest
         | of this against it fails on /me/accounts, because `me` is then the Page and a
         | Page has no accounts edge — which surfaced as a bare "(#100) Tried accessing
         | nonexisting field (accounts)" and read as something being broken. It is not:
         | it is the work already being done. So the first thing to establish is what is
         | in there, and whether there is anything left to do at all.
         */
        $saved = self::inspectToken($pasted);

        if ($saved['error'] === null && $saved['valid'] && $saved['type'] === 'PAGE') {
            if ($saved['expires_at'] === 0) {
                return [
                    'ok' => true,
                    'message' => sprintf(
                        'Already done. What is saved is %sown token, and Facebook reports it as never expiring, so there is nothing left to trade. Press this again only after pasting a new token from the Explorer.',
                        filled(self::pageId()) ? 'Page ' . self::pageId() . '\'s ' : 'the Page\'s ',
                    ),
                ];
            }

            return [
                'ok' => false,
                'message' => 'What is saved is a Page token with an expiry date, and a Page token cannot be traded up. Generate a fresh token in the Graph API Explorer, paste that into the Access Token box, save, then press this again.',
            ];
        }

        $long = self::exchange($pasted);

        if ($long['error'] !== null) {
            return ['ok' => false, 'message' => $long['error']];
        }

        $pages = self::pagesFor($long['token']);

        if ($pages['error'] !== null) {
            return ['ok' => false, 'message' => $pages['error']];
        }

        if ($pages['pages'] === []) {
            return [
                'ok' => false,
                'message' => 'That login does not administer any Page. Check you authorised the right Page, and that the token carries pages_show_list.',
            ];
        }

        // Nothing is written until here, so every path above leaves the working profile
        // exactly as it was. That is what the failure box on screen promises.

        $wanted = self::pageId();
        $chosen = null;

        if (filled($wanted)) {
            $chosen = collect($pages['pages'])->firstWhere('id', $wanted);

            if ($chosen === null) {
                return [
                    'ok' => false,
                    'message' => sprintf(
                        'Page %s is not one this login administers. It has: %s. Clear the Page ID box to let the right one be picked for you.',
                        $wanted,
                        self::describe($pages['pages']),
                    ),
                ];
            }
        } elseif (count($pages['pages']) === 1) {
            // Exactly one, so there is nothing to choose and asking would be busywork.
            $chosen = $pages['pages'][0];
        } else {
            return [
                'ok' => false,
                'message' => sprintf(
                    'This login administers more than one Page, so it is not for us to guess which one broadcasts. Put one of these ids in the Page ID box and press this again: %s.',
                    self::describe($pages['pages']),
                ),
            ];
        }

        Setting::write(self::GROUP . '.page_id', (string) $chosen['id'], self::GROUP);
        Setting::write(self::GROUP . '.page_token', (string) $chosen['token'], self::GROUP, true);

        self::flush();

        $health = self::inspectToken((string) $chosen['token']);

        return [
            'ok' => true,
            'message' => sprintf(
                'Connected to %s (%s). %s',
                $chosen['name'] !== '' ? $chosen['name'] : 'the Page',
                $chosen['id'],
                self::expiryNote($health),
            ),
        ];
    }

    /**
     * Swap a short-lived token for a long-lived one.
     *
     * @return array{token: string, error: string|null}
     */
    private static function exchange(string $token): array
    {
        $reply = self::graph('oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => (string) self::appId(),
            'client_secret' => (string) self::appSecret(),
            'fb_exchange_token' => $token,
        ], $token);

        if ($reply['error'] !== null) {
            return ['token' => '', 'error' => $reply['error']];
        }

        $long = (string) ($reply['body']['access_token'] ?? '');

        if ($long === '') {
            return ['token' => '', 'error' => 'Facebook accepted the swap but returned no token.'];
        }

        return ['token' => $long, 'error' => null];
    }

    /**
     * The Pages a login administers, each with its own token.
     *
     * @return array{pages: array<int, array{id: string, name: string, token: string}>, error: string|null}
     */
    private static function pagesFor(string $userToken): array
    {
        $reply = self::graph('me/accounts', ['fields' => 'id,name,access_token', 'limit' => 100], $userToken, $userToken);

        if ($reply['error'] !== null) {
            return ['pages' => [], 'error' => $reply['error']];
        }

        $pages = [];

        foreach ((array) ($reply['body']['data'] ?? []) as $page) {
            if (! is_array($page) || blank($page['id'] ?? null) || blank($page['access_token'] ?? null)) {
                continue;
            }

            $pages[] = [
                'id' => (string) $page['id'],
                'name' => (string) ($page['name'] ?? ''),
                'token' => (string) $page['access_token'],
            ];
        }

        return ['pages' => $pages, 'error' => null];
    }

    /**
     * What Facebook says about a token: whether it works, and when it stops.
     *
     * Asked rather than assumed. The whole point of the exchange above is that a token's
     * lifetime is not visible by looking at it, so claiming "this one is permanent"
     * without checking would reintroduce exactly the silent failure it set out to avoid.
     *
     * @return array{valid: bool, expires_at: int|null, scopes: array<int, string>, type: string|null, error: string|null}
     */
    public static function inspectToken(string $token): array
    {
        if (! self::hasApp() || blank($token)) {
            return ['valid' => false, 'expires_at' => null, 'scopes' => [], 'type' => null, 'error' => 'No app credentials to check with.'];
        }

        $reply = self::graph('debug_token', [
            'input_token' => $token,
            'access_token' => self::appId() . '|' . self::appSecret(),
        ], $token);

        if ($reply['error'] !== null) {
            return ['valid' => false, 'expires_at' => null, 'scopes' => [], 'type' => null, 'error' => $reply['error']];
        }

        $data = (array) ($reply['body']['data'] ?? []);

        return [
            'valid' => (bool) ($data['is_valid'] ?? false),
            'expires_at' => isset($data['expires_at']) ? (int) $data['expires_at'] : null,
            'scopes' => array_values(array_filter((array) ($data['scopes'] ?? []), 'is_string')),
            'type' => filled($data['type'] ?? null) ? (string) $data['type'] : null,
            'error' => null,
        ];
    }

    /**
     * Whether the saved token is one that will still be there at the next event.
     *
     * Only ever answered from Facebook's own reply, and null when it cannot be asked,
     * so "we do not know" is never dressed up as "it is fine".
     */
    public static function tokenIsPermanent(): ?bool
    {
        if (! self::hasApp() || blank(self::pageToken())) {
            return null;
        }

        $health = self::inspectToken((string) self::pageToken());

        if ($health['error'] !== null) {
            return null;
        }

        return $health['valid'] && $health['expires_at'] === 0;
    }

    /**
     * One GET against Graph, with the failure already turned into a sentence.
     *
     * @param  array<string, mixed>  $query
     * @return array{body: array<mixed>, error: string|null}
     */
    private static function graph(string $path, array $query, string $scrub = '', ?string $bearer = null): array
    {
        try {
            $request = Http::timeout(10)->connectTimeout(5);

            if ($bearer !== null) {
                $request = $request->withToken($bearer);
            }

            $response = $request->get(sprintf('https://graph.facebook.com/%s/%s', self::version(), ltrim($path, '/')), $query);

            if ($response->failed()) {
                $message = (string) ($response->json('error.message')
                    ?? 'Facebook answered with HTTP ' . $response->status() . '.');

                self::note('Facebook call refused: ' . $path, $message, $scrub);

                return ['body' => [], 'error' => $message];
            }

            return ['body' => (array) $response->json(), 'error' => null];
        } catch (Throwable $exception) {
            self::note('Facebook call failed: ' . $path, $exception->getMessage(), $scrub);

            return ['body' => [], 'error' => 'Facebook could not be reached.'];
        }
    }

    /** @param array<int, array{id: string, name: string, token: string}> $pages */
    private static function describe(array $pages): string
    {
        return collect($pages)
            ->map(fn (array $page) => trim(($page['name'] !== '' ? $page['name'] . ' ' : '') . '(' . $page['id'] . ')'))
            ->implode(', ');
    }

    /** @param array{valid: bool, expires_at: int|null, scopes: array<int, string>, error: string|null} $health */
    private static function expiryNote(array $health): string
    {
        if ($health['error'] !== null) {
            return 'The token is saved, but Facebook would not say when it expires: ' . $health['error'];
        }

        if (! $health['valid']) {
            return 'Facebook reports the saved token as no longer valid, which should not happen straight after a swap. Generate a fresh one in the Explorer and try again.';
        }

        if ($health['expires_at'] === 0) {
            return 'Facebook reports it as never expiring, so this is set up for good.';
        }

        if ($health['expires_at'] === null) {
            return 'Facebook did not report an expiry, so treat it as temporary and check again before the event.';
        }

        return sprintf(
            'Facebook reports it as expiring on %s, which is not what we wanted. Generate a new token in the Explorer, save it here, and press this again.',
            date('j M Y H:i', $health['expires_at']),
        );
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
