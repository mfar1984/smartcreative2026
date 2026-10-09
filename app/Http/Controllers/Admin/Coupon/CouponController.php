<?php

namespace App\Http\Controllers\Admin\Coupon;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CouponRequest;
use App\Models\Coupon;
use App\Models\CouponCode;
use App\Services\AdminLogger;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CouponController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request)
    {
        $search = trim((string) $request->query('q'));
        $kind = trim((string) $request->query('kind'));

        $coupons = Coupon::query()
            /*
             | Counted in SQL rather than by asking each row, because the list shows
             | how many of a batch are left and a page of fifteen batches would
             | otherwise be thirty extra queries.
             */
            ->withCount([
                'codes',
                'codes as redeemed_count' => fn ($query) => $query->whereNotNull('redeemed_at'),
            ])
            ->when($search !== '', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"))
            ->when($kind !== '', fn (Builder $query) => $query->where('kind', $kind))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.coupon.index', [
            'coupons' => $coupons,
            'kinds' => Coupon::KINDS,
            'search' => $search,
            'kind' => $kind,
            'isFiltered' => $search !== '' || $kind !== '',
            'canCreate' => $request->user()->hasPermission('coupons.create'),
            'canUpdate' => $request->user()->hasPermission('coupons.update'),
            'canDelete' => $request->user()->hasPermission('coupons.delete'),
        ]);
    }

    public function create()
    {
        return view('admin.coupon.form', $this->formData(new Coupon([
            'kind' => Coupon::KIND_EVENT,
            'quantity' => 0,
            'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
            'design' => 'classic',
        ]), 'create'));
    }

    public function store(CouponRequest $request)
    {
        $coupon = new Coupon($request->couponAttributes());
        $this->applyDesignImage($request, $coupon);

        $minted = DB::transaction(function () use ($coupon) {
            $coupon->save();

            /*
             | The codes are minted here and nowhere else, inside the same transaction
             | as the batch. A batch that exists with no codes behind it would offer a
             | discount nobody can claim, and the only way to tell would be a visitor
             | being refused.
             */
            return CouponCode::mintFor($coupon);
        });

        AdminLogger::activity('coupons.create', sprintf(
            'Created coupon %s: %s off %s, %s, expires %s.',
            $coupon->name,
            $coupon->discountLabel(),
            $coupon->kindLabel(),
            $coupon->isUnlimited() ? 'unlimited uses' : $minted . ' unique codes',
            $coupon->expiresLabel(),
        ));

        AdminLogger::audit($coupon, 'created', null, [
            'name' => $coupon->name,
            'kind' => $coupon->kind,
            'quantity' => $coupon->quantity,
            'discount_type' => $coupon->discount_type,
            'discount_value' => (float) $coupon->discount_value,
            'expires_at' => $coupon->expires_at?->toDateString(),
            'codes_minted' => $minted,
        ]);

        return redirect()
            ->route('admin.coupons.index')
            ->with('status', $coupon->isUnlimited()
                ? sprintf('Coupon %s created. It can be used without limit until %s.', $coupon->name, $coupon->expiresLabel())
                : sprintf('Coupon %s created with %d unique codes.', $coupon->name, $minted));
    }

    public function edit(Coupon $coupon)
    {
        return view('admin.coupon.form', $this->formData($coupon, 'edit'));
    }

    public function update(CouponRequest $request, Coupon $coupon)
    {
        $before = [
            'name' => $coupon->name,
            'kind' => $coupon->kind,
            'quantity' => $coupon->quantity,
            'discount_type' => $coupon->discount_type,
            'discount_value' => (float) $coupon->discount_value,
            'expires_at' => $coupon->expires_at?->toDateString(),
        ];

        $wasQuantity = (int) $coupon->quantity;

        $coupon->fill($request->couponAttributes());
        $this->applyDesignImage($request, $coupon);

        $minted = DB::transaction(function () use ($coupon, $wasQuantity) {
            $coupon->save();

            /*
             | Mint only the shortfall, and only when nothing has been used yet — the
             | request refuses a quantity change on a batch somebody has redeemed from,
             | for the reasons set out there. Topping up rather than re-minting means
             | codes already printed keep working.
             */
            if ($coupon->isUnlimited() || (int) $coupon->quantity <= $wasQuantity) {
                return 0;
            }

            $shortfall = (int) $coupon->quantity - $coupon->codes()->count();

            return $shortfall > 0 ? CouponCode::mintFor($coupon, $shortfall) : 0;
        });

        AdminLogger::activity('coupons.update', sprintf('Updated coupon %s.', $coupon->name));
        AdminLogger::audit($coupon, 'updated', $before, [
            'name' => $coupon->name,
            'kind' => $coupon->kind,
            'quantity' => $coupon->quantity,
            'discount_type' => $coupon->discount_type,
            'discount_value' => (float) $coupon->discount_value,
            'expires_at' => $coupon->expires_at?->toDateString(),
            'codes_minted' => $minted,
        ]);

        return redirect()
            ->route('admin.coupons.index')
            ->with('status', sprintf('Coupon %s saved.', $coupon->name));
    }

    public function destroy(Coupon $coupon)
    {
        /*
         | A batch somebody has used is not deleted.
         |
         | The redemption rows are the record of a discount that was actually given,
         | and they carry the figure the books rest on. Deleting the batch takes them
         | with it through the cascade, which would leave a registration reading
         | "RM 40.00 discount" with nothing left to say where it came from.
         */
        $used = $coupon->redeemedCount();

        if ($used > 0) {
            return redirect()
                ->route('admin.coupons.index')
                ->withErrors([
                    'coupon' => sprintf(
                        '%s has been used %d %s, so it cannot be deleted. Let it expire instead.',
                        $coupon->name,
                        $used,
                        $used === 1 ? 'time' : 'times',
                    ),
                ]);
        }

        AdminLogger::audit($coupon, 'deleted', [
            'name' => $coupon->name,
            'kind' => $coupon->kind,
            'quantity' => $coupon->quantity,
        ], null);

        $name = $coupon->name;

        if ($coupon->hasCustomDesign()) {
            Storage::disk('public')->delete($coupon->design_path);
        }

        $coupon->delete();

        AdminLogger::activity('coupons.delete', sprintf('Deleted coupon %s.', $name));

        return redirect()
            ->route('admin.coupons.index')
            ->with('status', sprintf('Coupon %s deleted.', $name));
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function formData(Coupon $coupon, string $mode): array
    {
        return [
            'coupon' => $coupon,
            'mode' => $mode,
            'kinds' => Coupon::KINDS,
            'discountTypes' => Coupon::DISCOUNT_TYPES,
            'designs' => Coupon::DESIGNS,
            'maxQuantity' => \App\Http\Requests\Admin\CouponRequest::MAX_QUANTITY,

            // Offered as a starting point so the operator can accept it or type over
            // it, which is the two ways the owner asked for in one field.
            'suggestedCode' => $mode === 'create' ? Coupon::generateCode() : $coupon->name,
        ];
    }

    /**
     * Store, replace or remove the uploaded artwork.
     *
     * Written onto the model rather than returned because the path and the design
     * choice move together: a 'custom' design with no file behind it would render
     * nothing, and a stored file on a preset design would be an orphan.
     *
     * Follows BrandingSettings' pattern for admin uploads — the public disk, a named
     * directory, and the replaced file deleted so the disk does not fill with files
     * nothing points at.
     */
    private function applyDesignImage(CouponRequest $request, Coupon $coupon): void
    {
        $disk = Storage::disk('public');

        if ($request->boolean('remove_design_image')) {
            if ($coupon->hasCustomDesign()) {
                $disk->delete($coupon->design_path);
            }

            $coupon->design_path = null;

            return;
        }

        if ($request->hasFile('design_image')) {
            if ($coupon->hasCustomDesign()) {
                $disk->delete($coupon->design_path);
            }

            // store() names the file from a hash of its contents, so nothing of the
            // operator's own filename reaches the disk.
            $coupon->design_path = $request->file('design_image')->store(Coupon::DESIGN_DIRECTORY, 'public');
        }
    }
}
