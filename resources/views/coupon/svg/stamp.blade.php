{{--
    Stamp, as SVG: warm paper stock with a red rubber-stamp box around the code.

    The box is rotated -7 degrees about its own centre, as in the artwork. Rotated, its
    corners reach roughly x=449..659 and y=80..200, so it stays inside the card — which
    is why the rotation is kept here and dropped on a narrow phone in the web version.

    @param \App\Support\CouponArtwork $art
--}}
@php
    $subject = $art->lines('subject');
@endphp

<rect x="40" y="40" width="640" height="200" rx="10" fill="#fdfbf5" stroke="#e5ddc8" stroke-width="1.5"/>

<text x="78" y="90" font-size="{{ $art->size('eyebrow') }}" font-weight="700" fill="#a16207"
      letter-spacing="{{ $art->spacing('eyebrow') }}">{{ $art->line('eyebrow') }}</text>

<text x="78" y="142" font-size="{{ $art->size('discount') }}" font-weight="700" fill="#1c1917">{{ $art->line('discount') }}</text>

@foreach ($subject as $index => $line)
    <text x="78" y="{{ 172 + $index * 18 }}" font-size="{{ $art->size('subject') }}" fill="#57534e">{{ $line }}</text>
@endforeach

<text x="78" y="{{ 200 + (max(1, count($subject)) - 1) * 18 }}" font-size="{{ $art->size('expiry') }}" fill="#a8a29e">{{ $art->line('expiry') }}</text>

<g transform="rotate(-7 554 140)">
    <rect x="454" y="92" width="200" height="96" rx="6" fill="none" stroke="#b91c1c" stroke-width="3"/>
    <rect x="461" y="99" width="186" height="82" rx="3" fill="none" stroke="#b91c1c" stroke-width="1.2"/>

    <text x="554" y="128" font-size="9.5" font-weight="700" fill="#b91c1c" letter-spacing="2"
          text-anchor="middle">COUPON CODE</text>

    <text x="554" y="162" font-size="{{ $art->size('code') }}" font-weight="700" fill="#b91c1c"
          font-family="Consolas, Menlo, monospace" letter-spacing="{{ $art->spacing('code') }}"
          text-anchor="middle">{{ $art->line('code') }}</text>
</g>
