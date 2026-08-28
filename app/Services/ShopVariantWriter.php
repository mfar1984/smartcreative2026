<?php

namespace App\Services;

use App\Models\ShopProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes the option list submitted on the product form.
 *
 * Rows carrying an id are updated in place rather than replaced, so their stock
 * counts and anything pointing at them survive an edit. Rows the operator dropped
 * are deleted, which ShopProductRequest has already refused for any option that has
 * been ordered.
 *
 * The order of operations matters, because shop_product_variants has a unique index
 * on (shop_product_id, label):
 *
 *   1. Delete first. Removing the "S" row and adding a new one also called "S" is an
 *      ordinary thing to do in the form, and creating before deleting made that a
 *      duplicate key error.
 *   2. Park kept rows on temporary labels, but only when a label is actually moving
 *      between rows. Swapping "S" and "M" between two saved options would otherwise
 *      collide halfway through, on whichever one is written first.
 *   3. Then write the real values.
 */
class ShopVariantWriter
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  from ShopProductRequest::variantRows()
     */
    public function sync(ShopProduct $product, array $rows): void
    {
        DB::transaction(function () use ($product, $rows) {
            $keptIds = collect($rows)
                ->pluck('id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->all();

            /*
             | Whatever is left was removed from the form. The when() guard matters:
             | with an empty kept list the whereKeyNot is skipped so everything goes,
             | which is right when the operator cleared the list.
             */
            $product->variants()
                ->when($keptIds !== [], fn ($query) => $query->whereKeyNot($keptIds))
                ->delete();

            $product->unsetRelation('variants');

            $this->parkLabelsIfMoving($product, $rows);

            foreach ($rows as $position => $row) {
                $attributes = [
                    'label' => $row['label'],
                    'sku' => $row['sku'] ?? null,

                    /*
                     | Null is meaningful in both of these. A blank price means
                     | "charge the product price" and a blank stock means
                     | "unlimited", so neither may be coerced to zero.
                     */
                    'price' => $row['price'] === null ? null : round((float) $row['price'], 2),
                    'stock' => $row['stock'] === null ? null : (int) $row['stock'],

                    // Display order follows the order of the form, so dragging a row
                    // is not needed for the list to come back as it was left.
                    'sort_order' => $position,
                ];

                $variant = null;

                if (! empty($row['id'])) {
                    /*
                     | Scoped through the relation, so an id belonging to another
                     | product cannot be written even if validation were bypassed.
                     */
                    $variant = $product->variants()->whereKey($row['id'])->first();
                }

                if ($variant !== null) {
                    // stock_taken is never written from the form; the order flow
                    // owns it.
                    $variant->fill($attributes)->save();
                } else {
                    $product->variants()->create($attributes);
                }
            }
        });

        // The relation was loaded before the write on an edit, so it is dropped to
        // stop a stale list being rendered on the page that follows.
        $product->unsetRelation('variants');
    }

    /**
     * Move every surviving option onto a throwaway label, but only when one of the
     * submitted labels currently belongs to a different row.
     *
     * Without this, renaming "S" to "M" while another row is being renamed "M" to
     * "S" fails on whichever is written first. With it, the collision cannot happen
     * because no real label is in place when the real values are written.
     *
     * Skipped entirely when nothing is moving, so the ordinary edit costs no extra
     * queries.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function parkLabelsIfMoving(ShopProduct $product, array $rows): void
    {
        $stored = $product->variants()->get();

        if ($stored->isEmpty()) {
            return;
        }

        $moving = collect($rows)
            ->filter(fn (array $row) => ! empty($row['id']))
            ->contains(function (array $row) use ($stored) {
                return $stored->contains(
                    fn ($variant) => $variant->id !== (int) $row['id']
                        && $variant->label === $row['label']
                );
            });

        if (! $moving) {
            return;
        }

        foreach ($stored as $variant) {
            /*
             | A random suffix rather than a fixed prefix, so the parked value cannot
             | match a label somebody actually typed. Nothing reads these: they exist
             | only between the two writes inside this transaction.
             */
            $variant->forceFill(['label' => '~' . $variant->id . '~' . Str::random(16)])->save();
        }

        $product->unsetRelation('variants');
    }
}
