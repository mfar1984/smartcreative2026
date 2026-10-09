{{--
    One coupon, drawn in whichever design its batch names.

    The only entry point. Every caller renders <x-coupon.ticket> and the design is
    resolved from the stored key by CouponTicket, so nothing outside that class maps a
    key to a file and adding a design means adding a component, not editing a switch.

    @param \App\Models\Coupon $coupon
    @param string|null $subject   what it is for: an event title, a product name
    @param string|null $code      the code somebody holds; defaults to the batch name
    @param bool $compact          drawn small, for a picker preview
--}}

@props([
    'coupon',
    'subject' => null,
    'code' => null,
    'compact' => false,
])

@php
    $ticket = \App\Support\CouponTicket::for($coupon, $subject, $code);
@endphp

<x-dynamic-component
    :component="$ticket->component()"
    :ticket="$ticket"
    :compact="$compact"
    {{ $attributes }} />
