{{--
    Minimal, as SVG: white, a thin blue rule across the top, light type, space.

    The code is right-aligned with wide tracking, as in the artwork. Both right-hand
    slots are anchored to x=640 so nothing on that side can grow past the card.

    @param \App\Support\CouponArtwork $art
--}}
@php
    $subject = $art->lines('subject');
@endphp

<rect x="40" y="40" width="640" height="200" rx="10" fill="#ffffff" stroke="#e2e8f0" stroke-width="1.5"/>
<line x1="40" y1="40" x2="680" y2="40" stroke="#2563eb" stroke-width="4"/>

<text x="80" y="94" font-size="10.5" font-weight="700" fill="#94a3b8" letter-spacing="2.4">COUPON</text>

<text x="80" y="156" font-size="{{ $art->size('discount') }}" font-weight="300" fill="#0f172a">{{ $art->line('discount') }}</text>

<line x1="80" y1="180" x2="330" y2="180" stroke="#e2e8f0" stroke-width="1.5"/>

@foreach ($subject as $index => $line)
    <text x="80" y="{{ 206 + $index * 18 }}" font-size="{{ $art->size('subject') }}" fill="#64748b">{{ $line }}</text>
@endforeach

<text x="640" y="94" font-size="10.5" font-weight="700" fill="#94a3b8" letter-spacing="2"
      text-anchor="end">CODE</text>

<text x="640" y="130" font-size="{{ $art->size('code') }}" fill="#0f172a"
      font-family="Consolas, Menlo, monospace" letter-spacing="{{ $art->spacing('code') }}"
      text-anchor="end">{{ $art->line('code') }}</text>

<text x="640" y="206" font-size="{{ $art->size('expiry') }}" fill="#94a3b8"
      text-anchor="end">{{ $art->line('expiry') }}</text>
