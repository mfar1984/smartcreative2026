<?php

namespace App\Support;

use App\Models\Coupon;

/**
 * The batch a design preview is drawn with.
 *
 * Shared by the two places that draw a preview: the coupon form, which renders the
 * current choice and the group the picker opens on, and CouponDesignPickerController,
 * which renders the rest of a group when the operator tabs to it. Both have to produce
 * the same picture for the same design, so the figures are worked out here once.
 *
 * Nothing built here is ever saved. A preview needs something with the right shape to
 * render, and a sample is the honest way to show a design before the operator has
 * decided what the coupon says.
 */
final class CouponDesignSample
{
    /** The sample code, drawn from the legible alphabet. See Coupon::CODE_EXCLUDED. */
    public const SAMPLE_CODE = 'HC7K4M';

    public const SAMPLE_DISCOUNT = 30;

    /**
     * An unsaved batch for one design to draw.
     *
     * Editing an existing batch previews with ITS OWN figures, so the operator is
     * choosing between pictures of the coupon he actually has rather than between
     * pictures of a made-up one. Creating has nothing to read yet, so it falls back to
     * representative values.
     */
    public static function for(Coupon $coupon, string $design): Coupon
    {
        $sample = new Coupon([
            'kind' => $coupon->kind ?: Coupon::KIND_EVENT,
            'name' => $coupon->name ?: self::SAMPLE_CODE,
            'quantity' => 0,
            'expires_at' => $coupon->expires_at?->toDateString() ?? now()->addMonth()->toDateString(),
            'discount_type' => $coupon->discount_type ?: Coupon::DISCOUNT_PERCENTAGE,
            'discount_value' => (float) $coupon->discount_value > 0 ? $coupon->discount_value : self::SAMPLE_DISCOUNT,
            'design' => $design,
        ]);

        // So the custom option shows the artwork actually uploaded rather than a
        // placeholder the operator cannot recognise.
        $sample->design_path = $coupon->design_path;

        return $sample;
    }

    /**
     * One sample per design, keyed by design, for a grid to draw.
     *
     * @param  array<int, string>  $designs
     * @return array<string, Coupon>
     */
    public static function many(Coupon $coupon, array $designs): array
    {
        $samples = [];

        foreach ($designs as $design) {
            $samples[$design] = self::for($coupon, $design);
        }

        return $samples;
    }

    /**
     * What the preview says the coupon is for.
     *
     * A batch is created before it is ticked on anything, so there is no real subject
     * to read: this is a representative one of the right kind, which is what makes the
     * preview recognisable rather than abstract.
     */
    public static function subject(Coupon $coupon): string
    {
        return $coupon->isForShop()
            ? 'Team Jersey 2026 · Home kit'
            : 'Hari Sukan Negara 2026 · Bahagian Sibu';
    }
}
