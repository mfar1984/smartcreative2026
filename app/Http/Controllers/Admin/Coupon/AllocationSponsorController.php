<?php

namespace App\Http\Controllers\Admin\Coupon;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CouponAllocation;
use App\Models\User;
use App\Services\AdminLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tying ONE BLOCK of codes to the sponsorship that funded it, overriding its batch.
 *
 * STAFF DO THIS, NOT THE SPONSOR. A sponsorship account is monitor-and-view only, so
 * the tag is applied here, on the coupon side, by whoever may edit the coupon.
 *
 * WHERE THIS SITS NOW THERE ARE TWO LEVELS
 *
 * The batch's own sponsorship is set on the coupon form, under THE CODE, which is also
 * the only level a shared code can be sponsored at. This screen is the EXCEPTION to
 * it, for the case the batch field cannot express: one batch split between sponsors,
 * which is routine when an NGO commissions a thousand codes through ten
 * representatives and somebody else pays for two of the blocks.
 *
 * THE RULE, which is CouponAllocation::effectiveSponsorId() and nothing else:
 *
 *   the batch-level sponsorship applies to every block in the batch, UNLESS that
 *   block names its own, which overrides it for that block only.
 *
 * So clearing a block here does not make it unsponsored; it hands it back to its
 * batch. The select says which of the two a blank means, because they look identical
 * until somebody tags the batch.
 *
 * "Every block on this batch" stays as a convenience: it writes the same explicit
 * per-block tag ten times, which is what somebody wants when the blocks are going
 * one way and the batch's own figure is going another.
 */
class AllocationSponsorController extends Controller
{
    public function update(Request $request, Coupon $coupon, CouponAllocation $allocation)
    {
        // The block has to belong to the batch in the path. Both are route
        // parameters, so without this an id from another batch would be accepted.
        abort_unless((int) $allocation->coupon_id === (int) $coupon->id, 404);

        $validated = $request->validate([
            /*
             | A sponsorship account and nothing else. Without the is_sponsor
             | condition any user id would be accepted here, which would quietly
             | hand an administrator a sponsor's screen.
             */
            'sponsor_user_id' => [
                'nullable',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('is_sponsor', true)),
            ],
            'apply_to' => ['nullable', 'in:block,batch'],
        ]);

        $sponsorId = $validated['sponsor_user_id'] ?? null;
        $sponsor = $sponsorId === null ? null : User::find($sponsorId);

        $applyToBatch = ($validated['apply_to'] ?? 'block') === 'batch';

        $blocks = $applyToBatch
            ? $coupon->allocations()->with('sponsor')->get()
            : collect([$allocation->load('sponsor')]);

        // Block id => who it answered to, taken before the writes so the trail can
        // name both ends of the move.
        $before = $blocks->mapWithKeys(fn ($block) => [
            $block->id => $block->sponsor?->logLabel() ?? 'not sponsored',
        ]);

        foreach ($blocks as $block) {
            $block->forceFill(['sponsor_user_id' => $sponsorId])->save();
        }

        /*
         | Both ends named, because this moves responsibility for a discount between
         | accounts and "it used to be theirs" is the half somebody will be asking
         | about afterwards. Read before the writes above would have been neater, so
         | it is read from the collection taken before them.
         */
        AdminLogger::activity('coupons.sponsor', $sponsor === null
            ? sprintf(
                'Cleared the sponsorship on %s of coupon %s, so %s follow the batch%s.',
                $applyToBatch ? 'every block' : sprintf('block %d', $allocation->id),
                $coupon->name,
                $applyToBatch ? 'they' : 'it',
                $coupon->hasSponsor() ? ' ('.$coupon->sponsor?->logLabel().')' : ', which is not sponsored',
            )
            : sprintf(
                'Tagged %s on coupon %s to sponsorship %s, overriding the batch.',
                $applyToBatch ? 'every block' : sprintf('block %d', $allocation->id),
                $coupon->name,
                $sponsor->logLabel(),
            ));

        AdminLogger::audit(
            $coupon,
            'coupon.sponsor_tagged',
            ['coupon' => $coupon->name, 'blocks' => $before->keys()->all(), 'sponsor' => $before->values()->unique()->all()],
            ['coupon' => $coupon->name, 'blocks' => $blocks->pluck('id')->all(), 'sponsor' => $sponsor?->logLabel()],
        );

        return redirect()
            ->route('admin.coupons.report.show', $coupon)
            ->with('status', $sponsor === null
                ? 'Sponsorship cleared. It follows the batch now.'
                : sprintf('Tagged to %s.', $sponsor->name));
    }
}
