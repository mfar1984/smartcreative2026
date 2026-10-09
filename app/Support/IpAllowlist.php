<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Matching against the IP allowlist saved on the Security tab.
 *
 * An empty list means no restriction, which is how the system behaved before the
 * list existed. A non-empty list does two separate things, and they are kept apart
 * on purpose:
 *
 *   permits()   who may sign in and stay signed in. A super admin is exempt, which
 *               is decided by the callers, because only they know who is asking.
 *   contains()  which addresses are trusted. A failed sign in from a listed
 *               address is never counted and never banned (LoginBanService).
 *
 * Matching is Symfony's IpUtils::checkIp, which handles single addresses, CIDR
 * ranges, IPv4 and IPv6. The client IP is $request->ip(); this application does
 * not configure trusted proxies, and must not, because a wildcard proxy would let
 * anyone write their own X-Forwarded-For and walk straight past the list.
 */
final class IpAllowlist
{
    /**
     * The saved entries.
     *
     * @return array<int, string>
     */
    public static function entries(): array
    {
        return SecuritySettings::ipAllowlist();
    }

    /** Whether a list has been saved at all. */
    public static function isActive(): bool
    {
        return self::entries() !== [];
    }

    /** Whether the address is on a non-empty list. Always false when the list is empty. */
    public static function contains(string $ip): bool
    {
        return self::matches($ip, self::entries());
    }

    /**
     * Whether an address matches any entry in a list of addresses and CIDR ranges.
     *
     * The matching itself, with no opinion about which list it is reading, so the
     * Maintenance tab's exemption list goes through this same comparison instead of
     * growing a second one. An empty list matches nothing.
     *
     * @param  array<int, string>  $entries
     */
    public static function matches(string $ip, array $entries): bool
    {
        if ($entries === [] || $ip === '') {
            return false;
        }

        return IpUtils::checkIp($ip, $entries);
    }

    /** Whether the address may use the admin: always with no list, otherwise only when listed. */
    public static function permits(string $ip): bool
    {
        return ! self::isActive() || self::contains($ip);
    }

    /**
     * Whether one line of the list is a valid IP address or CIDR range.
     *
     * Checked by hand rather than left to IpUtils, which quietly answers "no match"
     * for a malformed entry. A typo saved silently would refuse every staff member
     * at the next click, so the form must reject it instead.
     */
    public static function isValidEntry(string $entry): bool
    {
        if (! str_contains($entry, '/')) {
            return filter_var($entry, FILTER_VALIDATE_IP) !== false;
        }

        [$address, $mask] = explode('/', $entry, 2);

        if (! ctype_digit($mask)) {
            return false;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return (int) $mask <= 32;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return (int) $mask <= 128;
        }

        return false;
    }

    /** The text as it is stored: trimmed lines, blanks dropped, one entry per line. */
    public static function normalise(?string $text): string
    {
        return implode("\n", SecuritySettings::allowlistLines((string) $text));
    }
}
