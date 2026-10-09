<?php

namespace App\Http\Controllers\Admin\Coupon;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CouponRequest;
use App\Models\Coupon;
use App\Services\AdminLogger;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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
             | how many uses a batch has left and a page of fifteen batches would
             | otherwise be fifteen extra queries.
             */
            ->withCount([
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

        $coupon->save();

        AdminLogger::activity('coupons.create', sprintf(
            'Created coupon %s: %s off %s, %s, expires %s.',
            $coupon->name,
            $coupon->discountLabel(),
            $coupon->kindLabel(),
            $coupon->isUnlimited() ? 'unlimited uses' : $coupon->quantity . ' uses',
            $coupon->expiresLabel(),
        ));

        AdminLogger::audit($coupon, 'created', null, [
            'name' => $coupon->name,
            'kind' => $coupon->kind,
            'quantity' => $coupon->quantity,
            'discount_type' => $coupon->discount_type,
            'discount_value' => (float) $coupon->discount_value,
            'expires_at' => $coupon->expires_at?->toDateString(),
        ]);

        return redirect()
            ->route('admin.coupons.index')
            ->with('status', $coupon->isUnlimited()
                ? sprintf('Coupon %s created. It can be used without limit until %s.', $coupon->name, $coupon->expiresLabel())
                : sprintf(
                    'Coupon %s created. It can be used %d %s until %s.',
                    $coupon->name,
                    $coupon->quantity,
                    (int) $coupon->quantity === 1 ? 'time' : 'times',
                    $coupon->expiresLabel(),
                ));
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

        $coupon->fill($request->couponAttributes());
        $this->applyDesignImage($request, $coupon);

        $coupon->save();

        AdminLogger::activity('coupons.update', sprintf('Updated coupon %s.', $coupon->name));
        AdminLogger::audit($coupon, 'updated', $before, [
            'name' => $coupon->name,
            'kind' => $coupon->kind,
            'quantity' => $coupon->quantity,
            'discount_type' => $coupon->discount_type,
            'discount_value' => (float) $coupon->discount_value,
            'expires_at' => $coupon->expires_at?->toDateString(),
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
         | The ledger rows are the record of a discount that was actually given, and
         | they carry the figure the books rest on. Deleting the batch takes them with
         | it through the cascade, which would leave a registration reading
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

            // One sample batch per design, for the picker to draw. See designSample().
            'designSamples' => array_map(
                fn (string $design) => $this->designSample($coupon, $design),
                array_combine(array_keys(Coupon::DESIGNS), array_keys(Coupon::DESIGNS)),
            ),
            'designSubject' => $coupon->isForShop()
                ? 'Team Jersey 2026 · Home kit'
                : 'Hari Sukan Negara 2026 · Bahagian Sibu',
        ];
    }

    /**
     * An unsaved batch for one design tile to draw.
     *
     * Not saved and never will be: the picker needs something with the right shape to
     * render, and a sample is the honest way to show a design before the operator has
     * decided what the coupon says.
     *
     * Editing an existing batch previews with ITS OWN figures, so the operator is
     * choosing between pictures of the coupon he actually has rather than between
     * pictures of a made-up one. Creating has nothing to read yet, so it falls back to
     * representative values.
     */
    private function designSample(Coupon $coupon, string $design): Coupon
    {
        $sample = new Coupon([
            'kind' => $coupon->kind ?: Coupon::KIND_EVENT,
            // Drawn from the legible alphabet, so the sample looks like what Generate
            // actually produces rather than teaching the eye the wrong shape.
            'name' => $coupon->name ?: 'HC7K4M',
            'quantity' => 0,
            'expires_at' => $coupon->expires_at?->toDateString() ?? now()->addMonth()->toDateString(),
            'discount_type' => $coupon->discount_type ?: Coupon::DISCOUNT_PERCENTAGE,
            'discount_value' => (float) $coupon->discount_value > 0 ? $coupon->discount_value : 30,
            'design' => $design,
        ]);

        // So the custom tile shows the artwork actually uploaded rather than a
        // placeholder the operator cannot recognise.
        $sample->design_path = $coupon->design_path;

        return $sample;
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
