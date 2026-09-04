<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One receipt against a registration.
 *
 * The record of a single arrival of money, not of the registration's balance. The
 * balance is the sum of these, kept on the parent as `amount_paid` so the money
 * screens stay simple column sums.
 *
 * Rows are never edited or deleted. A receipt entered wrongly is corrected by
 * recording the opposite, the same way a ledger is corrected, because a row that
 * can be rewritten is not evidence of anything.
 */
class EventRegistrationPayment extends Model
{
    /** Observed by the gateway. Nobody asserted it. */
    public const SOURCE_GATEWAY = 'gateway';

    /** Asserted by a member of staff who saw the money. */
    public const SOURCE_MANUAL = 'manual';

    public const SOURCES = [
        self::SOURCE_GATEWAY => 'Gateway',
        self::SOURCE_MANUAL => 'Recorded by hand',
    ];

    protected $fillable = [
        'event_registration_id',
        'amount',
        'received_at',
        'reference',
        'note',
        'source',
        'recorded_by',
        'actor_label',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'received_at' => 'datetime',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'event_registration_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function amountLabel(): string
    {
        return 'RM ' . number_format((float) $this->amount, 2);
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? $this->source;
    }

    public function isManual(): bool
    {
        return $this->source === self::SOURCE_MANUAL;
    }

    /**
     * Who to credit for the row.
     *
     * The stored label wins over the relation so the trail survives the account
     * being deleted. A gateway payment has neither, and says so rather than
     * showing an empty cell.
     */
    public function actor(): string
    {
        return $this->actor_label
            ?? $this->recordedBy?->name
            ?? ($this->isManual() ? 'Recorded by hand' : 'Gateway');
    }
}
