<?php

namespace App\Support;

/**
 * Patterns that EXIST TO OBSERVE, NOT TO DEFEND. Do not promote this to a gate.
 *
 * Read that again before changing anything here. Nothing in this class decides
 * whether a request is served. It is called by ObserveSuspiciousInput, which
 * records what it finds at the lowest severity and then calls $next($request)
 * unconditionally. There is no branch anywhere that refuses a request because a
 * pattern matched, and there must never be one, for three reasons:
 *
 *   1. It would defend a door that is already shut. The raw SQL in this project is
 *      static literals and class constants; everything else goes through the query
 *      builder, which binds its parameters. A pattern gate stops nothing real.
 *   2. It WOULD stop real people. This system takes public registrations for a
 *      sports event. A participant named O'Brien, an event called "Drop Zone", a
 *      rule line carrying a < character: a false refusal there is worse than the
 *      thing being prevented, and it lands on somebody trying to sign up.
 *   3. It would become the thing people trust instead of the real defences —
 *      prepared statements, Blade's escaping, the permission middleware, the signed
 *      links and the CSRF token.
 *
 * What it IS for: a field arriving with ' OR 1=1-- is stored harmlessly as text and
 * nobody ever learns that somebody probed. This records that somebody did. The set
 * is deliberately small and obvious, tuned so ordinary Malaysian names, event names
 * and enquiry text do not match. If a pattern starts matching real text, delete the
 * pattern — do not add an exception list.
 */
final class SuspiciousInput
{
    /**
     * Input keys never looked at, let alone recorded.
     *
     * A password is scanned for nothing: a wrong password is not intelligence, and
     * a password containing a quote is none of this class's business.
     *
     * @var array<int, string>
     */
    private const SKIPPED_KEYS = [
        'password',
        'current_password',
        'password_confirmation',
        '_token',
        '_method',
        'remember_token',
        'signature',
    ];

    /** How much of one value is scanned. A probe states itself in the first line. */
    private const MAX_SCANNED_LENGTH = 2000;

    /** How deep into a nested payload the scan goes. */
    private const MAX_DEPTH = 3;

    /**
     * Label => pattern.
     *
     * Each one is tightened past the obvious version so everyday text does not
     * match:
     *
     *   sql-tautology       requires BOTH sides to be the SAME number, so "or 1=1"
     *                       and "' or '1'='1" match while "2 and 2=4" does not. The
     *                       quoting is deliberately lopsided: the classic payload
     *                       ends mid-string with no closing quote, so only the first
     *                       operand's quotes are paired up.
     *   sql-statement-break requires a semicolon AND a complete statement head, so
     *                       an event called "Drop Zone" and a note saying "delete
     *                       later" do not match.
     *   javascript-uri      requires an attribute in front of it, so an enquiry
     *                       about "JavaScript: the good parts" does not match.
     *
     * Nothing here matches a bare apostrophe or a bare <, which is the point.
     *
     * @var array<string, string>
     */
    private const PATTERNS = [
        'sql-union' => '/\bunion\s+(?:all\s+)?select\b/i',
        'sql-tautology' => '/\b(?:or|and)\s+([\'"]?)(\d{1,4})\1\s*=\s*[\'"]?\2\b/i',
        'sql-statement-break' => '/;\s*(?:drop\s+(?:table|database)|truncate\s+table|delete\s+from|insert\s+into|update\s+\w+\s+set)\b/i',
        'sql-timing' => '/\b(?:sleep|benchmark|pg_sleep|waitfor\s+delay)\s*\(/i',
        'script-tag' => '/<\s*script\b/i',
        'inline-event-handler' => '/<[^>]+\son(?:error|load|click|mouseover)\s*=/i',
        'javascript-uri' => '/\b(?:href|src|action|formaction)\s*=\s*[\'"]?\s*javascript\s*:/i',
        'path-traversal' => '#\.\.[/\\\\]#',
        'stream-wrapper' => '#\b(?:php|data|expect|file)://#i',
    ];

    /**
     * The first pattern a payload trips, or null.
     *
     * Stops at the first match on purpose: one row per request is intelligence, ten
     * rows naming the same probe is noise.
     *
     * @param  array<string, mixed>  $values
     * @return array{key: string, pattern: string, sample: string}|null
     */
    public static function firstMatch(array $values, int $depth = 0): ?array
    {
        foreach ($values as $key => $value) {
            if (self::isSkipped((string) $key)) {
                continue;
            }

            if (is_array($value)) {
                if ($depth >= self::MAX_DEPTH) {
                    continue;
                }

                $nested = self::firstMatch($value, $depth + 1);

                if ($nested !== null) {
                    return $nested;
                }

                continue;
            }

            if (! is_string($value) || $value === '') {
                continue;
            }

            $pattern = self::match($value);

            if ($pattern !== null) {
                return [
                    'key' => (string) $key,
                    'pattern' => $pattern,
                    'sample' => mb_substr($value, 0, 120),
                ];
            }
        }

        return null;
    }

    /**
     * The label of the first pattern this string trips, or null.
     *
     * Public so the behaviour can be asserted directly: the tests pin O'Brien,
     * "Drop Zone" and a bare < as NOT matching, because those are the registrations
     * a pattern gate would have refused.
     */
    public static function match(string $value): ?string
    {
        $value = mb_substr($value, 0, self::MAX_SCANNED_LENGTH);

        foreach (self::PATTERNS as $label => $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return $label;
            }
        }

        return null;
    }

    /** Whether this input key is one the scan never reads. */
    public static function isSkipped(string $key): bool
    {
        return in_array(strtolower($key), self::SKIPPED_KEYS, true);
    }
}
