{{--
    One coupon as a downloadable SVG.

    SELF-CONTAINED, which is the whole point of the file: no stylesheet, no web font
    link, no remote image. Only font FAMILY names, which name whatever the machine
    opening it already has and fall back to the generic at the end of the stack.

    The canvas is the 640x200 card from the approved artwork with a 40px margin round
    it, on the same pale background. The margin is not decoration — 'classic' punches
    its notches by drawing circles in the background colour, and 'stamp' rotates a box
    that paints outside the space it occupies.

    Each design is a partial holding nothing but geometry. What a slot can hold is
    CouponArtwork's answer, so no template does arithmetic on a string.

    @param \App\Support\CouponArtwork $art
--}}
<svg xmlns="http://www.w3.org/2000/svg"
     width="{{ $art::WIDTH }}" height="{{ $art::HEIGHT }}"
     viewBox="0 0 {{ $art::WIDTH }} {{ $art::HEIGHT }}"
     font-family="Segoe UI, Inter, Helvetica, Arial, sans-serif"
     role="img" aria-labelledby="coupon-summary">

    <title id="coupon-summary">{{ $art->summary() }}</title>

    <rect width="{{ $art::WIDTH }}" height="{{ $art::HEIGHT }}" fill="#f1f5f9"/>

    @include('coupon.svg.' . $art->design(), ['art' => $art])
</svg>
