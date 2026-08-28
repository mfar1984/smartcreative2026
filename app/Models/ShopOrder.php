<?php

namespace App\Models;

use App\Support\PaymentFigures;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * One shop order.
 *
 * Carries its own copy of the buyer's details and of every line, so the record of
 * what happened does not change when a product is edited or a customer moves house.
 */
class ShopOrder extends Model
{
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_PAID = 'paid';
    public const STATUS_PACKING = 'packing';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    /**
     * Status slug => label.
     */
    public const STATUSES = [
        self::STATUS_PENDING_PAYMENT => 'Pending Payment',
        self::STATUS_PAID => 'Paid',
        self::STATUS_PACKING => 'Packing',
        self::STATUS_SHIPPED => 'Shipped',
        self::STATUS_DELIVERED => 'Delivered',
        self::STATUS_CANCELLED => 'Cancelled',
        self::STATUS_REFUNDED => 'Refunded',
    ];

    /**
     * Where an order may go next.
     *
     * Held as data rather than as conditionals scattered through the controllers, so
     * there is one answer to "can this move there" and the buttons on screen cannot
     * offer a transition the server would refuse.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING_PAYMENT => [self::STATUS_PAID, self::STATUS_CANCELLED],
        self::STATUS_PAID => [self::STATUS_PACKING, self::STATUS_CANCELLED, self::STATUS_REFUNDED],
        self::STATUS_PACKING => [self::STATUS_SHIPPED, self::STATUS_CANCELLED, self::STATUS_REFUNDED],
        self::STATUS_SHIPPED => [self::STATUS_DELIVERED, self::STATUS_REFUNDED],
        self::STATUS_DELIVERED => [self::STATUS_REFUNDED],

        // Both are ends. Reopening one would let an order quietly come back to life
        // without anybody deciding so.
        self::STATUS_CANCELLED => [],
        self::STATUS_REFUNDED => [],
    ];

    public const METHOD_GATEWAY = 'gateway';
    public const METHOD_COD = 'cod';
    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const METHODS = [
        self::METHOD_GATEWAY => 'Card or online banking',
        self::METHOD_COD => 'Cash on delivery',
        self::METHOD_BANK_TRANSFER => 'Bank transfer',
    ];

    protected $fillable = [
        'reference',
        'status',
        'payment_method',
        'payment_reference',
        'payment_details',
        'paid_at',
        'customer_name',
        'customer_email',
        'customer_phone',
        'address_line_1',
        'address_line_2',
        'postcode',
        'city',
        'state',
        'country',
        'items_total',
        'shipping_total',
        'grand_total',
        'shipping_label',
        'courier_name',
        'tracking_number',
        'tracking_url',
        'shipped_at',
        'delivered_at',
        'received_confirmed_at',
        'received_confirmed_ip',
        'refunded_amount',
        'refunded_at',
        'refund_reason',
        'notes',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'payment_details' => 'array',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'received_confirmed_at' => 'datetime',
            'refunded_at' => 'datetime',
            'items_total' => 'decimal:2',
            'shipping_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     * ------------------------------------------------------------------ */

    public function items(): HasMany
    {
        return $this->hasMany(ShopOrderItem::class)->orderBy('id');
    }

    /** The status trail, oldest first, because it reads as a history. */
    public function events(): HasMany
    {
        return $this->hasMany(ShopOrderEvent::class)->orderBy('id');
    }

    /* ---------------------------------------------------------------------
     | Status
     * ------------------------------------------------------------------ */

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->payment_method] ?? $this->payment_method;
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    public function isPendingPayment(): bool
    {
        return $this->status === self::STATUS_PENDING_PAYMENT;
    }

    /**
     * Whether somebody still has to confirm the money arrived by hand.
     *
     * Cash on delivery and a bank transfer both land outside this system, so neither
     * can mark itself paid.
     */
    public function awaitsManualPayment(): bool
    {
        return $this->isPendingPayment()
            && in_array($this->payment_method, [self::METHOD_COD, self::METHOD_BANK_TRANSFER], true);
    }

    /**
     * Statuses this order may be moved to right now.
     *
     * @return array<int, string>
     */
    public function allowedTransitions(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    /** An end state: nothing more happens to it. */
    public function isClosed(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /* ---------------------------------------------------------------------
     | Money
     * ------------------------------------------------------------------ */

    public function itemsTotalLabel(): string
    {
        return PaymentFigures::money((float) $this->items_total);
    }

    public function shippingTotalLabel(): string
    {
        return (float) $this->shipping_total <= 0
            ? 'Free'
            : PaymentFigures::money((float) $this->shipping_total);
    }

    public function grandTotalLabel(): string
    {
        return PaymentFigures::money((float) $this->grand_total);
    }

    public function isRefunded(): bool
    {
        return (float) $this->refunded_amount > 0;
    }

    /**
     * The whole charge came back.
     *
     * Compared with a tolerance because both sides are decimals and half a cent of
     * drift would leave a fully refunded order looking partial.
     */
    public function isFullyRefunded(): bool
    {
        return (float) $this->refunded_amount >= ((float) $this->grand_total - 0.001);
    }

    /** What is left of the charge after refunds. */
    public function netAmount(): float
    {
        return max(0, (float) $this->grand_total - (float) $this->refunded_amount);
    }

    /* ---------------------------------------------------------------------
     | Delivery
     * ------------------------------------------------------------------ */

    /** Total weight of the parcel, from the lines rather than the products. */
    public function weightGrams(): int
    {
        return (int) $this->items->sum(
            fn (ShopOrderItem $item) => (int) $item->weight_grams * $item->quantity
        );
    }

    public function itemCount(): int
    {
        return (int) $this->items->sum('quantity');
    }

    /** The delivery address on one line, for a list. */
    public function addressLine(): string
    {
        return collect([
            $this->address_line_1,
            $this->address_line_2,
            $this->postcode . ' ' . $this->city,
            $this->state,
        ])->filter()->implode(', ');
    }

    /** Whether the buyer has said the parcel arrived. */
    public function isReceiptConfirmed(): bool
    {
        return $this->received_confirmed_at !== null;
    }

    /* ---------------------------------------------------------------------
     | Scopes
     * ------------------------------------------------------------------ */

    public function scopeAwaitingPayment(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING_PAYMENT);
    }

    /** Orders that owe somebody a parcel. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_PAID,
            self::STATUS_PACKING,
            self::STATUS_SHIPPED,
        ]);
    }

    /* ---------------------------------------------------------------------
     | Reference
     * ------------------------------------------------------------------ */

    /**
     * Sequential, human readable reference such as SO-2026-0007.
     *
     * Same approach as EventRegistration::nextReference(): generated inside a
     * transaction with a locking read, so two simultaneous checkouts cannot claim
     * the same number.
     */
    public static function nextReference(): string
    {
        $year = now()->format('Y');
        $prefix = "SO-{$year}-";

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
