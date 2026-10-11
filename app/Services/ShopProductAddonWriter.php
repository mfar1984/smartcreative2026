<?php

namespace App\Services;

use App\Models\ShopProduct;
use App\Models\ShopProductAddon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes the add-on catalogue submitted on the product form.
 *
 * Rows carrying an id are updated in place rather than replaced, so their stock
 * counts and the order lines pointing at them survive an edit. Rows the operator
 * dropped are deleted, which ShopProductRequest has already refused for anything
 * that has been ordered.
 *
 * stock_taken is never written from the form. The order flow owns it, through the
 * single increment on the move into paid, and a figure typed on this screen would
 * overwrite what has actually been sold.
 */
class ShopProductAddonWriter
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  from ShopProductRequest::addonRows()
     */
    public function sync(ShopProduct $product, array $rows): void
    {
        DB::transaction(function () use ($product, $rows) {
            $keptAddonIds = collect($rows)
                ->pluck('id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->all();

            /*
             | Deleted first, before anything is written. The when() guard matters:
             | with an empty kept list the whereKeyNot is skipped so everything goes,
             | which is right when the operator cleared the list.
             */
            $product->addons()
                ->when($keptAddonIds !== [], fn ($query) => $query->whereKeyNot($keptAddonIds))
                ->delete();

            $product->unsetRelation('addons');

            foreach ($rows as $position => $row) {
                $addon = $this->writeAddon($product, $row, $position);

                $this->syncOptions($addon, $row['variants'] ?? []);
            }
        });

        // The relation was loaded before the write on an edit, so it is dropped to
        // stop a stale list being rendered on the page that follows.
        $product->unsetRelation('addons');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function writeAddon(ShopProduct $product, array $row, int $position): ShopProductAddon
    {
        $attributes = [
            'name' => $row['name'],
            'description' => $row['description'] ?? null,
            'price' => round((float) $row['price'], 2),
            'max_quantity' => $row['max_quantity'] ?? null,

            // Null means unlimited, so a blank box must not be coerced to zero,
            // which would read as sold out.
            'stock' => ($row['stock'] ?? null) === null ? null : (int) $row['stock'],

            'is_required' => (bool) ($row['is_required'] ?? false),
            'is_active' => (bool) ($row['is_active'] ?? false),

            // Already reconciled to a known value by the form request; the fallback
            // is here only so a caller that builds rows by hand cannot write a blank.
            'selection_type' => $row['selection_type'] ?? ShopProductAddon::SELECTION_RADIO,

            // Display order follows the order of the form, so the list comes back
            // the way it was left without needing a drag handle.
            'sort_order' => $position,
        ];

        if (! empty($row['id'])) {
            // Scoped through the relation so an id belonging to another product
            // cannot be written even if validation were bypassed.
            $addon = $product->addons()->whereKey($row['id'])->first();

            if ($addon !== null) {
                $addon->fill($attributes)->save();

                return $addon;
            }
        }

        return $product->addons()->create($attributes);
    }

    /**
     * Sync one add-on's options.
     *
     * The order of operations follows ShopVariantWriter's, because
     * shop_product_addon_variants carries the same kind of unique index on
     * (add-on, label):
     *
     *   1. Delete the removed rows first. Dropping "Front" and adding a new row also
     *      called "Front" is an ordinary edit, and creating before deleting made it
     *      a duplicate key error.
     *   2. Park kept rows on throwaway labels, but only when a submitted label
     *      currently belongs to a different row of this add-on. Swapping "Front" and
     *      "Back" between two saved options would otherwise collide on whichever is
     *      written first.
     *   3. Then write the real values.
     *
     * @param  array<int, array<string, mixed>>  $options
     */
    private function syncOptions(ShopProductAddon $addon, array $options): void
    {
        $keptIds = collect($options)
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $addon->variants()
            ->when($keptIds !== [], fn ($query) => $query->whereKeyNot($keptIds))
            ->delete();

        $addon->unsetRelation('variants');

        $this->parkLabelsIfMoving($addon, $options);

        foreach ($options as $position => $option) {
            $attributes = [
                'label' => $option['label'],

                // Null means "charge the add-on's price", so a blank box stays null.
                'price' => ($option['price'] ?? null) === null ? null : round((float) $option['price'], 2),
                'stock' => ($option['stock'] ?? null) === null ? null : (int) $option['stock'],
                'sort_order' => $position,
            ];

            $model = null;

            if (! empty($option['id'])) {
                $model = $addon->variants()->whereKey($option['id'])->first();
            }

            if ($model !== null) {
                $model->fill($attributes)->save();
            } else {
                $addon->variants()->create($attributes);
            }
        }

        $addon->unsetRelation('variants');
    }

    /**
     * Move every surviving option of this add-on onto a throwaway label, but only
     * when one of the submitted labels currently belongs to a different row.
     *
     * Skipped entirely when nothing is moving, so the ordinary edit costs no extra
     * queries.
     *
     * @param  array<int, array<string, mixed>>  $options
     */
    private function parkLabelsIfMoving(ShopProductAddon $addon, array $options): void
    {
        $stored = $addon->variants()->get();

        if ($stored->isEmpty()) {
            return;
        }

        $moving = collect($options)
            ->filter(fn (array $option) => ! empty($option['id']))
            ->contains(fn (array $option) => $stored->contains(
                fn ($model) => $model->id !== (int) $option['id']
                    && $model->label === $option['label']
            ));

        if (! $moving) {
            return;
        }

        foreach ($stored as $model) {
            /*
             | A random suffix rather than a fixed prefix, so a parked value cannot
             | match a label somebody actually typed. Nothing reads these: they exist
             | only between the two writes inside this transaction.
             */
            $model->forceFill(['label' => '~' . $model->id . '~' . Str::random(16)])->save();
        }

        $addon->unsetRelation('variants');
    }
}
