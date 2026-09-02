<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventAddon;
use App\Models\EventAddonVariant;
use Illuminate\Support\Facades\DB;

/**
 * Writes the add-on catalogue submitted on the event form.
 *
 * Rows that carry an id are updated in place rather than replaced, so their
 * stock counts and the order lines pointing at them survive an edit. Rows the
 * operator dropped are deleted, which EventRequest has already refused for
 * anything that has been bought.
 */
class EventAddonWriter
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  from EventRequest::addonRows()
     */
    public function sync(Event $event, array $rows): void
    {
        DB::transaction(function () use ($event, $rows) {
            $keptAddonIds = [];

            foreach ($rows as $position => $row) {
                $addon = $this->writeAddon($event, $row, $position);
                $keptAddonIds[] = $addon->id;

                $this->syncVariants($addon, $row['variants'] ?? []);
            }

            // Whatever is left was removed from the form.
            $event->addons()
                ->when($keptAddonIds !== [], fn ($query) => $query->whereKeyNot($keptAddonIds))
                ->delete();
        });

        // The relation was loaded before the write on an edit, so it is dropped
        // to stop a stale list being rendered on the page that follows.
        $event->unsetRelation('addons');
    }

    private function writeAddon(Event $event, array $row, int $position): EventAddon
    {
        $attributes = [
            'name' => $row['name'],
            'description' => $row['description'] ?? null,
            'price' => round((float) $row['price'], 2),
            'max_quantity' => $row['max_quantity'] ?? null,
            'is_required' => (bool) ($row['is_required'] ?? false),

            // Already reconciled against is_required by the form request, so this
            // writes what was decided rather than deciding again.
            'is_checked_by_default' => (bool) ($row['is_checked_by_default'] ?? false),
            'uncheck_reminder' => $row['uncheck_reminder'] ?? null,
            'is_active' => (bool) ($row['is_active'] ?? false),

            // Display order follows the order of the form, so dragging a row is
            // not needed for the list to come back the way it was left.
            'sort_order' => $position,
        ];

        if (! empty($row['id'])) {
            // Scoped through the relation so an id belonging to another event
            // cannot be written even if validation were bypassed.
            $addon = $event->addons()->whereKey($row['id'])->first();

            if ($addon !== null) {
                $addon->fill($attributes)->save();

                return $addon;
            }
        }

        return $event->addons()->create($attributes);
    }

    /**
     * @param  array<int, array<string, mixed>>  $variants
     */
    private function syncVariants(EventAddon $addon, array $variants): void
    {
        $keptIds = [];

        foreach ($variants as $position => $variant) {
            $attributes = [
                'label' => $variant['label'],

                // Null is meaningful here: it means "charge the add-on price",
                // so a blank box must not be coerced to zero.
                'price' => $variant['price'] === null ? null : round((float) $variant['price'], 2),
                'stock' => $variant['stock'] === null ? null : (int) $variant['stock'],
                'sort_order' => $position,
            ];

            $model = null;

            if (! empty($variant['id'])) {
                $model = $addon->variants()->whereKey($variant['id'])->first();
            }

            if ($model !== null) {
                // stock_taken is never written from the form; it is owned by the
                // registration flow.
                $model->fill($attributes)->save();
            } else {
                $model = $addon->variants()->create($attributes);
            }

            $keptIds[] = $model->id;
        }

        $addon->variants()
            ->when($keptIds !== [], fn ($query) => $query->whereKeyNot($keptIds))
            ->delete();

        $addon->unsetRelation('variants');
    }
}
