<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Somebody a campaign could reach, once.
 *
 * Deduplicated across every registration they have ever made, because a person
 * who entered three events is one person and must be sent one message.
 */
class CampaignContact extends Model
{
    public const SOURCE_REGISTRATION = 'registration';
    public const SOURCE_ENQUIRY = 'enquiry';
    public const SOURCE_ADMIN = 'admin';

    protected $fillable = [
        'email',
        'phone',
        'name',
        'consent_email',
        'consent_sms',
        'consented_at',
        'consent_source',
        'consent_ip',
        'unsubscribed_at',
        'unsubscribe_reason',
        'bounced_at',
        'bounce_reason',
        'complained_at',
        'token',
        'first_event_id',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'consent_email' => 'boolean',
            'consent_sms' => 'boolean',
            'consented_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'bounced_at' => 'datetime',
            'complained_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Every contact needs one before it can appear in an unsubscribe link,
        // and forgetting to set it would produce a link that unsubscribes nobody.
        static::creating(function (self $contact) {
            $contact->token ??= Str::random(48);
        });
    }

    public function firstEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'first_event_id');
    }

    /* ---------------------------------------------------------------------
     | Suppression
     * ------------------------------------------------------------------ */

    /**
     * Whether this contact must never be sent to again, whatever the consent
     * columns say.
     *
     * Deliberately checked before consent everywhere. Somebody who asked to be
     * left alone has said something more considered than a manager ticking a box
     * on their behalf, so the request wins.
     */
    public function isSuppressed(): bool
    {
        return $this->unsubscribed_at !== null
            || $this->bounced_at !== null
            || $this->complained_at !== null;
    }

    public function suppressionReason(): ?string
    {
        if ($this->unsubscribed_at !== null) {
            return 'Unsubscribed ' . $this->unsubscribed_at->format('d M Y');
        }

        if ($this->bounced_at !== null) {
            return 'Address bounced: ' . ($this->bounce_reason ?: 'no reason recorded');
        }

        if ($this->complained_at !== null) {
            return 'Marked as a complaint ' . $this->complained_at->format('d M Y');
        }

        return null;
    }

    public function canReceiveEmail(): bool
    {
        return $this->consent_email && filled($this->email) && ! $this->isSuppressed();
    }

    public function canReceiveSms(): bool
    {
        return $this->consent_sms && filled($this->phone) && ! $this->isSuppressed();
    }

    /* ---------------------------------------------------------------------
     | Scopes
     * ------------------------------------------------------------------ */

    /** Reachable on a channel: consented, addressable, and not suppressed. */
    public function scopeReachable(Builder $query, string $channel): Builder
    {
        $consent = $channel === 'sms' ? 'consent_sms' : 'consent_email';

        return $query->where($consent, true)->pickable($channel);
    }

    /**
     * Addressable on a channel and not on the suppression list.
     *
     * Consent is deliberately not required here. This is the set an operator may
     * choose from by hand, where the choosing is itself the decision and the screen
     * shows which of them agreed on the form.
     *
     * Suppression is still absolute. Not ticking a box is an absence of an answer;
     * unsubscribing, bouncing or reporting as spam is an answer, and no amount of
     * clicking in the admin area may overrule it.
     */
    public function scopePickable(Builder $query, string $channel): Builder
    {
        $column = $channel === 'sms' ? 'phone' : 'email';

        return $query
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->whereNull('unsubscribed_at')
            ->whereNull('bounced_at')
            ->whereNull('complained_at');
    }

    public function scopeSuppressed(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNotNull('unsubscribed_at')
            ->orWhereNotNull('bounced_at')
            ->orWhereNotNull('complained_at'));
    }

    /* ---------------------------------------------------------------------
     | Writing
     * ------------------------------------------------------------------ */

    /**
     * Find or create the contact behind one person, and fold in what is known.
     *
     * Matched on email first, then on the normalised phone, because a person who
     * gave an address on one entry and only a phone on another is still one
     * person. Whichever identifier was missing is filled in.
     *
     * Consent is only ever turned on here, never off: a later entry without the
     * box ticked does not withdraw an agreement already given. Withdrawing is
     * what unsubscribe is for.
     */
    public static function absorb(
        ?string $email,
        ?string $phone,
        ?string $name,
        bool $consented,
        string $source,
        ?string $ip = null,
        ?int $eventId = null,
    ): ?self {
        $email = filled($email) ? mb_strtolower(trim($email)) : null;
        $phone = PhoneNumber::toInternational($phone);

        if ($email === null && $phone === null) {
            return null;
        }

        $contact = null;

        if ($email !== null) {
            $contact = static::where('email', $email)->first();
        }

        if ($contact === null && $phone !== null) {
            $contact = static::where('phone', $phone)->first();
        }

        if ($contact === null) {
            $contact = new self([
                'email' => $email,
                'phone' => $phone,
                'name' => $name,
                'first_event_id' => $eventId,
            ]);
        }

        // Fill gaps without overwriting: a name already on file may be better
        // than the one on this entry, and a second identifier is new information.
        $contact->email ??= $email;
        $contact->phone ??= $phone;
        $contact->name = $contact->name ?: $name;
        $contact->first_event_id ??= $eventId;
        $contact->last_seen_at = now();

        if ($consented) {
            $contact->consent_email = $contact->consent_email || filled($contact->email);
            $contact->consent_sms = $contact->consent_sms || filled($contact->phone);
            $contact->consented_at ??= now();
            $contact->consent_source ??= $source;
            $contact->consent_ip ??= $ip;
        }

        $contact->save();

        return $contact;
    }

    public function unsubscribe(string $reason = 'Asked to stop through the link in a message'): void
    {
        if ($this->unsubscribed_at !== null) {
            return;
        }

        $this->update([
            'unsubscribed_at' => now(),
            'unsubscribe_reason' => $reason,
            // Cleared as well as timestamped. The scope checks the timestamp, but
            // a screen reading the flags should not show an agreement that has
            // been withdrawn.
            'consent_email' => false,
            'consent_sms' => false,
        ]);
    }

    public function label(): string
    {
        return $this->name ?: ($this->email ?: $this->phone ?: 'Unknown');
    }
}
