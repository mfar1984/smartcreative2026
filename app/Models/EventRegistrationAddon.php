<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a registration's add-on order.
 *
 * The name, variant label and unit price are copied in at purchase time rather
 * than read back through the relation. An invoice must keep saying what was
 * actually bought and charged even after the organiser renames a shirt or
 * changes its price.
 */
class EventRegistrationAddon extends Model
{
    protected $fillable = [
        'event_registration_id',
        'event_participant_id',
        'event_addon_id',
        'event_addon_variant_id',
        'name',
        'variant_label',
        'unit_price',
        'quantity',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'quantity' => 'integer',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'event_registration_id');
    }

    /**
     * Who this was chosen for, or null on a bulk line.
     *
     * Null is the ordinary case: most add-ons are ordered as a quantity for the
     * whole entry, and only the ones marked per_participant name a person.
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(EventParticipant::class, 'event_participant_id');
    }

    public function isForOnePerson(): bool
    {
        return $this->event_participant_id !== null;
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(EventAddon::class, 'event_addon_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(EventAddonVariant::class, 'event_addon_variant_id');
    }

    /**
     * How the line reads on an invoice, for example "Event Shirt (Size M)".
     */
    public function describe(): string
    {
        return filled($this->variant_label)
            ? "{$this->name} ({$this->variant_label})"
            : $this->name;
    }

    public function unitPriceLabel(): string
    {
        return 'RM ' . number_format((float) $this->unit_price, 2);
    }

    public function lineTotalLabel(): string
    {
        return 'RM ' . number_format((float) $this->line_total, 2);
    }
}
