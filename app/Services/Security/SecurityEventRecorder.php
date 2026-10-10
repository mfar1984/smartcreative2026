<?php

namespace App\Services\Security;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\AdminLogger;
use App\Support\IpAllowlist;
use App\Support\SecuritySettings;
use App\Support\SuspiciousInput;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * The Security Log: one place that turns a refusal into a row somebody can read.
 *
 * The system already refuses attacks. What was missing is that nobody could see it:
 * a prober could walk the whole admin URL space, collect two hundred 403s, and
 * leave no trace anybody would ever look at. So the refusals that already happen
 * are recorded here, and almost all of them arrive through ONE hook — the render
 * callback in bootstrap/app.php — rather than through a call at every refusal site,
 * which is how one site comes to be forgotten.
 *
 * WHAT IT DOES NOT DO: it does not refuse anything. There is no pattern gate here.
 * A field arriving with ' OR 1=1-- is recorded at the lowest severity and the
 * request is served exactly as before; see App\Support\SuspiciousInput for why that
 * is deliberate and must stay that way.
 *
 * Three rules this class is built on:
 *
 *   RECORDING CANNOT FAIL THE RESPONSE. Every public entry point swallows its own
 *   Throwable into the application log. A clean 403 must never become a 500 because
 *   the thing watching the 403 fell over.
 *
 *   A FLOOD CANNOT FILL THE TABLE. Repeats collapse: the same address, refused the
 *   same way on the same path inside COLLAPSE_MINUTES, bumps `hits` on the row that
 *   is already there. One prober hitting four hundred admin URLs in a minute leaves
 *   a handful of rows with large hit counts, which is also the more readable answer.
 *
 *   NOTHING SECRET IS WRITTEN. Request data is redacted by key against
 *   AdminLogger::SENSITIVE_KEYS plus the extra names below, recursively, so a
 *   password value never reaches the table even when it was the wrong one.
 */
final class SecurityEventRecorder
{
    /**
     * How long a repeat folds into the row already there.
     *
     * Five minutes: long enough that a scripted sweep is a few rows rather than
     * hundreds, short enough that "the same address came back an hour later" still
     * reads as a separate visit on the screen.
     */
    public const COLLAPSE_MINUTES = 5;

    /** Request keys recorded in the context, at most. */
    private const MAX_CONTEXT_KEYS = 20;

    /** How much of one recorded value is kept. */
    private const MAX_CONTEXT_VALUE = 200;

    /** How deep the redactor walks a nested payload before giving up on it. */
    private const MAX_CONTEXT_DEPTH = 3;

    /**
     * Keys dropped from the context outright: framework plumbing, not intelligence.
     *
     * @var array<int, string>
     */
    private const DROPPED_KEYS = ['_token', '_method', 'signature', 'expires'];

    /**
     * Extra names redacted on top of AdminLogger's list.
     *
     * The brief for this log is that it must redact AT LEAST as hard as the audit
     * trail does, so the audit trail's list is reused rather than retyped and these
     * are added to it. A security event carries whatever somebody posted, which is
     * a wider net than an audit diff.
     *
     * @var array<int, string>
     */
    private const EXTRA_SENSITIVE_KEYS = [
        'passwd',
        'credential',
        'authorization',
        'signature',
        'otp',
        'pin',
        'cvv',
        'card_number',
        'private',
    ];

    /**
     * Paths that never reach the log.
     *
     * The health check is polled by the host every minute and the build assets are
     * fetched on every page; a 403 or a stray query on either would bury the real
     * rows. Matched with Request::is(), so the wildcards are path wildcards.
     *
     * @var array<int, string>
     */
    private const IGNORED_PATHS = [
        'up',
        'build/*',
        'storage/*',
        'vendor/*',
        'favicon.ico',
        'robots.txt',
        'sitemap.xml',
    ];

    /** Anything that looks like an asset, whatever folder it is served from. */
    private const ASSET_EXTENSIONS = '/\.(?:css|js|mjs|map|png|jpe?g|gif|svg|webp|avif|ico|woff2?|ttf|eot)$/i';

    /* ---------------------------------------------------------------------
     | The one hook
     * ------------------------------------------------------------------ */

    /**
     * Record a refused request, from the render callback in bootstrap/app.php.
     *
     * Called for EVERY exception that reaches the handler, so the first job is to
     * decide which ones are refusals. A validation error, a 404 and a 500 are not:
     * a 404 is any mistyped URL and recording those would bury the rows that matter.
     *
     * Returns nothing and throws nothing. The caller returns null so Laravel renders
     * the refusal exactly as it did before this log existed.
     */
    public static function fromException(Throwable $exception, Request $request): void
    {
        try {
            if (self::isIgnoredPath($request)) {
                return;
            }

            [$type, $severity, $description] = self::classify($exception) ?? [null, null, null];

            if ($type === null) {
                return;
            }

            self::record($type, $severity, $description, $request);
        } catch (Throwable $failure) {
            self::noteFailure($failure);
        }
    }

    /**
     * What kind of refusal this exception is, or null when it is not one.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    private static function classify(Throwable $exception): ?array
    {
        /*
         | A signed link that did not check out. Checked before the generic 403
         | below, because InvalidSignatureException IS a 403 and the distinction is
         | the whole point: somebody tampering with a payment link is not the same as
         | somebody opening a screen their role cannot see.
         |
         | WARNING rather than critical, because a refused signature cannot tell the
         | two apart. These links live thirty days and reach participants by email, so
         | the commonest cause by far is a real person opening a link from last month.
         | Severity says what can be CONCLUDED, and the honest conclusion is "worth a
         | look", which is what a run of them from one address becomes.
         */
        if ($exception instanceof InvalidSignatureException) {
            return [
                SecurityEvent::TYPE_INVALID_SIGNATURE,
                SecurityEvent::SEVERITY_WARNING,
                'A signed link was refused: the signature did not check out, or it had expired.',
            ];
        }

        if ($exception instanceof TooManyRequestsHttpException) {
            return [
                SecurityEvent::TYPE_RATE_LIMITED,
                SecurityEvent::SEVERITY_WARNING,
                'Too many requests: the rate limit refused this one.',
            ];
        }

        if (! $exception instanceof HttpExceptionInterface) {
            return null;
        }

        $status = $exception->getStatusCode();
        $message = trim($exception->getMessage());

        return match ($status) {
            // The CSRF token was missing, stale or wrong. Ordinary for somebody who
            // left a tab open since yesterday, and exactly what a cross-site post
            // looks like, so it is recorded and left for a human to read.
            419 => [
                SecurityEvent::TYPE_CSRF_FAILURE,
                SecurityEvent::SEVERITY_WARNING,
                'A form was refused: the CSRF token was missing, stale or wrong.',
            ],

            // Every 403 in the system: the permission middleware (whose message
            // names the permission that was missing), the handler tournament scope,
            // a cross-tab id in User Management, a sponsor asking for another
            // sponsorship's id, and the two hand-written abort(403)s on refunds.
            403 => [
                SecurityEvent::TYPE_ACCESS_DENIED,
                SecurityEvent::SEVERITY_WARNING,
                $message !== '' ? $message : 'Access denied.',
            ],

            default => null,
        };
    }

    /* ---------------------------------------------------------------------
     | Observation
     * ------------------------------------------------------------------ */

    /**
     * Note a probing pattern in request data. NEVER refuses anything.
     *
     * Called by ObserveSuspiciousInput before the request is handled. Severity is
     * always the lowest one, and the type is left out of the repetition ban's count
     * (SecurityEvent::scopeCountsTowardsBan), so a match cannot stop anybody now or
     * get anybody banned later. It is a note that somebody probed, nothing more.
     */
    public static function observe(Request $request): void
    {
        try {
            if (self::isIgnoredPath($request)) {
                return;
            }

            $found = SuspiciousInput::firstMatch($request->all());

            if ($found === null) {
                return;
            }

            self::record(
                SecurityEvent::TYPE_SUSPICIOUS_INPUT,
                SecurityEvent::SEVERITY_INFO,
                sprintf('Observed a %s pattern in "%s". The request was served normally.', $found['pattern'], $found['key']),
                $request,
                ['pattern' => $found['pattern'], 'field' => $found['key'], 'sample' => $found['sample']],
            );
        } catch (Throwable $failure) {
            self::noteFailure($failure);
        }
    }

    /* ---------------------------------------------------------------------
     | Writing
     * ------------------------------------------------------------------ */

    /**
     * Record one security event, collapsing a repeat into the row already there.
     *
     * Safe to call from anywhere: it swallows its own failures. The explicit call
     * sites are the three refusals that never raise an exception and so cannot
     * arrive through the hook — the two sign-in refusals (LoginRequest,
     * EnforceIpAllowlist) and the backup store's file name check.
     *
     * @param  array<string, mixed>  $extra
     */
    public static function record(
        string $type,
        string $severity,
        string $description,
        ?Request $request = null,
        array $extra = [],
    ): ?SecurityEvent {
        try {
            $request ??= self::currentRequest();

            if ($request !== null && self::isIgnoredPath($request)) {
                return null;
            }

            $ip = (string) ($request?->ip() ?? '');
            $path = $request !== null ? Str::limit('/' . ltrim($request->path(), '/'), 500, '') : null;
            $user = Auth::user();
            $now = now();

            $existing = self::collapsible($type, $ip, $path);

            if ($existing !== null) {
                $existing->increment('hits', 1, ['last_seen_at' => $now]);

                $event = $existing;
            } else {
                $context = $extra;

                if ($request !== null) {
                    $context['route'] = $request->route()?->getName();
                    $context['input'] = self::requestContext($request);
                }

                $event = SecurityEvent::create([
                    'severity' => array_key_exists($severity, SecurityEvent::SEVERITIES) ? $severity : SecurityEvent::SEVERITY_WARNING,
                    'type' => $type,
                    'description' => Str::limit($description, 252, '...'),
                    'ip_address' => $ip !== '' ? $ip : null,
                    'user_id' => $user?->id,
                    'actor_label' => $user?->logLabel(),
                    'method' => $request?->method(),
                    'path' => $path,
                    'user_agent' => $request !== null ? substr((string) $request->userAgent(), 0, 512) : null,
                    'context' => array_filter($context, fn ($value) => $value !== null && $value !== []),
                    'hits' => 1,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);
            }

            /*
             | Outside the branch above, deliberately. The ban counts REFUSALS, not
             | rows, and repeats collapse — so a sweep that hammers five paths four
             | hundred times is five rows. Evaluating only on a fresh row would mean
             | the worst floods were the ones that never reached the threshold.
             |
             | Only a refusal can arm it, and only an address that is not exempt.
             | TYPE_IP_BANNED and TYPE_SUSPICIOUS_INPUT are excluded, so a ban cannot
             | count towards the next one and a pattern match cannot cause one.
             */
            self::considerBan($ip, $user, $type);

            return $event;
        } catch (Throwable $failure) {
            self::noteFailure($failure);

            return null;
        }
    }

    /**
     * The row this event folds into, or null when it is a fresh one.
     *
     * Keyed on address, type and path: the same prober hitting twenty DIFFERENT
     * admin URLs still leaves twenty rows, which is the shape that says "somebody
     * is walking the URL space". It is the same URL hammered that collapses.
     */
    private static function collapsible(string $type, string $ip, ?string $path): ?SecurityEvent
    {
        if ($ip === '') {
            return null;
        }

        return SecurityEvent::query()
            ->where('ip_address', $ip)
            ->where('type', $type)
            ->where('path', $path)
            ->where('last_seen_at', '>=', now()->subMinutes(self::COLLAPSE_MINUTES))
            ->orderByDesc('id')
            ->first();
    }

    /* ---------------------------------------------------------------------
     | The repetition ban
     * ------------------------------------------------------------------ */

    /**
     * Ban the address when it has been refused too many times inside the window.
     *
     * DISARMED BY DEFAULT, which is the rule the whole Security tab was built on:
     * first deploy changes nothing. The counting runs from the first request after
     * this ships, so the owner can read a week of the log before switching the
     * banning on from the Security tab.
     *
     * Two addresses can never be banned by this, for the same reason the sign-in ban
     * exempts them: locking the office out of its own admin in the middle of an
     * event is a worse outcome than anything this prevents.
     *
     *   - a super admin's session. The ban is per address, so the exemption has to
     *     be read off whoever is signed in.
     *   - an address on the IP allowlist, which is trusted by definition.
     */
    private static function considerBan(string $ip, ?User $user, string $type): void
    {
        if ($ip === '' || ! SecuritySettings::securityBanEnabled()) {
            return;
        }

        if ($type === SecurityEvent::TYPE_SUSPICIOUS_INPUT || $type === SecurityEvent::TYPE_IP_BANNED) {
            return;
        }

        if ($user?->role !== null && $user->role->isSuperAdmin()) {
            return;
        }

        if (IpAllowlist::contains($ip)) {
            return;
        }

        $refusals = (int) SecurityEvent::query()
            ->countsTowardsBan()
            ->where('ip_address', $ip)
            ->where('last_seen_at', '>=', now()->subMinutes(SecuritySettings::securityBanWindowMinutes()))
            ->sum('hits');

        if ($refusals < SecuritySettings::securityBanAfterEvents()) {
            return;
        }

        $ban = app(LoginBanService::class)->banForSecurityEvents($ip, $refusals);

        if ($ban === null) {
            return;
        }

        $description = sprintf(
            '%s banned for %d minutes after %d refused requests within %d minutes.',
            $ip,
            (int) $ban->expires_at->diffInMinutes($ban->banned_at, true),
            $refusals,
            SecuritySettings::securityBanWindowMinutes(),
        );

        SecurityEvent::create([
            'severity' => SecurityEvent::SEVERITY_CRITICAL,
            'type' => SecurityEvent::TYPE_IP_BANNED,
            'description' => $description,
            'ip_address' => $ip,
            'hits' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        /*
         | Also an activity line, under the `security.` prefix, which is in
         | AdminLogger::ALWAYS_RECORDED — so the one line saying the system started
         | blocking an address is written even with activity logging switched off.
         */
        AdminLogger::activity('security.banned', $description, null, 'Security Log', AdminLogger::LEVEL_WARN);
    }

    /* ---------------------------------------------------------------------
     | Context
     * ------------------------------------------------------------------ */

    /**
     * What was posted, redacted and trimmed.
     *
     * Worth keeping: a probe states itself in its payload, and a refusal with no
     * payload is half a story. Bounded hard, because this is attacker-controlled
     * data going into a column: at most MAX_CONTEXT_KEYS keys, each value cut to
     * MAX_CONTEXT_VALUE characters, nested arrays walked MAX_CONTEXT_DEPTH deep and
     * then abandoned, uploads named rather than read.
     *
     * @return array<string, mixed>
     */
    private static function requestContext(Request $request): array
    {
        $values = $request->except(self::DROPPED_KEYS);

        if (count($values) > self::MAX_CONTEXT_KEYS) {
            $values = array_slice($values, 0, self::MAX_CONTEXT_KEYS, true);
            $values['...'] = sprintf('%d more field(s) not recorded', count($request->all()) - self::MAX_CONTEXT_KEYS);
        }

        return self::redact($values);
    }

    /**
     * Redact by key name, recursively, and trim what is left.
     *
     * AdminLogger::SENSITIVE_KEYS is reused rather than retyped so the two logs
     * cannot drift apart, with EXTRA_SENSITIVE_KEYS on top. A matching key's value
     * is replaced before it is ever looked at, so a wrong password is recorded as
     * [redacted] exactly like a right one.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function redact(array $values, int $depth = 0): array
    {
        $needles = [...AdminLogger::SENSITIVE_KEYS, ...self::EXTRA_SENSITIVE_KEYS];
        $redacted = [];

        foreach ($values as $key => $value) {
            $name = strtolower((string) $key);

            foreach ($needles as $needle) {
                if (str_contains($name, $needle)) {
                    $redacted[$key] = '[redacted]';

                    continue 2;
                }
            }

            if (is_array($value)) {
                $redacted[$key] = $depth >= self::MAX_CONTEXT_DEPTH
                    ? '[nested]'
                    : self::redact($value, $depth + 1);

                continue;
            }

            if ($value instanceof UploadedFile) {
                $redacted[$key] = sprintf('[file] %s', Str::limit((string) $value->getClientOriginalName(), 80));

                continue;
            }

            if (is_scalar($value) || $value === null) {
                $redacted[$key] = is_string($value)
                    ? Str::limit($value, self::MAX_CONTEXT_VALUE, '...')
                    : $value;

                continue;
            }

            $redacted[$key] = '[not recorded]';
        }

        return $redacted;
    }

    /* ---------------------------------------------------------------------
     | Plumbing
     * ------------------------------------------------------------------ */

    /** Whether this path is one the log deliberately never hears about. */
    private static function isIgnoredPath(Request $request): bool
    {
        if ($request->is(...self::IGNORED_PATHS)) {
            return true;
        }

        return preg_match(self::ASSET_EXTENSIONS, $request->path()) === 1;
    }

    /**
     * The request in flight, or null when there is none (a console command, a queued job).
     */
    private static function currentRequest(): ?Request
    {
        $request = app()->bound('request') ? app('request') : null;

        return $request instanceof Request ? $request : null;
    }

    /**
     * A failure inside this class goes to the application log and nowhere else.
     *
     * The whole point: a refused request stays refused with the status it already
     * had. Turning a clean 403 into a 500 because the thing watching the 403 could
     * not write its row would be the log causing the incident.
     */
    private static function noteFailure(Throwable $failure): void
    {
        try {
            Log::warning('A security event could not be recorded.', [
                'reason' => $failure->getMessage(),
            ]);
        } catch (Throwable) {
            // Nothing left to try, and nothing worth breaking a response over.
        }
    }
}
