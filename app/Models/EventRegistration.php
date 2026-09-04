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

    /**
     * Some of the charge has arrived and some has not.
     *
     * Its own status rather than a flavour of paid or of unpaid, because it
     * behaves like neither. A part-paid entry must not appear in the takings as
     * settled, and must not be chased for the whole fee either. Everything that
     * used to ask "is it paid" now has a third answer to consider, which is the
     * point: leaving it as unpaid would hide money that has genuinely arrived.
     */
    public const PAYMENT_PARTIAL = 'partial';

    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_FAILED = 'failed';
    public const PAYMENT_REFUNDED = 'refunded';

    public const PAYMENT_STATUSES = [
        self::PAYMENT_UNPAID => 'Unpaid',
        self::PAYMENT_PENDING => 'Awaiting Payment',
        self::PAYMENT_PARTIAL => 'Partly Paid',
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
        'amount_paid',
        'refunded_amount',
        'refunded_at',
        'refund_reason',
        'notes',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'registration_fee' => 'decimal:2',
            'addons_total' => 'decimal:2',
            'amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'payment_details' => 'array',
            'payment_synced_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /* ---------------------------------------------------------------------
     | Refunds
     * ------------------------------------------------------------------ */

    /** Anything at all came back. */
    public function isRefunded(): bool
    {
        return (float) $this->refunded_amount > 0;
    }

    /**
     * The whole charge came back.
     *
     * Compared with a tolerance because both sides are decimals and a half cent of
     * float drift would otherwise leave a fully refunded entry looking partial.
     */
    public function isFullyRefunded(): bool
    {
        return (float) $this->refunded_amount >= ((float) $this->amount - 0.001);
    }

    public function isPartiallyRefunded(): bool
    {
        return $this->isRefunded() && ! $this->isFullyRefunded();
    }

    /** What is left of the charge after refunds. */
    public function netAmount(): float
    {
        return max(0, (float) $this->amount - (float) $this->refunded_amount);
    }

    /**
     * What could still be sent back, as far as our own records know.
     *
     * CHIP is the authority on this and reports `refundable_amount` on the purchase.
     * This is the local guard, so a second refund cannot be submitted for more than
     * the entry was ever worth even before CHIP is asked.
     */
    public function refundableAmount(): float
    {
        return $this->isPaid() || $this->isRefunded() ? $this->netAmount() : 0.0;
    }

    public function refundedAmountLabel(): string
    {
        return 'RM ' . number_format((float) $this->refunded_amount, 2);
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

    /**
     * Everyone holding a playing place, which includes a manager who also plays.
     *
     * Uses the scope rather than role = 'player' so a manager on the roster is
     * counted here as well. See EventParticipant::isPlaying().
     */
    public function players(): HasMany
    {
        return $this->participants()->playing();
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

        /*
         | The balance, not the charge. Quoting amountLabel() here told the counter
         | that a team who had already transferred RM 200 of RM 250 still owed the
         | whole RM 250, which is the sort of thing that gets argued about at a desk
         | with a queue behind it.
         */
        if (! $this->isFree() && ! $this->isPaid()) {
            $warnings[] = sprintf(
                'Payment is %s. %s outstanding.',
                $this->paymentStatusLabel(),
                $this->outstandingAmountLabel(),
            );
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
     *
     * A part-paid entry is deliberately excluded as well, and this is the one
     * subtle answer here. The gateway checkout is built from the full charge, so
     * sending somebody who has already transferred half of it would take the whole
     * fee a second time. Money that started arriving by hand is settled by hand;
     * owesBalance() is what the reminder and the counter ask instead.
     */
    public function awaitingPayment(): bool
    {
        return ! $this->isFree()
            && in_array($this->payment_status, [self::PAYMENT_UNPAID, self::PAYMENT_PENDING, self::PAYMENT_FAILED], true)
            && $this->status !== self::STATUS_CANCELLED;
    }

    /* ---------------------------------------------------------------------
     | What has actually arrived
     |
     | Three separate figures, and confusing any two of them is how a set of books
     | starts lying: `amount` is owed, `amount_paid` is received, `refunded_amount`
     | went back out again.
     * ------------------------------------------------------------------ */

    /** Every receipt against this entry, newest first. */
    public function payments(): HasMany
    {
        return $this->hasMany(EventRegistrationPayment::class, 'event_registration_id')
            ->orderByDesc('received_at')
            ->orderByDesc('id');
    }

    public function amountPaid(): float
    {
        return (float) $this->amount_paid;
    }

    /**
     * What is still owed.
     *
     * Floored at zero so an overpayment recorded by hand cannot present itself as
     * a negative debt, which would then be subtracted from the outstanding total
     * across the whole event and quietly reduce what other people owe.
     */
    public function outstandingAmount(): float
    {
        return max(0, (float) $this->amount - (float) $this->amount_paid);
    }

    public function amountPaidLabel(): string
    {
        return 'RM ' . number_format($this->amountPaid(), 2);
    }

    public function outstandingAmountLabel(): string
    {
        return 'RM ' . number_format($this->outstandingAmount(), 2);
    }

    /** Something arrived, but not all of it. */
    public function isPartlyPaid(): bool
    {
        return $this->payment_status === self::PAYMENT_PARTIAL;
    }

    /**
     * Whether money is still owed on this entry.
     *
     * Broader than awaitingPayment(): it also covers a part-paid entry, which owes
     * a balance but must not be sent to the gateway. This is what a reminder, the
     * attendance counter and the record-a-payment control should ask.
     *
     * Compared with half a cent of tolerance because both sides are decimals, and
     * an entry settled to the cent would otherwise read as owing a rounding error.
     */
    public function owesBalance(): bool
    {
        return ! $this->isFree()
            && $this->status !== self::STATUS_CANCELLED
            && $this->payment_status !== self::PAYMENT_REFUNDED
            && $this->outstandingAmount() > 0.005;
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
