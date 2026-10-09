<?php

namespace App\Http\Controllers\Admin\Coupon;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Services\AdminLogger;
use App\Support\CouponArtwork;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The coupon's design, as a file the owner can keep.
 *
 * Asked for so the artwork can be used away from this site — a poster, a print shop, a
 * post. A preset design is drawn as real SVG by CouponArtwork, with the batch's own
 * figures in it, and comes back self-contained: nothing in that file reaches back to
 * this server, so it opens on a machine that has never seen it.
 *
 * Custom is the exception and deliberately so. It is a raster image the operator
 * uploaded, so the uploaded file is served AS ITSELF, with its own content type. There
 * is nothing to gain from wrapping a photograph in an SVG shell and calling it vector,
 * and a file that claims to be one thing and is another is worse than no download.
 *
 * On the view permission, which is the right one: this hands over nothing the coupon
 * list is not already showing, in a different shape.
 */
class CouponDesignController extends Controller
{
    public function __invoke(Coupon $coupon): Response
    {
        if ($coupon->design === Coupon::DESIGN_CUSTOM) {
            return $this->uploaded($coupon);
        }

        $artwork = CouponArtwork::for($coupon);

        /*
         | Worth recording. The file carries a live discount code in letters big enough
         | to read across a room, so who took a copy and when is the question somebody
         | asks after a code turns up somewhere it was not meant to.
         */
        AdminLogger::activity('coupons.design-download', sprintf(
            'Downloaded the %s artwork for coupon %s: %s off %s, expires %s.',
            $artwork->design(),
            $coupon->name,
            $coupon->discountLabel(),
            $coupon->kindLabel(),
            $coupon->expiresLabel(),
        ));

        return response($artwork->svg(), 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $artwork->filename(),
            ),

            // An SVG is a document, not a picture, so nothing downstream may decide for
            // itself what this is.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The operator's own artwork, handed back unchanged.
     *
     * A batch set to custom with nothing uploaded is a 404 rather than a fallback
     * drawing: the list offers the download for every coupon, and quietly handing over
     * a different design than the one on the screen is how the wrong poster gets
     * printed. The screen already shows which design a batch is on.
     */
    private function uploaded(Coupon $coupon): Response
    {
        $disk = Storage::disk('public');

        abort_unless($coupon->hasCustomDesign() && $disk->exists($coupon->design_path), 404);

        AdminLogger::activity('coupons.design-download', sprintf(
            'Downloaded the uploaded artwork for coupon %s.',
            $coupon->name,
        ));

        // Named after the coupon rather than carrying the operator's own filename,
        // which store() hashed away on upload anyway.
        $name = sprintf(
            'coupon-%s-custom.%s',
            $coupon->name,
            pathinfo($coupon->design_path, PATHINFO_EXTENSION) ?: 'png',
        );

        return $disk->download($coupon->design_path, $name, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
