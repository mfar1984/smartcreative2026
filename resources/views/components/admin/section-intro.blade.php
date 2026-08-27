{{--
    Introduction block that sits above a tab's panels: a coloured icon tile
    next to the section name and a one line explanation.

    @param string      $title
    @param string|null $description
    @param string      $icon
    @param string      $accent  blue | green | amber | purple
--}}
@props([
    'title',
    'description' => null,
    'icon' => 'sliders',
    'accent' => 'blue',
])

@php
    // Written out in full so Tailwind picks the classes up when scanning.
    $accentClasses = [
        'blue' => 'bg-blue-600',
        'green' => 'bg-green-600',
        'amber' => 'bg-amber-500',
        'purple' => 'bg-purple-600',
    ];
    $tile = $accentClasses[$accent] ?? $accentClasses['blue'];
@endphp

<div {{ $attributes->merge(['class' => 'flex items-start gap-4 mb-5']) }}>
    <span class="{{ $tile }} p-2.5 rounded-lg shrink-0" aria-hidden="true">
        <x-admin.icon :name="$icon" class="w-5 h-5 text-white" />
    </span>

    <div class="min-w-0 pt-0.5">
        <h2 class="text-base font-bold text-gray-900">{{ $title }}</h2>
        @if ($description)
            <p class="text-xs text-gray-500 mt-0.5">{{ $description }}</p>
        @endif
    </div>
</div>
