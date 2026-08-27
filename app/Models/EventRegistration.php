<?php

namespace App\Models;

use App\Support\ParticipantOptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EventRegistration extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_WAITLISTED = 'waitlisted';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING => 'Pending',
        self::STATUS_CONFIRMED => 'Confirmed',
        self::STATUS_WAITLISTED => 'Waitlisted',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    public const PAYMENT_UNPAID = 'unpaid';
    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_FAILED = 'failed';
    public const PAYMENT_REFUNDED = 'refunded';

    public const PAYMENT_STATUSES = [
        self::PAYMENT_UNPAID => 'Unpaid',
        self::PAYMENT_PENDING => 'Awaiting Payment',
        self::PAYMENT_PAID => 'Paid',
        self::PAYMENT_FAILED => 'Failed',
        self::PAYMENT_REFUNDED => 'Refunded',
    ];

    protected $fillable = [
        'event_id',
        'reference',
        'mode',
        'team_name',
        'logo_path',
        'status',
        'payment_status',
        'payment_reference',
        'payment_details',
        'payment_synced_at',
        'registration_fee',
        'addons_total',
        'amount',
        'notes',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'registration_fee' => 'decimal:2',
            'addons_total' => 'decimal:2',
            'amount' => 'decimal:2',
            'payment_details' => 'array',
            'payment_synced_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(EventParticipant::class);
    }

    public function managers(): HasMany
    {
        return $this->participants()->where('role', ParticipantOptions::ROLE_MANAGER);
    }

    public function players(): HasMany
    {
        return $this->participants()->where('role', ParticipantOptions::ROLE_PLAYER);
    }

    /** Extras bought alongside the registration, one row per size or option. */
    public function addonLines(): HasMany
    {
        return $this->hasMany(EventRegistrationAddon::class, 'event_registration_id');
    }

    /** Every message sent about this entry, newest first. */
    public function notifications(): HasMany
    {
        return $this->hasMany(EventNotification::class, 'event_registration_id')->latest('id');
    }

    /* ---------------------------------------------------------------------
     | Logo
     * ------------------------------------------------------------------ */

    public function hasLogo(): bool
    {
        return filled($this->logo_path);
    }

    public function logoUrl(): ?string
    {
        return $this->hasLogo()
            ? Storage::disk('public')->url($this->logo_path)
            : null;
    }

    /* ---------------------------------------------------------------------
     | Attendance
     * ------------------------------------------------------------------ */

    /** Arrivals recorded against this entry, one per person who turned up. */
    public function attendances(): HasMany
    {
        return $this->hasMany(EventAttendance::class, 'event_registration_id');
    }

    /** Players substituted at the counter. */
    public function participantChanges(): HasMany
    {
        return $this->hasMany(EventParticipantChange::class, 'event_registration_id')->latest();
    }

    /**
     * How many of the people named have arrived.
     *
     * @return array{0: int, 1: int} checked in, expected
     */
    public function attendanceCount(): array
    {
        $expected = $this->relationLoaded('participants')
            ? $this->participants->count()
            : $this->participants()->count();

        $arrived = $this->relationLoaded('attendances')
            ? $this->attendances->count()
            : $this->attendances()->count();

        return [$arrived, $expected];
    }

    public function isFullyCheckedIn(): bool
    {
        [$arrived, $expected] = $this->attendanceCount();

        return $expected > 0 && $arrived >= $expected;
    }

    /**
     * Whether the counter should be warned before letting this entry in.
     *
     * Not a block: a counter may well take cash at the door, or wave through a
     * sponsored place. It is information the operator needs, not a decision the
     * software should make for them.
     */
    public function attendanceWarnings(): array
    {
        $warnings = [];

        if ($this->status === self::STATUS_CANCELLED) {
            $warnings[] = 'This registration was cancelled.';
        }

        if (! $this->isFree() && ! $this->isPaid()) {
            $warnings[] = sprintf('Payment is %s. %s outstanding.', $this->paymentStatusLabel(), $this->amountLabel());
        }

        if ($this->status === self::STATUS_WAITLISTED) {
            $warnings[] = 'This registration is on the waiting list.';
        }

        return $warnings;
    }

    /* ---------------------------------------------------------------------
     | Money
     * ------------------------------------------------------------------ */

    public function isFree(): bool
    {
        return (float) $this->amount <= 0;
    }

    public function amountLabel(): string
    {
        return 'RM ' . number_format((float) $this->amount, 2);
    }

    public function registrationFeeLabel(): string
    {
        return 'RM ' . number_format((float) $this->registration_fee, 2);
    }

    public function addonsTotalLabel(): string
    {
        return 'RM ' . number_format((float) $this->addons_total, 2);
    }

    public function isPaid(): bool
    {
        return $this->payment_status === self::PAYMENT_PAID;
    }

    /**
     * Whether the visitor should still be sent to the gateway.
     *
     * A refund is deliberately not payable again: re-opening it would let a
     * refunded registration quietly become paid without anyone deciding so.
     */
    public function awaitingPayment(): bool
    {
        return ! $this->isFree()
            && in_array($this->payment_status, [self::PAYMENT_UNPAID, self::PAYMENT_PENDING, self::PAYMENT_FAILED], true)
            && $this->status !== self::STATUS_CANCELLED;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function paymentStatusLabel(): string
    {
        return self::PAYMENT_STATUSES[$this->payment_status] ?? $this->payment_status;
    }

    /**
     * Name to show in a list: the team if there is one, otherwise the first
     * person named on the registration.
     */
    public function displayName(): string
    {
        if (filled($this->team_name)) {
            return $this->team_name;
        }

        return $this->participants->first()?->full_name ?? $this->reference;
    }

    /**
     * Sequential, human readable reference such as REG-2026-0007.
     *
     * Generated inside a transaction with a locking read so two simultaneous
     * submissions cannot claim the same number.
     */
    public static function nextReference(): string
    {
        $year = now()->format('Y');
        $prefix = "REG-{$year}-";

        return DB::transaction(function () use ($prefix) {
            $last = static::query()
                ->where('reference', 'like', $prefix . '%')
                ->lockForUpdate()
                ->orderByDesc('reference')
                ->value('reference');

            $next = $last === null
                ? 1
                : ((int) substr($last, strlen($prefix))) + 1;

            return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        });
    }
}
