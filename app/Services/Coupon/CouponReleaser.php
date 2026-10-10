<?php

namespace App\Services\Coupon;

use App\Models\Coupon;
use App\Models\CouponCode;
use App\Models\CouponIssuedCode;
use App\Models\EventRegistration;
use App\Services\AdminLogger;
use App\Support\PaymentFigures;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Giving a coupon's uses back when the thing they paid for is deleted.
 *
 * THE MIRROR OF CouponRedeemer, AND IT HAS TO BE
 *
 * A claim writes one ledger row per head, counts the cap off those rows, and in unique
 * mode marks that many issued codes used. Deleting the registration used to leave every
 * one of those in place: the foreign key is nullOnDelete, so the ledger row survived
 * with an empty registration id, the batch's remaining count stayed reduced, and the
 * sponsor's "actually used" figure kept the discount for an entry that no longer
 * existed. Nothing on any screen explained where the use had gone.
 *
 * WHY RELEASING IS ALWAYS CORRECT HERE, RATHER THAN A JUDGEMENT CALL
 *
 * It rests on a guard that already exists. ParticipantController::destroy() refuses to
 * delete a registration when EventRegistration::hasMoneyReceived() answers true. So a
 * registration that CAN be deleted never received a sen, which means nobody ever
 * benefited from the discount, which means the use was never really spent. There is no
 * case where this hands back a use that bought somebody something.
 *
 * That is also exactly why this is called from the delete path and not from a model
 * event: the rule above is only true BESIDE the money guard. See the note on the call
 * site in ParticipantController.
 *
 * WHAT IT TAKES, AND WHY IT TAKES THE SAME LOCK
 *
 * The batch row, locked for update, which is the identical lock CouponRedeemer takes
 * to serialise the cap. A release and a concurrent claim both move the same remaining
 * count — one by deleting rows and one by counting them — so without the lock the
 * claim could count the rows this release is about to remove, or miss the ones it has
 * just put back. They serialise on the batch, so whichever runs second sees the other's
 * committed work.
 *
 * ORDER MATTERS INSIDE THE TRANSACTION
 *
 * The issued codes are unmarked BEFORE the ledger rows go. coupon_issued_codes.
 * coupon_code_id is nullOnDelete, so deleting first would null the pairing and leave
 * `used_at` stamped with nothing left to say which row it belonged to — a code spent
 * for ever on a registration that no longer exists.
 *
 * WHY coupon_codes.event_registration_id STAYS nullOnDelete
 *
 * With rows released on deletion there should be no orphan left for the database to
 * null, so the declaration looks redundant. It is kept, and not tightened to a
 * cascade, because the two candidates are worse:
 *
 *   CASCADE would destroy the ledger row for ANY registration that is ever deleted,
 *   including one that received money — the one case where the discount really was
 *   given and the row is a financial record. The guard that makes releasing safe
 *   lives in a controller; a cascade would apply the same outcome where that guard
 *   does not hold, silently, with no trail. A release is a deliberate act that gets
 *   logged; a cascade is a side effect that does not.
 *
 *   RESTRICT would turn the database into a second veto on deleting an entry and
 *   answer it with a constraint violation instead of the sentence the operator reads.
 *
 * So it stays as a backstop whose job is now narrow and explicit: if something ever
 * deletes a registration without coming through here, the result is a visible orphan
 * rather than a dangling id or a lost record — which is exactly the shape the
 * migration beside this release already knows how to clear.
 */
class CouponReleaser
{
    /**
     * Give back every use this registration spent.
     *
     * Returns one entry per batch touched, which is what the caller logs. An empty
     * array means there was nothing to release, which is the ordinary case.
     *
     * Expects to be called inside the caller's own transaction, and takes its own as
     * a backstop so a direct call is still atomic. The release must commit or roll
     * back with the deletion it belongs to, or a failed delete would hand back uses
     * for an entry that is still standing.
     *
     * @return array<int, array{coupon: string, uses: int, codes: int, discount: float}>
     */
    public function releaseForRegistration(EventRegistration $registration): array
    {
        if ($registration->getKey() === null) {
            return [];
        }

        $couponIds = CouponCode::query()
            ->where('event_registration_id', $registration->getKey())
            ->distinct()
            ->pluck('coupon_id');

        if ($couponIds->isEmpty()) {
            return [];
        }

        return DB::transaction(function () use ($registration, $couponIds) {
            $released = [];

            foreach ($couponIds as $couponId) {
                /*
                 | The batch, under the same lock a claim takes. Everything below is
                 | re-read inside it: a claim committed since this method started
                 | must not have its rows released, and the count it reads next must
                 | include the ones put back here.
                 */
                $locked = Coupon::query()->whereKey($couponId)->lockForUpdate()->first();

                if ($locked === null) {
                    continue;
                }

                $rows = CouponCode::query()
                    ->where('coupon_id', $locked->id)
                    ->where('event_registration_id', $registration->getKey())
                    ->orderBy('id')
                    ->get();

                if ($rows->isEmpty()) {
                    continue;
                }

                $released[] = [
                    'coupon' => $locked->name,
                    'uses' => $rows->count(),
                    'codes' => $this->unspendCodes($rows),
                    'discount' => round((float) $rows->sum('discount_amount'), 2),
                ];

                CouponCode::query()->whereKey($rows->modelKeys())->delete();
            }

            return $released;
        });
    }

    /**
     * Say what came back, in the trail.
     *
     * Uses silently reappearing is as confusing as uses silently disappearing, so a
     * release is recorded in the same words a redemption is: which batch, how many
     * uses, and what the discount was worth.
     *
     * Separate from the work and called after the commit, for CouponRedeemer's
     * reason: a logging failure must not roll back a deletion that has already
     * happened, and the batch lock is released as early as possible.
     *
     * @param  array<int, array{coupon: string, uses: int, codes: int, discount: float}>  $released
     */
    public function log(array $released, string $reference): void
    {
        foreach ($released as $entry) {
            AdminLogger::activity('coupons.release', sprintf(
                'Deleting %s released %d use(s) of coupon %s, worth %s%s.',
                $reference,
                $entry['uses'],
                $entry['coupon'],
                PaymentFigures::money($entry['discount']),
                $entry['codes'] > 0
                    ? sprintf(' and returned %d code(s) to the block', $entry['codes'])
                    : '',
            ));
        }
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * Put the minted codes these rows paid for back into their block, unused.
     *
     * Shared batches mint nothing, so this answers zero for them and the pool is
     * given back by the ledger rows going. In unique mode the holder's block is the
     * stock, and a code left marked used would be gone from it for ever.
     *
     * Matched on coupon_code_id rather than on the allocation, so only the codes this
     * registration actually spent come back — never a neighbouring claim's.
     *
     * @param  Collection<int, CouponCode>  $rows
     */
    private function unspendCodes(Collection $rows): int
    {
        return CouponIssuedCode::query()
            ->whereIn('coupon_code_id', $rows->modelKeys())
            ->update([
                'used_at' => null,
                'coupon_code_id' => null,
                'updated_at' => now(),
            ]);
    }
}
