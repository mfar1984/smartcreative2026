<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use App\Models\Event;
use App\Services\Coupon\CouponAvailability;
use App\Services\Coupon\CouponOutcome;
use App\Support\Cart;
use App\Support\CouponDiscount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Checking a voucher code before anybody commits to it.
 *
 * READ ONLY. Nothing here claims a code, stamps one, or changes a total. It answers
 * "is this worth anything here, and what would it take off", so the public form can
 * show the figure and the coupon's own design while the visitor is still filling the
 * form in. The claim happens once, at submit, inside the flow that writes the record,
 * through CouponRedeemer.
 *
 * That split is deliberate and is the only safe shape. A code checked here can be
 * gone by the time Submit is pressed — somebody else took the last one — so this
 * answer is advisory by construction and the submit path re-reads everything under a
 * lock. A visitor who loses that race is told so and charged the normal price.
 *
 * WHAT IS RETURNED, AND WHY IT IS THE TERMS RATHER THAN A TOTAL
 *
 * The response carries the batch's terms — percentage or ringgit, the value, and
 * whether a fixed amount is owed per head — not a finished total. On the registration
 * form the charge is still moving as add-ons are ticked, so a total worked out here
 * would be stale before it was drawn. The browser applies the terms to the running
 * total it already maintains for display, and the server works the real figure out
 * again with CouponDiscount when the form is posted. The money is decided in one
 * place; this only decides what to show.
 */
class VoucherController extends Controller
{
    public function __construct(private readonly CouponAvailability $availability)
    {
    }

    public function check(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'scope' => ['required', Rule::in([Coupon::KIND_EVENT, Coupon::KIND_SHOP])],

            // Only read for the event scope, and resolved to a row below rather than
            // trusted: the slug decides which batches are on offer.
            'event' => ['nullable', 'string', 'max:190'],
        ]);

        $kind = $validated['scope'];

        [$offered, $subject, $event] = $kind === Coupon::KIND_EVENT
            ? $this->eventContext($validated['event'] ?? null)
            : $this->shopContext();

        /*
         | No offer, no answer. A code cannot be checked against an event or a basket
         | that is not offering any coupon, and saying "not recognised" here is the
         | same answer a stranger poking at this endpoint should get.
         */
        if ($offered === null) {
            return response()->json([
                'ok' => false,
                'message' => CouponOutcome::failed(CouponOutcome::NOT_FOUND)->message(),
            ]);
        }

        $lookup = $this->availability->lookup($validated['code'], $offered, $kind);

        if (! $lookup->succeeded()) {
            return response()->json([
                'ok' => false,
                'message' => $lookup->message(),
            ]);
        }

        $coupon = $lookup->coupon;

        /*
         | Whether a FIXED amount is owed once per head.
         |
         | The event's own per-participant rule, read through CouponDiscount so the
         | preview and the charge agree about it. A percentage is never multiplied —
         | see the note in CouponDiscount for why that would turn 34% into free.
         */
        $perHead = $event !== null
            && ! $coupon->isPercentage()
            && CouponDiscount::timesFor($event, 2) > 1;

        return response()->json([
            'ok' => true,
            'code' => $lookup->typedCode(),
            'discount_type' => $coupon->discount_type,
            'discount_value' => (float) $coupon->discount_value,
            'per_head' => $perHead,
            'terms' => $coupon->discountLabel() . ' off',
            'message' => sprintf('%s off applied.', $coupon->discountLabel()),

            // The coupon in its own design, drawn server side so the browser never
            // has to know anything about how a design is put together.
            'ticket' => view('components.coupon.ticket', [
                'coupon' => $coupon,
                'subject' => $subject,
                'code' => $lookup->typedCode(),
                'compact' => false,
            ])->render(),
        ]);
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * The batches on offer for one event, or null when there is no such offer.
     *
     * @return array{0: \Illuminate\Support\Collection<int, Coupon>|null, 1: string|null, 2: Event|null}
     */
    private function eventContext(?string $slug): array
    {
        if (blank($slug)) {
            return [null, null, null];
        }

        $event = Event::query()->publiclyListed()->where('slug', $slug)->first();

        if ($event === null) {
            return [null, null, null];
        }

        $offered = $this->availability->forEvent($event);

        return $offered->isEmpty()
            ? [null, null, null]
            : [$offered, $event->title, $event];
    }

    /**
     * @return array{0: \Illuminate\Support\Collection<int, Coupon>|null, 1: string|null, 2: Event|null}
     */
    private function shopContext(): array
    {
        $lines = Cart::lines();

        if ($lines->isEmpty()) {
            return [null, null, null];
        }

        $offered = $this->availability->forCart($lines);

        return $offered->isEmpty()
            ? [null, null, null]
            : [$offered, 'Your order', null];
    }
}
