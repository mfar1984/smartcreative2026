<?php

namespace App\Support;

use App\Services\Payment\ChipGateway;
use Illuminate\Support\Facades\Cache;

/**
 * The CHIP account balance, cached, for the one line in the sidebar footer.
 *
 * The sidebar is drawn on every admin page. Calling CHIP on each of those would
 * mean every click waits on somebody else's network, and one outage at CHIP would
 * make the whole admin feel broken. So the value is fetched at most once every few
 * minutes and read from cache the rest of the time.
 *
 * When a fetch fails, the last figure that did arrive is shown instead, marked as
 * stale with its age. It is deliberately not shown as RM 0.00: a false zero on an
 * account balance invites a wrong decision, and "nothing" is not the same as
 * "we could not ask".
 */
final class ChipBalance
{
    /** How long a fetched figure counts as current. */
    private const FRESH_FOR_MINUTES = 5;

    /**
     * How old the fallback figure may get before it stops being shown.
     *
     * Beyond this the sidebar shows nothing rather than a number. "We could not
     * ask" and "here is a figure from two days ago" are different statements, and
     * only one of them is safe to put next to a currency symbol.
     */
    private const MAX_STALE_HOURS = 6;

    public function __construct(private readonly ChipGateway $gateway)
    {
    }

    /**
     * Cache keys, fingerprinted with the credentials they were fetched under.
     *
     * This is what makes a key swap safe. Change the API key and the keys change
     * with it, so the old account's balance is unreachable rather than waiting to
     * be invalidated by someone remembering to call forget(). A test balance shown
     * under a live key looks like a working integration reporting the wrong money,
     * which is worse than showing nothing.
     *
     * @return array{fresh: string, last: string}
     */
    private function keys(): array
    {
        $fingerprint = substr(hash('sha256', (string) PaymentSettings::chipApiKey()), 0, 16);

        return [
            'fresh' => "chip.balance.{$fingerprint}.fresh",
            'last' => "chip.balance.{$fingerprint}.last",
        ];
    }

    /**
     * What the sidebar should draw, or null when there is nothing to draw.
     *
     * @return array{currency: string, amount: float, stale: bool, fetched_at: \Illuminate\Support\Carbon}|null
     */
    public function current(): ?array
    {
        if (! PaymentSettings::isChip() || ! $this->gateway->isConfigured()) {
            return null;
        }

        ['fresh' => $freshKey, 'last' => $lastKey] = $this->keys();

        $last = Cache::get($lastKey);

        if (Cache::has($freshKey) && is_array($last)) {
            return $this->present($last, stale: false);
        }

        $fetched = $this->fetch();

        if ($fetched !== null) {
            // Held for the maximum staleness rather than forever, so a figure can
            // never outlive the point at which it stops being worth showing.
            Cache::put($lastKey, $fetched, now()->addHours(self::MAX_STALE_HOURS));
            Cache::put($freshKey, true, now()->addMinutes(self::FRESH_FOR_MINUTES));

            return $this->present($fetched, stale: false);
        }

        /*
         | The fetch failed. Hold the failure for a minute so a CHIP outage is not
         | retried on every single page load, then fall back to whatever arrived
         | last, marked stale. Past MAX_STALE_HOURS there is nothing to fall back
         | to, because the entry has expired, and the line disappears instead of
         | quoting an old number.
         */
        Cache::put($freshKey, true, now()->addMinute());

        return is_array($last) ? $this->present($last, stale: true) : null;
    }

    /**
     * Ask CHIP, and reduce the answer to the one currency and one figure shown.
     *
     * @return array{currency: string, minor: int, at: int}|null
     */
    private function fetch(): ?array
    {
        $balances = $this->gateway->fetchBalance();

        if ($balances === null || $balances === []) {
            return null;
        }

        /*
         | CHIP answers with one entry per currency the account holds. This account
         | returns MYR only, but the documented shape includes others, so MYR is
         | preferred and the first entry is used if it is absent rather than
         | assuming a key that may not be there.
         */
        $currency = array_key_exists('MYR', $balances) ? 'MYR' : (string) array_key_first($balances);
        $figures = $balances[$currency] ?? null;

        if (! is_array($figures)) {
            return null;
        }

        /*
         | available_balance, not balance. They are equal while nothing is on its
         | way out, which is why picking the wrong one would look perfectly correct
         | in testing and be wrong the moment a payout is pending. This line answers
         | "what could be withdrawn", so it has to be the available figure.
         */
        $minor = $figures['available_balance'] ?? $figures['balance'] ?? null;

        if (! is_numeric($minor)) {
            return null;
        }

        return [
            'currency' => $currency,
            'minor' => (int) $minor,
            'at' => now()->getTimestamp(),
        ];
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array{currency: string, amount: float, stale: bool, fetched_at: \Illuminate\Support\Carbon}|null
     */
    private function present(array $stored, bool $stale): ?array
    {
        if (! isset($stored['currency'], $stored['minor'], $stored['at'])) {
            return null;
        }

        return [
            'currency' => (string) $stored['currency'],

            // Minor unit to major. 11600 is RM 116.00, and CHIP's own examples
            // include negative balances, so this is signed on purpose.
            'amount' => ((int) $stored['minor']) / 100,

            'stale' => $stale,
            'fetched_at' => now()->setTimestamp((int) $stored['at']),
        ];
    }

    /**
     * Drop the cache so the next read asks CHIP again.
     *
     * Called when the payment credentials are saved. The keys are fingerprinted so
     * a changed key already misses, but the old entries would otherwise sit in the
     * cache store until they expired, and clearing them keeps the store honest.
     */
    public static function forget(): void
    {
        $fingerprint = substr(hash('sha256', (string) PaymentSettings::chipApiKey()), 0, 16);

        Cache::forget("chip.balance.{$fingerprint}.fresh");
        Cache::forget("chip.balance.{$fingerprint}.last");
    }
}
