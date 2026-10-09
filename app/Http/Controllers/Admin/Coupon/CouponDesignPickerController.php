<?php

namespace App\Http\Controllers\Admin\Coupon;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Support\CouponDesignSample;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The part of the design picker the form did not render.
 *
 * THIS IS WHAT KEEPS THE FORM LIGHT. The coupon form renders the current choice and
 * one group, and nothing else. Tab to another group and its previews are fetched from
 * here and appended; search across groups and the groups the search needs are fetched
 * the same way. At two hundred designs the form still ships five or six previews.
 *
 * The alternative — rendering every design into the page and hiding the rest behind a
 * tab or a scrollbar — costs the same bytes and the same layout work whether anybody
 * looks at them or not, which is the thing this avoids rather than a thing it defers.
 *
 * HTML rather than JSON because what is wanted IS the markup: the previews are Blade
 * components that already know how to draw a design, and a JSON round trip would mean
 * a second renderer in JavaScript that could disagree with them.
 *
 * On the permission, which is the create-or-update pair: this is a part of the coupon
 * form, so whoever may open that form may load the rest of it. It hands over nothing
 * but drawings of sample figures.
 */
class CouponDesignPickerController extends Controller
{
    public function __invoke(Request $request, ?Coupon $coupon = null): Response
    {
        $coupon ??= new Coupon([
            'kind' => Coupon::KIND_EVENT,
            'quantity' => 0,
            'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
        ]);

        $design = (string) $request->query('design', '');

        return $design !== ''
            ? $this->preview($coupon, $design)
            : $this->group($coupon, (string) $request->query('group', ''));
    }

    /**
     * The cards of one group: a real radio and a preview each.
     *
     * An unknown group is a 404 rather than an empty grid. The only caller is the
     * picker's own tab list, built from Coupon::DESIGN_GROUPS, so a miss means
     * something is wrong and silently returning nothing would hide it.
     */
    private function group(Coupon $coupon, string $group): Response
    {
        abort_unless(array_key_exists($group, Coupon::DESIGN_GROUPS), 404);

        $designs = Coupon::designsInGroup($group);

        return response()->view('admin.coupon.partials.design-cards', [
            'designs' => $designs,
            'group' => $group,
            'groupLabel' => Coupon::designGroupLabel($group),

            /*
             | Nothing in a fetched group is ever the current choice. The form opens on
             | the group the choice belongs to, so that group is the one already in the
             | page and the picker keeps every group it has loaded — a group arriving
             | here is one the operator has not chosen from yet.
             */
            'chosen' => '',

            'samples' => CouponDesignSample::many($coupon, array_keys($designs)),
            'subject' => CouponDesignSample::subject($coupon),
        ]);
    }

    /**
     * One design drawn full size, for the inline "current design" block.
     *
     * Fetched rather than cloned from the picker card, because the card preview is the
     * compact rendering and the inline one is full size — the same component, drawn at
     * a different scale, so it has to come from the component rather than from a copy
     * of its output.
     */
    private function preview(Coupon $coupon, string $design): Response
    {
        abort_unless(array_key_exists($design, Coupon::DESIGNS), 404);

        return response()->view('admin.coupon.partials.design-preview', [
            'sample' => CouponDesignSample::for($coupon, $design),
            'subject' => CouponDesignSample::subject($coupon),
        ]);
    }
}
