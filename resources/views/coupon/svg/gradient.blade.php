{{--
    Gradient, as SVG: blue through indigo to violet, white type, a frosted code chip.

    The gradient and the clip live in this partial rather than in the wrapper, because
    <defs> is valid anywhere in the document and nothing else needs them. The two soft
    circles are clipped to the card, which is what the web version's overflow-hidden
    does.

    @param \App\Support\CouponArtwork $art
--}}
@php
    $subject = $art->lines('subject');
@endphp

<defs>
    <linearGradient id="coupon-gradient" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0%" stop-color="#2563eb"/>
        <stop offset="55%" stop-color="#4338ca"/>
        <stop offset="100%" stop-color="#6d28d9"/>
    </linearGradient>

    <clipPath id="coupon-card">
        <rect x="40" y="40" width="640" height="200" rx="18"/>
    </clipPath>
</defs>

<g clip-path="url(#coupon-card)">
    <rect x="40" y="40" width="640" height="200" fill="url(#coupon-gradient)"/>
    <circle cx="628" cy="74" r="130" fill="#ffffff" opacity="0.08"/>
    <circle cx="560" cy="230" r="88" fill="#ffffff" opacity="0.06"/>
</g>

<text x="78" y="84" font-size="{{ $art->size('eyebrow') }}" font-weight="700" fill="#c7d2fe"
      letter-spacing="{{ $art->spacing('eyebrow') }}">{{ $art->line('eyebrow') }}</text>

<text x="78" y="146" font-size="{{ $art->size('discount') }}" font-weight="700" fill="#ffffff">{{ $art->line('discount') }}</text>

@foreach ($subject as $index => $line)
    <text x="78" y="{{ 176 + $index * 18 }}" font-size="{{ $art->size('subject') }}" fill="#dbeafe">{{ $line }}</text>
@endforeach

<text x="78" y="{{ 208 + (max(1, count($subject)) - 1) * 18 }}" font-size="{{ $art->size('expiry') }}" fill="#a5b4fc">{{ $art->line('expiry') }}</text>

<rect x="456" y="110" width="188" height="58" rx="10" fill="#ffffff" opacity="0.16"/>
<rect x="456" y="110" width="188" height="58" rx="10" fill="none" stroke="#ffffff" stroke-width="1.2" opacity="0.45"/>

<text x="550" y="132" font-size="9.5" font-weight="700" fill="#e0e7ff" letter-spacing="2"
      text-anchor="middle">CODE</text>

<text x="550" y="158" font-size="{{ $art->size('code') }}" font-weight="700" fill="#ffffff"
      font-family="Consolas, Menlo, monospace" letter-spacing="{{ $art->spacing('code') }}"
      text-anchor="middle">{{ $art->line('code') }}</text>
