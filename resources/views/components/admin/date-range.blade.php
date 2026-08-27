{{--
    From and to date inputs for a payments filter bar.

    Pulled into a component because six screens carry the same pair, and a range
    that behaves differently on one of them would make its figures look wrong
    rather than different.

    @param string|null $from
    @param string|null $to
--}}
@props([
    'from' => null,
    'to' => null,
])

@php
    $input = 'rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
@endphp

<label for="from" class="text-xs font-semibold text-gray-500">From</label>
<input type="date" id="from" name="from" value="{{ $from }}" class="{{ $input }}">

<label for="to" class="text-xs font-semibold text-gray-500">To</label>
<input type="date" id="to" name="to" value="{{ $to }}" class="{{ $input }}">
