<?php

namespace App\Support;

use App\Models\Coupon;
use App\Models\CouponAllocation;
use Illuminate\Support\Carbon;

/**
 * One row of a sponsor's holding, whichever shape the thing behind it is.
 *
 * A sponsor's list has to show two different things side by side and read as one
 * list:
 *
 *   A BLOCK of a unique batch. Codes were minted, handed to a named representative,
 *   and some are spent. Everything the old list showed.
 *
 *   A WHOLE SHARED BATCH. The name on the poster is the code, nothing was minted,
 *   and there is no representative because there is no block. Its uses and its
 *   discount count exactly as a block's do; what it has no answer for is "whose".
 *
 * WHY A PRESENTER AND NOT A MADE-UP ALLOCATION
 *
 * Handing the view an unsaved CouponAllocation would have been fewer lines, and it
 * would have read "Unassigned" in the handler column — indistinguishable from a real
 * block nobody was named on, which is the blank row that looks like a bug. A shared
 * batch is not an unassigned block; it is a batch with no blocks, and that is a
 * different sentence. So `holder` is NULL here, and only null for that reason, and
 * both the screen and the CSV say so in words.
 */
class SponsorBlockRow
{
    /**
     * @param  int|null  $blockId  null for a shared batch: there is no block to filter to
     * @param  string|null  $holder  null means there is no block, not an unnamed one
     * @param  int|null  $total  null means unlimited, which has no denominator
     * @param  int|null  $left  null for the same reason
     */
    public function __construct(
        public readonly ?int $blockId,
        public readonly int $couponId,
        public readonly string $couponName,
        public readonly string $discountLabel,
        public readonly ?string $holder,
        public readonly ?Carbon $issuedAt,
        public readonly ?int $total,
        public readonly int $used,
        public readonly ?int $left,
        public readonly bool $isSharedBatch,
    ) {}

    /**
     * A block of minted codes, with its counts already totalled in SQL.
     *
     * Reads codes_total and codes_used off the row rather than asking the model,
     * which would be two more queries per representative.
     */
    public static function fromBlock(CouponAllocation $block): self
    {
        $total = (int) $block->codes_total;
        $used = (int) $block->codes_used;

        return new self(
            blockId: (int) $block->id,
            couponId: (int) $block->coupon_id,
            couponName: (string) ($block->coupon?->name ?? ''),
            discountLabel: (string) $block->coupon?->discountLabel(),
            holder: $block->holderLabel(),
            issuedAt: $block->issued_at,
            total: $total,
            used: $used,
            left: max(0, $total - $used),
            isSharedBatch: false,
        );
    }

    /**
     * A shared batch, which is its own single row.
     *
     * The use cap stands in for the code count, because that is the same promise
     * expressed as a number of uses — the reading CouponSponsorship::codesIssued()
     * already takes. An unlimited one has no denominator at all, so `total` and
     * `left` are null rather than zero.
     *
     * "Issued" is the batch's own creation, which is the moment the code came into
     * existence and the nearest honest answer to when a block went out.
     */
    public static function fromSharedBatch(Coupon $coupon, int $used): self
    {
        $total = CouponSponsorship::codesIssued($coupon);

        return new self(
            blockId: null,
            couponId: (int) $coupon->id,
            couponName: (string) $coupon->name,
            discountLabel: $coupon->discountLabel(),
            holder: null,
            issuedAt: $coupon->created_at,
            total: $total,
            used: $used,
            left: $total === null ? null : max(0, $total - $used),
            isSharedBatch: true,
        );
    }

    /**
     * How the row reads where a handler would be named.
     *
     * Said in words rather than left as a dash, because a dash in that column would
     * be read as "we do not know who". There is nobody to know: a shared code is
     * typed off a poster and was never handed to anybody.
     */
    public function holderLabel(): string
    {
        return $this->holder ?? 'One shared code — no block';
    }

    public function hasHolder(): bool
    {
        return $this->holder !== null && $this->holder !== CouponHolderIdentity::UNASSIGNED;
    }

    /** Where the row stands, in one word, for a badge. */
    public function stateLabel(): string
    {
        if ($this->left === 0) {
            return 'Finished';
        }

        return $this->used === 0 ? 'Untouched' : 'In use';
    }

    public function stateTone(): string
    {
        if ($this->left === 0) {
            return 'amber';
        }

        return $this->used === 0 ? 'gray' : 'green';
    }

    /** The codes column, where unlimited has no number to print. */
    public function totalLabel(): string
    {
        return $this->total === null ? 'Unlimited' : number_format($this->total);
    }

    public function leftLabel(): string
    {
        return $this->left === null ? '—' : number_format($this->left);
    }

    public function issuedLabel(string $fallback = '—'): string
    {
        return LocalTime::format($this->issuedAt, fallback: $fallback);
    }
}
