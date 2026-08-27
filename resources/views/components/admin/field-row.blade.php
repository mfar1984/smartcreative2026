{{--
    One form row: label and helper text on the left, control on the right.

    Collapses to a stacked layout below the md breakpoint so the label never
    gets squeezed on a phone.

    @param string      $label
    @param string|null $help
    @param string|null $for       id of the control, so the label is clickable
    @param bool        $required
    @param string|null $error     field name to pull a validation message for
--}}
@props([
    'label',
    'help' => null,
    'for' => null,
    'required' => false,
    'error' => null,
])

<div class="grid grid-cols-1 md:grid-cols-[240px_minmax(0,1fr)] gap-1.5 md:gap-6 px-5 py-4">

    {{-- Left: label --}}
    <div class="md:pt-2.5">
        <label @if ($for) for="{{ $for }}" @endif class="block text-sm font-semibold text-gray-700">
            {{ $label }}
            @if ($required)
                <span class="text-red-600" aria-hidden="true">*</span>
            @endif
        </label>

        @if ($help)
            <p class="text-xs text-gray-500 mt-0.5 leading-snug">{{ $help }}</p>
        @endif
    </div>

    {{-- Right: control --}}
    <div class="min-w-0">
        {{ $slot }}

        @if ($error)
            @error($error)
                <p class="text-xs text-red-600 mt-1.5">{{ $message }}</p>
            @enderror
        @endif
    </div>
</div>
