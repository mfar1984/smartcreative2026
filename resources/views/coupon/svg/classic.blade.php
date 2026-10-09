{{--
    Classic, as SVG: a perforated ticket with the code on a torn-off stub.

    Geometry lifted from the approved artwork. The notches are circles filled with the
    page colour rather than a cut-out, which is why the canvas carries a background:
    a real hole would need a mask and would show the wrong colour anywhere else.

    The expiry moves down with a second line of title instead of being overlapped by
    it, which is the only thing here the artwork did not have to decide.

    @param \App\Support\CouponArtwork $art
--}}
@php
    $subject = $art->lines('subject');
@endphp

<rect x="40" y="40" width="640" height="200" rx="10" fill="#ffffff" stroke="#cbd5e1" stroke-width="1.5"/>
<rect x="40" y="40" width="8" height="200" rx="4" fill="#2563eb"/>

<text x="78" y="82" font-size="{{ $art->size('eyebrow') }}" font-weight="700" fill="#2563eb"
      letter-spacing="{{ $art->spacing('eyebrow') }}">{{ $art->line('eyebrow') }}</text>

<text x="78" y="142" font-size="{{ $art->size('discount') }}" font-weight="700" fill="#0f172a">{{ $art->line('discount') }}</text>

@foreach ($subject as $index => $line)
    <text x="78" y="{{ 174 + $index * 18 }}" font-size="{{ $art->size('subject') }}" fill="#475569">{{ $line }}</text>
@endforeach

<text x="78" y="{{ 202 + (max(1, count($subject)) - 1) * 18 }}" font-size="{{ $art->size('expiry') }}" fill="#94a3b8">{{ $art->line('expiry') }}</text>

{{-- The tear, and the two notches that make it read as a ticket. --}}
<line x1="498" y1="62" x2="498" y2="218" stroke="#cbd5e1" stroke-width="1.5" stroke-dasharray="5 5"/>
<circle cx="498" cy="40" r="11" fill="#f1f5f9"/>
<circle cx="498" cy="240" r="11" fill="#f1f5f9"/>

<text x="589" y="122" font-size="10.5" font-weight="700" fill="#94a3b8" letter-spacing="1.4"
      text-anchor="middle">CODE</text>

<text x="589" y="156" font-size="{{ $art->size('code') }}" font-weight="700" fill="#0f172a"
      font-family="Consolas, Menlo, monospace" letter-spacing="{{ $art->spacing('code') }}"
      text-anchor="middle">{{ $art->line('code') }}</text>
