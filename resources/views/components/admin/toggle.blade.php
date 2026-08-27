{{--
    Switch style checkbox.

    Built from a real <input type="checkbox"> so it keeps keyboard focus, form
    submission and screen reader behaviour. The track and the knob are both
    siblings of the input, because Tailwind's peer-* variants only reach
    following siblings, not nested elements.

    An unchecked box sends nothing, so read it with $request->boolean().

    @param string      $name
    @param bool        $checked
    @param string|null $id
    @param string|null $label     text shown next to the switch
    @param bool        $disabled
--}}
@props([
    'name',
    'checked' => false,
    'id' => null,
    'label' => null,
    'disabled' => false,
])

@php $inputId = $id ?? $name; @endphp

<label for="{{ $inputId }}" @class(['inline-flex items-center gap-2.5', 'cursor-pointer' => ! $disabled])>
    <span class="relative inline-flex shrink-0">
        <input type="checkbox"
               id="{{ $inputId }}"
               name="{{ $name }}"
               value="1"
               @checked($checked)
               @disabled($disabled)
               class="peer sr-only">

        {{-- Track --}}
        <span class="block w-10 h-6 rounded-full bg-gray-300 transition-colors
                     peer-checked:bg-blue-600
                     peer-focus-visible:ring-2 peer-focus-visible:ring-blue-500/40 peer-focus-visible:ring-offset-2
                     peer-disabled:opacity-50" aria-hidden="true"></span>

        {{-- Knob --}}
        <span class="pointer-events-none absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow
                     transition-transform peer-checked:translate-x-4
                     peer-disabled:opacity-50" aria-hidden="true"></span>
    </span>

    @if ($label)
        <span @class(['text-sm', $disabled ? 'text-gray-400' : 'text-gray-700'])>{{ $label }}</span>
    @endif
</label>
