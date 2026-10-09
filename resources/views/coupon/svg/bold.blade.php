{{--
    Bold, as SVG: a dark block on the left carrying the discount as the hero.

    The block is 200 units wide and the artwork sets its figure at 62px, which is sized
    for "30". A ringgit coupon puts "1,250.00" in the same place, so the figure steps
    down to whatever fits rather than painting over the edge of the block — the one
    degradation the owner called out by name.

    @param \App\Support\CouponArtwork $art
--}}
@php
    $subject = $art->lines('subject');
@endphp

<rect x="40" y="40" width="640" height="200" rx="10" fill="#ffffff" stroke="#cbd5e1" stroke-width="1.5"/>
<path d="M40 50 a10 10 0 0 1 10 -10 h200 v200 h-200 a10 10 0 0 1 -10 -10 z" fill="#0f172a"/>

<text x="150" y="130" font-size="{{ $art->size('hero') }}" font-weight="700" fill="#ffffff"
      text-anchor="middle">{{ $art->line('hero') }}</text>

<text x="150" y="166" font-size="{{ $art->size('unit') }}" font-weight="700" fill="#60a5fa"
      text-anchor="middle">{{ $art->line('unit') }}</text>

<text x="150" y="194" font-size="11" fill="#64748b" letter-spacing="1.4" text-anchor="middle">DISCOUNT</text>

<text x="286" y="86" font-size="{{ $art->size('eyebrow') }}" font-weight="700" fill="#2563eb"
      letter-spacing="{{ $art->spacing('eyebrow') }}">{{ $art->line('eyebrow') }}</text>

@foreach ($subject as $index => $line)
    <text x="286" y="{{ 120 + $index * 22 }}" font-size="{{ $art->size('subject') }}" font-weight="600"
          fill="#0f172a">{{ $line }}</text>
@endforeach

<rect x="286" y="164" width="186" height="42" rx="7" fill="#eff6ff" stroke="#bfdbfe" stroke-width="1.5"/>

<text x="379" y="191" font-size="{{ $art->size('code') }}" font-weight="700" fill="#1d4ed8"
      font-family="Consolas, Menlo, monospace" letter-spacing="{{ $art->spacing('code') }}"
      text-anchor="middle">{{ $art->line('code') }}</text>

{{-- Anchored to the right edge of the card, so a long date format grows towards the
     chip rather than off the paper. --}}
<text x="652" y="191" font-size="{{ $art->size('expiry') }}" fill="#94a3b8"
      text-anchor="end">{{ $art->line('expiry') }}</text>
