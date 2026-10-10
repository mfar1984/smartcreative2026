<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Confining a query to the events a monitoring account may see.
 *
 * ONE DEFINITION, read by every scopeVisibleTo() in the models and by the handful of
 * controller queries that have no model scope to hang off. The rule itself is two
 * sentences and it is the whole of the third layer:
 *
 *   null        this user is not narrowed at all. Every member of staff and the
 *               super admin, so the query is handed back untouched and no existing
 *               screen returns fewer rows than it did.
 *   an array    a monitoring account, confined to exactly these event ids. EMPTY IS
 *               MEANINGFUL and is the safe default: an account assigned to nothing
 *               gets empty lists, not everything.
 *
 * Narrowing a URL only hides a link, which is why this exists alongside
 * ScopeEventToMonitor rather than instead of it: the middleware refuses an event the
 * request NAMES, and this stops a listing, a tab count, a money total or an export
 * quietly including an event the request never had to name at all.
 */
final class MonitorScope
{
    /**
     * The event ids a user is confined to, or null for no confinement.
     *
     * @return array<int, int>|null
     */
    public static function ids(?User $user): ?array
    {
        return $user?->visibleEventIds();
    }

    /**
     * Confine a query by its own event id column.
     *
     * The column is qualified by any caller whose query is joined, because a bare
     * `event_id` would be ambiguous there.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function byColumn(Builder $query, ?User $user, string $column = 'event_id'): Builder
    {
        $ids = self::ids($user);

        return $ids === null ? $query : $query->whereIn($column, $ids);
    }

    /**
     * Confine a query through a relation that carries the event.
     *
     * For the rows that have no event of their own — a person, a coupon redemption, a
     * coupon batch — and reach one through what they belong to.
     *
     * whereHas rather than a join, so the relation's own constraints apply and the
     * caller's select list is left alone. A row whose relation is missing is excluded,
     * which is the right answer: a redemption with no registration is a shop order,
     * and a shop order belongs to no event anybody was assigned.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function throughRelation(
        Builder $query,
        ?User $user,
        string $relation,
        string $column = 'event_id',
    ): Builder {
        $ids = self::ids($user);

        if ($ids === null) {
            return $query;
        }

        return $query->whereHas($relation, fn (Builder $related) => $related->whereIn($column, $ids));
    }

    /**
     * Confine a batch list to the coupons ticked onto an assigned event.
     *
     * THE COUPON MODULE IS NOT PER-EVENT THE WAY PARTICIPANTS ARE, so it gets its own
     * two helpers rather than reusing the column one. A batch is TICKED ONTO events
     * through coupon_event, and the same batch routinely applies to several.
     *
     * A monitoring account sees a batch if it is ticked onto an event assigned to
     * them, and does not see one that applies only to other events. A SHOP batch is
     * ticked onto no event at all, so it is never visible to one — which is correct:
     * it discounts merchandise, not the event they were asked to watch.
     *
     * What this deliberately does NOT do is apportion a batch's STOCK. A batch shared
     * between an assigned and an unassigned event has one allowance and one remaining
     * count, and there is no honest way to split them per event. The REDEMPTION
     * figures are scoped instead, by the helper below.
     *
     * @param  Builder<\App\Models\Coupon>  $query
     * @return Builder<\App\Models\Coupon>
     */
    public static function couponsOnAssignedEvents(Builder $query, ?User $user): Builder
    {
        return self::throughRelation($query, $user, 'events', 'events.id');
    }

    /**
     * Confine a redemption ledger to uses on an assigned event.
     *
     * A redemption reaches its event through the ENTRY it discounted, so a use on an
     * event nobody was assigned is excluded — and so is a use on a SHOP ORDER, which
     * has no event at all. That second exclusion is deliberate rather than incidental:
     * it is why a monitoring account's Shop figures read zero instead of carrying
     * somebody else's merchandise discounts.
     *
     * This is what scopes the figures a batch cannot scope for itself. A batch ticked
     * onto an assigned event AND an unassigned one is visible, but every redemption
     * count and every discount total about it covers the assigned side only.
     *
     * Takes any builder rather than only an Eloquent one, because the Report screen
     * reads the same ledger through withCount and withSum sub-queries as well as
     * directly.
     *
     * @template TQuery of Builder<\App\Models\CouponCode>|\Illuminate\Database\Query\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function redemptionsOnAssignedEvents($query, ?User $user)
    {
        $ids = self::ids($user);

        if ($ids === null) {
            return $query;
        }

        /*
         | A sub-query against event_registrations rather than whereHas, because this
         | is also handed the plain query builders behind withCount and withSum, which
         | have no relations to reach through. One expression that works on both is
         | better than two that have to be kept saying the same thing.
         */
        return $query->whereIn('coupon_codes.event_registration_id', function ($inner) use ($ids) {
            $inner->select('id')
                ->from('event_registrations')
                ->whereIn('event_id', $ids);
        });
    }
}
