<?php

namespace App\Http\Controllers\Admin\Coupon;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CouponAllocationRequest;
use App\Http\Requests\Admin\CouponRequest;
use App\Models\Coupon;
use App\Models\User;
use App\Services\AdminLogger;
use App\Services\Coupon\CouponIssuer;
use App\Support\CouponDesignSample;
use App\Support\CouponHolderIdentity;
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

    public function create(Request $request)
    {
        return view('admin.coupon.form', $this->formData($request, new Coupon([
            'kind' => Coupon::KIND_EVENT,
            'mode' => Coupon::MODE_SHARED,
            'quantity' => 0,
            'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
            'design' => 'classic',
        ]), 'create'));
    }

    public function store(CouponRequest $request, CouponIssuer $issuer)
    {
        $coupon = new Coupon($request->couponAttributes());
        $this->applyDesignImage($request, $coupon);

        /*
         | A unique batch is saved with no stock and then issues its first block, so
         | `quantity` is only ever written by the issuer. The alternative — trusting the
         | typed figure and minting to match — is two sources of truth for how many
         | codes exist, and a short mint would silently promise uses nobody holds.
         */
        $wanted = (int) $coupon->quantity;

        if ($coupon->isUnique()) {
            $coupon->quantity = 0;
        }

        $coupon->save();

        if ($coupon->isUnique()) {
            $issuer->issue($coupon, $wanted, $request->holderFields());
            $coupon->refresh();
        }

        AdminLogger::activity('coupons.create', sprintf(
            'Created coupon %s: %s off %s, %s, expires %s.',
            $coupon->name,
            $coupon->discountLabel(),
            $coupon->kindLabel(),
            $this->allowanceLabel($coupon),
            $coupon->expiresLabel(),
        ));

        AdminLogger::audit($coupon, 'created', null, [
            'name' => $coupon->name,
            'kind' => $coupon->kind,
            'mode' => $coupon->mode,
            'quantity' => $coupon->quantity,
            'discount_type' => $coupon->discount_type,
            'discount_value' => (float) $coupon->discount_value,
            'committed_amount' => $coupon->committed_amount === null ? null : (float) $coupon->committed_amount,
            'sponsor' => $coupon->sponsor?->logLabel(),
            'expires_at' => $coupon->expires_at?->toDateString(),
        ]);

        // Recorded in its own right even on a fresh batch: it decides whose money a
        // discount comes out of and who gets a screen showing it.
        $this->logSponsorship($coupon, null, $coupon->sponsor);

        if ($coupon->isUnique()) {
            return redirect()
                ->route('admin.coupons.report.show', $coupon)
                ->with('status', sprintf(
                    'Coupon %s created with %d individual %s, handled by %s. They work until %s.',
                    $coupon->name,
                    $coupon->quantity,
                    (int) $coupon->quantity === 1 ? 'code' : 'codes',
                    $coupon->allocations()->with('holder')->get()->last()?->holderLabel() ?? CouponHolderIdentity::UNASSIGNED,
                    $coupon->expiresLabel(),
                ));
        }

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

    /**
     * Issue another block of codes, with its own handler.
     *
     * A NEW ALLOCATION, never a top-up. The owner's workflow is "a hundred more,
     * handled by somebody else", and growing a single quantity could not express the
     * second holder — which is the whole thing the report has to answer.
     */
    public function issueCodes(CouponAllocationRequest $request, Coupon $coupon, CouponIssuer $issuer)
    {
        $count = (int) $request->validated('quantity');

        $allocation = $issuer->issue($coupon, $count, $request->holderFields());

        return redirect()
            ->route('admin.coupons.report.show', $coupon)
            ->with('status', sprintf(
                '%d more %s generated for %s. %s now has %d %s in total.',
                $count,
                $count === 1 ? 'code' : 'codes',
                $allocation->holderLabel(),
                $coupon->name,
                $coupon->fresh()->quantity,
                (int) $coupon->fresh()->quantity === 1 ? 'code' : 'codes',
            ));
    }

    public function edit(Request $request, Coupon $coupon)
    {
        return view('admin.coupon.form', $this->formData($request, $coupon, 'edit'));
    }

    public function update(CouponRequest $request, Coupon $coupon)
    {
        $before = $this->snapshot($coupon);

        // Read before the fill, because after it the relation would answer with the
        // new account and the trail would name the same sponsorship twice.
        $previousSponsor = $coupon->sponsor;

        $coupon->fill($request->couponAttributes());
        $this->applyDesignImage($request, $coupon);

        $sponsorChanged = $coupon->isDirty('sponsor_user_id');

        $coupon->save();

        AdminLogger::activity('coupons.update', sprintf('Updated coupon %s.', $coupon->name));
        AdminLogger::audit($coupon, 'updated', $before, $this->snapshot($coupon));

        if ($sponsorChanged) {
            $this->logSponsorship($coupon, $previousSponsor, $coupon->fresh()->sponsor);
        }

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
     * What a batch allows, in words, for the trail entry.
     *
     * Three different sentences because they are three different things: codes handed
     * out, a capped shared code, and a shared code with no cap at all.
     */
    private function allowanceLabel(Coupon $coupon): string
    {
        if ($coupon->isUnique()) {
            return $coupon->quantity.' individual codes';
        }

        return $coupon->isUnlimited() ? 'unlimited uses' : $coupon->quantity.' uses';
    }

    /**
     * The fields worth recording either side of an edit.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Coupon $coupon): array
    {
        return [
            'name' => $coupon->name,
            'kind' => $coupon->kind,
            'mode' => $coupon->mode,
            'quantity' => $coupon->quantity,
            'discount_type' => $coupon->discount_type,
            'discount_value' => (float) $coupon->discount_value,
            'committed_amount' => $coupon->committed_amount === null ? null : (float) $coupon->committed_amount,
            'sponsor' => $coupon->sponsor?->logLabel(),
            'expires_at' => $coupon->expires_at?->toDateString(),
        ];
    }

    /**
     * Who funded this batch changing hands, named at both ends.
     *
     * NEVER SILENT. This moves the responsibility for a discount between
     * sponsorship accounts: whose pledge it comes out of, whose screen it appears
     * on, and whose figures it leaves. Both the old sponsorship and the new one are
     * named in the activity line and in the audit entry, because "it used to be
     * theirs" is the half somebody will actually be asking about afterwards.
     *
     * The same action slug the Report screen's per-block tagging writes, so one
     * filter answers "who changed who funded what" whichever level it was done at.
     */
    private function logSponsorship(Coupon $coupon, ?User $before, ?User $after): void
    {
        if ($before === null && $after === null) {
            return;
        }

        AdminLogger::activity('coupons.sponsor', match (true) {
            $after === null => sprintf(
                'Removed the sponsorship %s from coupon %s. It is not sponsored now.',
                $before->logLabel(),
                $coupon->name,
            ),
            $before === null => sprintf(
                'Tagged coupon %s to sponsorship %s. It was not sponsored before.',
                $coupon->name,
                $after->logLabel(),
            ),
            default => sprintf(
                'Moved coupon %s from sponsorship %s to %s.',
                $coupon->name,
                $before->logLabel(),
                $after->logLabel(),
            ),
        });

        AdminLogger::audit(
            $coupon,
            'coupon.sponsor_tagged',
            ['coupon' => $coupon->name, 'sponsor' => $before?->logLabel()],
            ['coupon' => $coupon->name, 'sponsor' => $after?->logLabel()],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request, Coupon $coupon, string $mode): array
    {
        /*
         | THE DESIGN PICKER RENDERS ONE GROUP, NOT ALL OF THEM.
         |
         | The form shows the current choice full size and the group that choice belongs
         | to; every other group's previews are fetched by CouponDesignPickerController
         | when the operator tabs to it or searches for it. So the Design section is the
         | same height and the same weight whether there are six designs or six hundred,
         | which is the thing the owner asked for.
         |
         | Read here rather than in the view because the view would otherwise have to
         | work out the chosen design twice: once for the preview and once for the tab.
         */
        $design = (string) old('design', $coupon->design ?: 'classic');
        $activeGroup = Coupon::designGroupFor($design);
        $groupDesigns = Coupon::designsInGroup($activeGroup);

        // The chosen design is drawn full size inline, and it is not always in the
        // group on show — custom belongs to no group at all.
        $previewed = array_values(array_unique([...array_keys($groupDesigns), $design, Coupon::DESIGN_CUSTOM]));

        /*
         | WHO FUNDED THE BATCH, offered on the form itself rather than only on the
         | Report screen afterwards.
         |
         | Two things were wrong with only having it there. The owner could not find
         | it — the control only appeared once a sponsorship account existed, and
         | only on a batch that had blocks. And a SHARED batch could not be sponsored
         | at all, because a shared batch never gets an allocation and the tag lived
         | on the allocation. Nobody decided to exclude the case; it fell out of where
         | the tag was put. See the 2026_10_16 migration.
         |
         | On coupons.update rather than a new slug, because it is the same action the
         | Report screen already guards with that permission: this only moves where it
         | is done. CouponRequest refuses a submitted value from anybody without it,
         | so a role that cannot see the field cannot post one either.
         */
        $canSetSponsor = $request->user()->hasPermission('coupons.update');

        return [
            'coupon' => $coupon,
            'mode' => $mode,
            'kinds' => Coupon::KINDS,
            'codeModes' => Coupon::MODES,
            'discountTypes' => Coupon::DISCOUNT_TYPES,
            'maxQuantity' => CouponRequest::MAX_QUANTITY,

            'canSetSponsor' => $canSetSponsor,
            'sponsorId' => old('sponsor_user_id', $coupon->sponsor_user_id),

            // Loaded only for somebody who may actually choose one, because for
            // everybody else it is a list of accounts with no control to use it on.
            'sponsors' => $canSetSponsor
                ? User::query()->where('is_sponsor', true)->orderBy('name')->get(['id', 'name'])
                : collect(),

            // Offered as a starting point so the operator can accept it or type over
            // it, which is the two ways the owner asked for in one field.
            'suggestedCode' => $mode === 'create' ? Coupon::generateCode() : $coupon->name,

            'design' => $design,
            'designGroups' => Coupon::DESIGN_GROUPS,
            'activeGroup' => $activeGroup,
            'groupDesigns' => $groupDesigns,

            /*
             | Every design as key, label and group, for the search box to match
             | against. Text only and a few bytes a design: this is the one thing the
             | picker does need to know about designs it has not drawn, because
             | searching for a design in a group nobody has opened has to find it.
             */
            'designIndex' => array_map(
                fn (string $key) => [
                    'key' => $key,
                    'label' => Coupon::designLabelFor($key),
                    'group' => Coupon::designGroup($key),
                    'groupLabel' => Coupon::designGroupLabel(Coupon::designGroup($key)),
                ],
                array_keys(Coupon::groupedDesigns()),
            ),

            'designSamples' => CouponDesignSample::many($coupon, $previewed),
            'designSubject' => CouponDesignSample::subject($coupon),
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
