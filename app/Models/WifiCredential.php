<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One competitor's Wi-Fi login for one event.
 *
 * Three states worth telling apart, and the reason this model carries two nullable
 * timestamps rather than a single status column:
 *
 * Issued. The row exists. The login is decided but the router has never heard of it, so
 * typing it in would fail.
 *
 * Provisioned. The router has fetched it. The login now works.
 *
 * Delivered. The competitor has been told. Until this, a working login is sitting in a
 * database nobody can see.
 *
 * Those happen in that order and a person can be stuck at any of them, which is why the
 * admin screen needs all three separately. Collapsing them into one status would hide
 * the only distinction that matters when somebody says the Wi-Fi does not work.
 */
class WifiCredential extends Model
{
    protected $fillable = [
        'event_id',
        'event_registration_id',
        'event_participant_id',
        'username',
        'password',
        'expires_on',
        'provisioned_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_on' => 'date',
            'provisioned_at' => 'datetime',
            'delivered_at' => 'datetime',

            /*
             | Encrypted at rest.
             |
             | It cannot be hashed: the router needs the real password to create the
             | account and the competitor needs to be told what to type. Encryption is
             | the only protection left, and it earns its place against the realistic
             | threat, which is a copy of the database rather than an attacker with a
             | live connection. Without it these rows are a list of working logins in
             | plain text.
             */
            'password' => 'encrypted',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     * ------------------------------------------------------------------ */

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'event_registration_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(EventParticipant::class, 'event_participant_id');
    }

    /* ---------------------------------------------------------------------
     | Scopes
     * ------------------------------------------------------------------ */

    /**
     * Credentials that still have a day left to run.
     *
     * Compared against today rather than filtered in PHP, so the export cannot carry a
     * dead login onto the router and the admin screen does not pad its counts with rows
     * from an event that finished last month.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereDate('expires_on', '>=', now()->toDateString());
    }

    /**
     * Only the ones belonging to a registration that is actually coming.
     *
     * A cancelled entry keeps its row, because deleting it would lose the record that a
     * login was once issued and possibly printed. What it must not do is reach the
     * router: somebody who withdrew should not still be able to get on the network.
     *
     * Waitlisted entries are held back for the same reason. They have not been given a
     * place, so they have not been given the Wi-Fi that comes with one.
     */
    public function scopeForAttending(Builder $query): Builder
    {
        return $query->whereHas('registration', fn (Builder $inner) => $inner
            ->whereNotIn('status', [
                EventRegistration::STATUS_CANCELLED,
                EventRegistration::STATUS_WAITLISTED,
            ]));
    }

    public function scopeUndelivered(Builder $query): Builder
    {
        return $query->whereNull('delivered_at');
    }

    /* ---------------------------------------------------------------------
     | State
     * ------------------------------------------------------------------ */

    /** Whether the router has a copy, and therefore whether this login works. */
    public function isProvisioned(): bool
    {
        return $this->provisioned_at !== null;
    }

    public function isDelivered(): bool
    {
        return $this->delivered_at !== null;
    }

    public function hasExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->isPast()
            && ! $this->expires_on->isToday();
    }

    /**
     * One honest sentence about where this credential stands.
     *
     * Ordered by what would mislead most if it were left out. Expired first, because a
     * login that has run out is not "delivered" in any useful sense. Then not
     * provisioned, because a competitor holding a slip that cannot possibly work is the
     * failure most likely to be blamed on the password.
     */
    public function state(): string
    {
        if ($this->hasExpired()) {
            return 'Expired';
        }

        if (! $this->isProvisioned()) {
            return 'Not on the router yet';
        }

        return $this->isDelivered() ? 'Working, and sent' : 'Working, not sent';
    }
}
