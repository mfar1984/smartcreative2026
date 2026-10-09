<?php

namespace App\Models;

use App\Support\PaymentFigures;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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

    /**
     * The same lifecycle for an order collected at a counter.
     *
     * Packing and shipped are gone rather than optional. There is no parcel and no
     * courier, so an order sitting in "shipped" would be a lie, and leaving the
     * statuses available would invite somebody to set one. Paid goes straight to
     * delivered, which for a counter handover is the moment it is handed over.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS_OFFLINE = [
        self::STATUS_PENDING_PAYMENT => [self::STATUS_PAID, self::STATUS_CANCELLED],
        self::STATUS_PAID => [self::STATUS_DELIVERED, self::STATUS_CANCELLED, self::STATUS_REFUNDED],
        self::STATUS_DELIVERED => [self::STATUS_REFUNDED],
        self::STATUS_CANCELLED => [],
        self::STATUS_REFUNDED => [],
    ];

    public const FULFILMENT_ONLINE = 'online';
    public const FULFILMENT_OFFLINE = 'offline';

    /**
     * Fulfilment slug => label.
     */
    public const FULFILMENTS = [
        self::FULFILMENT_ONLINE => 'Posted',
        self::FULFILMENT_OFFLINE => 'Collected',
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
        'fulfilment',
        'payment_method',
        'payment_reference',
        'paid_purchase_id',
        'payment_details',
        'payment_synced_at',
        'payment_receipt_path',
        'payment_receipt_uploaded_at',
        'payment_link_sent_at',
        'paid_at',
        'customer_name',
        'customer_email',
        'customer_phone',
        'identity_card',
        'address_line_1',
        'address_line_2',
        'postcode',
        'city',
        'state',
        'country',
        'items_total',
        'discount_total',
        'coupon_code_id',
        'shipping_total',
        'grand_total',
        'shipping_label',
        'collection_event_id',
        'collection_label',
        'collection_location',
        'collection_at',
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
            'payment_synced_at' => 'datetime',
            'payment_receipt_uploaded_at' => 'datetime',
            'payment_link_sent_at' => 'datetime',
            'collection_at' => 'datetime',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'received_confirmed_at' => 'datetime',
            'refunded_at' => 'datetime',
            'items_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
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

    /**
     * The event the goods are collected at, when there was one.
     *
     * Nullable and nullOnDelete: the order keeps its own snapshot of the place and
     * time, so losing this link costs the admin a hyperlink and costs the buyer
     * nothing.
     */
    public function collectionEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'collection_event_id');
    }

    /** The status trail, oldest first, because it reads as a history. */
    public function events(): HasMany
    {
        return $this->hasMany(ShopOrderEvent::class)->orderBy('id');
    }

    /** Every checkout ever opened at the gateway for this order, newest first. */
    public function checkouts(): HasMany
    {
        return $this->hasMany(ShopOrderCheckout::class)->latest('id');
    }

    /**
     * Who physically took the goods, for an order collected at a counter.
     *
     * Null on every order collected before this record existed, and on every posted
     * one. Nothing was backfilled, so the screens ask whether there is a row rather
     * than assuming there is.
     */
    public function handover(): MorphOne
    {
        return $this->morphOne(CollectionHandover::class, 'collectable');
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
     * Whether this order is waiting for money the gateway can collect.
     *
     * Keyed on the payment method and the amount, never on fulfilment — a counter
     * collection paid by card is exactly the case this exists for. The mirror image
     * of awaitsManualPayment(), which answers the same question for cod and
     * bank_transfer.
     */
    public function awaitsGatewayPayment(): bool
    {
        return $this->isPendingPayment()
            && $this->payment_method === self::METHOD_GATEWAY
            && (float) $this->grand_total > 0;
    }

    /* ---------------------------------------------------------------------
     | Chasing the money
     * ------------------------------------------------------------------ */

    /**
     * How long a buyer is left alone after a payment link goes out.
     *
     * Six hours: long enough that a double press, a reload or a second person
     * working the same list cannot mail the same buyer twice in a sitting, short
     * enough that chasing somebody again the same working day is still possible.
     * A fixed figure rather than a setting, because nobody has asked to tune it and
     * a setting nobody changes is one more thing that can be set wrong.
     */
    public const PAYMENT_LINK_COOLDOWN_HOURS = 6;

    /** Whether a payment link went out recently enough that another would be spam. */
    public function paymentLinkRemindedRecently(): bool
    {
        return $this->payment_link_sent_at !== null
            && $this->payment_link_sent_at->greaterThan(self::paymentLinkCooldownCutoff());
    }

    /** When this order may be chased again, or null when it may be chased now. */
    public function paymentLinkCooldownEndsAt(): ?Carbon
    {
        return $this->paymentLinkRemindedRecently()
            ? $this->payment_link_sent_at->copy()->addHours(self::PAYMENT_LINK_COOLDOWN_HOURS)
            : null;
    }

    /**
     * A send at or before this moment is old enough to be ignored.
     *
     * One place for the arithmetic, so the row-level check and the SQL scope cannot
     * disagree about where the window starts.
     */
    public static function paymentLinkCooldownCutoff(): Carbon
    {
        return now()->subHours(self::PAYMENT_LINK_COOLDOWN_HOURS);
    }

    /**
     * Whether this order was settled by this gateway purchase.
     *
     * The question behind it is "is this arriving purchase.paid a replay, or has a
     * second purchase collected money for an order that is already paid?", and it is
     * asked in two places that must not answer it differently: the webhook's decision
     * whether to re-point payment_reference, and applyPaid()'s decision whether a
     * refused move is an incident.
     *
     * It reads paid_purchase_id and nothing else. payment_reference holds the latest
     * attempt and an administrator can type into it; membership of
     * shop_order_checkouts is true from the moment a purchase was opened, which is
     * before any money moved. Both are true for the double-collection shape, so
     * either of them as the test would file a double charge as a harmless replay.
     */
    public function wasSettledBy(string $purchaseId): bool
    {
        return filled($this->paid_purchase_id) && $this->paid_purchase_id === $purchaseId;
    }

    /**
     * What the gateway says it collected, in cents, or null when the figure cannot
     * be trusted.
     *
     * Corroborated against the purchase's own total before it is believed. CHIP is
     * sent our line items and chargePayload() refuses a cent-level mismatch, so if
     * purchase.total does not come back equal to what we asked for, we are reading a
     * field we have misunderstood — a different unit, or a different meaning — rather
     * than watching an underpayment. Returning null in that case makes the caller
     * fall through to "pay it and log that we could not check", because the expensive
     * mistake here is refusing money that really arrived.
     *
     * @param  array<string, mixed>  $payment  the purchase payload, as stored or as received
     * @param  int  $expectedCents  grand_total in cents, computed from the row
     */
    public static function collectedCents(array $payment, int $expectedCents): ?int
    {
        $collected = data_get($payment, 'payment.amount');
        $total = data_get($payment, 'purchase.total');

        if (! is_numeric($collected)) {
            return null;
        }

        // purchase.total agreeing with our own figure is what proves the unit.
        if (! is_numeric($total) || (int) $total !== $expectedCents) {
            return null;
        }

        return (int) $collected;
    }

    /**
     * How far short of the charge the stored gateway record is, in cents, or null
     * when there is nothing to answer: no stored payload, nothing to trust, or the
     * full amount collected.
     *
     * Read by the admin Payment panel so a refused payment is visible on the order
     * rather than only in a log nobody opens.
     */
    public function gatewayShortfallCents(): ?int
    {
        /*
         | A paid order is not short of anything.
         |
         | The recovery path after a refused short collection is Confirm Payment with
         | a note, and neither confirmPayment() nor ShopOrderWriter::moveTo() clears
         | payment_details — so without this guard the order would read Paid at the
         | top of the Payment panel and "has not been marked paid" three lines below,
         | pointing at a Confirm Payment panel that no longer renders. The figures are
         | not lost: they stay in the order history and in the activity log.
         |
         | isPaid() is paid_at !== null, so this also covers a paid order later
         | refunded — which is right: that one is a refund story, not a shortfall.
         */
        if ($this->isPaid()) {
            return null;
        }

        $payment = $this->payment_details;

        if (! is_array($payment) || $payment === []) {
            return null;
        }

        $expected = (int) round((float) $this->grand_total * 100);
        $collected = self::collectedCents($payment, $expected);

        return $collected !== null && $collected < $expected
            ? $expected - $collected
            : null;
    }

    /* ---------------------------------------------------------------------
     | Posted out, or collected in person
     * ------------------------------------------------------------------ */

    public function isOffline(): bool
    {
        return $this->fulfilment === self::FULFILMENT_OFFLINE;
    }

    public function isOnline(): bool
    {
        return ! $this->isOffline();
    }

    public function fulfilmentLabel(): string
    {
        return self::FULFILMENTS[$this->fulfilment] ?? $this->fulfilment;
    }

    /**
     * Where and when this order is handed over, read from the order's own snapshot.
     *
     * Never resolved through the event, even when the key is still there. The buyer
     * was told a place and a time, and a venue change afterwards does not rewrite
     * what they were promised.
     */
    public function collectionSummary(): ?string
    {
        if (! $this->isOffline()) {
            return null;
        }

        return collect([
            $this->collection_location,
            $this->collection_at ? \App\Support\LocalTime::formatWallClock($this->collection_at) : null,
        ])->filter()->join(', ') ?: $this->collection_label;
    }

    /** Handed over at the counter. The same moment as delivered, for a posted order. */
    public function isCollected(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    /**
     * Paid for, but still sitting with us.
     *
     * The row-level half of the figure the orders list has always shown at the top of
     * the Offline tab: scopeOpen() counts a paid offline order as waiting to be
     * collected, because the only thing left to happen to it is the handover. Written
     * as a predicate so the Hand Over cell and the confirm-collection action read the
     * same answer that counter does, instead of each deciding for itself and
     * disagreeing on the same row.
     *
     * canMoveTo() carries the rest of the conditions without naming them twice:
     * TRANSITIONS_OFFLINE allows delivered out of paid and out of nowhere else, so an
     * order already collected, cancelled or fully refunded is false here. isPaid()
     * reads paid_at rather than the status, so a row whose status was moved without
     * the money ever landing cannot be handed over.
     */
    public function awaitsCollection(): bool
    {
        return $this->isOffline()
            && $this->isPaid()
            && $this->canMoveTo(self::STATUS_DELIVERED);
    }

    /* ---------------------------------------------------------------------
     | Proof of a bank transfer
     * ------------------------------------------------------------------ */

    /**
     * Whether this order is one where the buyer has to send proof of payment.
     *
     * Cash on delivery is excluded: the money is handed over with the parcel, so
     * there is nothing to upload beforehand.
     */
    public function needsPaymentReceipt(): bool
    {
        return $this->payment_method === self::METHOD_BANK_TRANSFER && ! $this->isPaid();
    }

    public function hasPaymentReceipt(): bool
    {
        return filled($this->payment_receipt_path);
    }

    public function paymentReceiptUrl(): ?string
    {
        return $this->hasPaymentReceipt()
            ? Storage::disk('public')->url($this->payment_receipt_path)
            : null;
    }

    /**
     * Whether the uploaded receipt is a picture rather than a PDF.
     *
     * Used only to decide whether it can be shown inline in the admin, so somebody
     * verifying a transfer can read it without downloading a file first.
     */
    public function paymentReceiptIsImage(): bool
    {
        return $this->hasPaymentReceipt()
            && in_array(
                strtolower(pathinfo((string) $this->payment_receipt_path, PATHINFO_EXTENSION)),
                ['jpg', 'jpeg', 'png', 'webp'],
                true,
            );
    }

    /**
     * Statuses this order may be moved to right now.
     *
     * Which map applies depends on how the order is fulfilled, because a counter
     * handover has no packing or shipping step to pass through.
     *
     * @return array<int, string>
     */
    public function allowedTransitions(): array
    {
        $map = $this->isOffline() ? self::TRANSITIONS_OFFLINE : self::TRANSITIONS;

        return $map[$this->status] ?? [];
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

    /* ---------------------------------------------------------------------
     | Coupons
     |
     | The discount comes off the ITEMS and never off the postage: a courier charges
     | what it charges whether or not the buyer had a code.
     |
     |     grand_total = items_total - discount_total + shipping_total
     |
     | with the discount capped at items_total, so the total can never go negative and
     | never eat the delivery charge.
     * ------------------------------------------------------------------ */

    public function couponCode(): BelongsTo
    {
        return $this->belongsTo(CouponCode::class, 'coupon_code_id');
    }

    public function hasDiscount(): bool
    {
        return (float) $this->discount_total > 0.005;
    }

    public function discountTotalLabel(): string
    {
        return PaymentFigures::money((float) $this->discount_total);
    }

    /** What the items come to once the coupon is off them. */
    public function discountedItemsTotal(): float
    {
        return max(0.0, round((float) $this->items_total - (float) $this->discount_total, 2));
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

    public function refundedLabel(): string
    {
        return PaymentFigures::money((float) $this->refunded_amount);
    }

    public function netLabel(): string
    {
        return PaymentFigures::money($this->netAmount());
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

    /**
     * Orders that still owe the buyer something.
     *
     * A parcel for a posted order, a handover at the counter for a collected one.
     * Paid covers both, which is why it is one scope rather than two.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_PAID,
            self::STATUS_PACKING,
            self::STATUS_SHIPPED,
        ]);
    }

    public function scopeFulfilment(Builder $query, string $fulfilment): Builder
    {
        return $query->where('fulfilment', $fulfilment);
    }

    /**
     * Orders a payment link could go out to right now.
     *
     * The SQL mirror of awaitsGatewayPayment() plus an address to send to and the
     * cooldown. Used only to count, so a button can say how many it would email and
     * disappear when the answer is none: the send loop asks
     * ShopPaymentLinkSender::skipReason() about each row, which is the authority and
     * the thing that produces the reason breakdown.
     */
    public function scopeRemindableForPayment(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PENDING_PAYMENT)
            ->whereNull('paid_at')
            ->where('payment_method', self::METHOD_GATEWAY)
            ->where('grand_total', '>', 0)
            ->whereNotNull('customer_email')
            ->where('customer_email', '!=', '')
            ->where(fn (Builder $inner) => $inner
                ->whereNull('payment_link_sent_at')
                ->orWhere('payment_link_sent_at', '<=', self::paymentLinkCooldownCutoff()));
    }

    /** Bank transfers still waiting for somebody to check the money arrived. */
    public function scopeAwaitingReceiptCheck(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PENDING_PAYMENT)
            ->where('payment_method', self::METHOD_BANK_TRANSFER)
            ->whereNotNull('payment_receipt_path');
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
