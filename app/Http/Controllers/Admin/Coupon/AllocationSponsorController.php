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
 * Tying a block of codes to the sponsorship that funded it.
 *
 * STAFF DO THIS, NOT THE SPONSOR. A sponsorship account is monitor-and-view only, so
 * the tag is applied here, on the coupon side, by whoever may edit the coupon.
 *
 * THE BLOCK IS THE UNIT, AND WHY
 *
 * The owner's case is an NGO commissioning a thousand codes handed out through ten
 * representatives, and his question is whose block ran out first. That question is
 * asked of a block, so a block is what gets tagged. A batch-level tag could not
 * express it — one batch is routinely split between sponsors, and a block issued next
 * month would silently inherit a tag nobody chose.
 *
 * "Every block on this batch" is offered as a convenience, because an NGO's ten blocks
 * are usually all one sponsorship. It writes the same per-block tag ten times rather
 * than storing a second, batch-level tag: two places saying who funded a block is how
 * two screens come to disagree about whose money it is.
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
            ? $coupon->allocations()->get()
            : collect([$allocation]);

        foreach ($blocks as $block) {
            $block->forceFill(['sponsor_user_id' => $sponsorId])->save();
        }

        AdminLogger::activity('coupons.sponsor', $sponsor === null
            ? sprintf(
                'Removed the sponsorship from %s on coupon %s.',
                $applyToBatch ? 'every block' : sprintf('block %d', $allocation->id),
                $coupon->name,
            )
            : sprintf(
                'Tagged %s on coupon %s to sponsorship %s.',
                $applyToBatch ? 'every block' : sprintf('block %d', $allocation->id),
                $coupon->name,
                $sponsor->logLabel(),
            ));

        AdminLogger::audit($coupon, 'coupon.sponsor_tagged', null, [
            'coupon' => $coupon->name,
            'blocks' => $blocks->pluck('id')->all(),
            'sponsor' => $sponsor?->logLabel(),
        ]);

        return redirect()
            ->route('admin.coupons.report.show', $coupon)
            ->with('status', $sponsor === null
                ? 'Sponsorship removed.'
                : sprintf('Tagged to %s.', $sponsor->name));
    }
}
