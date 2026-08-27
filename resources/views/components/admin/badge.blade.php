{{--
    Small status pill.

    @param string $tone  green | red | amber | blue | purple | gray
    @param bool   $dot   show a leading dot
--}}
@props([
    'tone' => 'gray',
    'dot' => false,
])

@php
    // Full class strings so Tailwind finds them when scanning.
    $tones = [
        'green' => 'bg-green-100 text-green-800',
        'red' => 'bg-red-100 text-red-800',
        'amber' => 'bg-amber-100 text-amber-800',
        'blue' => 'bg-blue-100 text-blue-800',
        'purple' => 'bg-purple-100 text-purple-800',
        'gray' => 'bg-gray-100 text-gray-700',
    ];
    $dots = [
        'green' => 'bg-green-500',
        'red' => 'bg-red-500',
        'amber' => 'bg-amber-500',
        'blue' => 'bg-blue-500',
        'purple' => 'bg-purple-500',
        'gray' => 'bg-gray-400',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold ' . ($tones[$tone] ?? $tones['gray'])]) }}>
    @if ($dot)
        <span class="w-1.5 h-1.5 rounded-full {{ $dots[$tone] ?? $dots['gray'] }}" aria-hidden="true"></span>
    @endif
    {{ $slot }}
</span>
