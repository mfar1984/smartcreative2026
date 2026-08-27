{{--
    Renders one schema driven field as a label/control row.

    Keeps the field-type switch in a single place so every settings screen that
    is described by a schema array looks and behaves the same.

    @param string      $name
    @param array       $field      label, type, options, help, placeholder, secret
    @param string|null $value      stored value; always null for secrets
    @param bool        $hasSecret  a secret value is already stored
    @param bool        $canUpdate
--}}
@props([
    'name',
    'field',
    'value' => null,
    'hasSecret' => false,
    'canUpdate' => true,
])

@php
    $type = $field['type'] ?? 'text';
    $isSecret = $field['secret'] ?? false;
    $isToggle = $type === 'toggle';

    $inputClasses = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition disabled:bg-gray-100 disabled:text-gray-500';

    // A toggle carries its own label next to the switch, so the row label is
    // reduced to a short heading and the explanation moves to the control side.
    $rowLabel = $isToggle ? ($field['row_label'] ?? $field['label']) : $field['label'];
    $rowHelp = $isToggle ? null : ($field['help'] ?? null);

    $htmlType = match ($type) {
        'password' => 'password',
        'number' => 'number',
        'email' => 'email',
        'url' => 'url',
        default => 'text',
    };
@endphp

<x-admin.field-row
    :label="$rowLabel"
    :help="$rowHelp"
    :for="$isToggle ? $name : $name"
    :required="in_array('required', $field['rules'] ?? [], true)"
    :error="$name">

    @if ($isToggle)
        <div class="md:pt-1">
            <x-admin.toggle :name="$name" :id="$name" :checked="$value === '1'" :label="$field['label']" :disabled="! $canUpdate" />
            @isset($field['help'])
                <p class="text-xs text-gray-500 mt-1.5">{{ $field['help'] }}</p>
            @endisset
        </div>

    @elseif ($type === 'select')
        <select id="{{ $name }}" name="{{ $name }}" @disabled(! $canUpdate) class="{{ $inputClasses }} bg-white">
            @foreach ($field['options'] as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected(old($name, $value) === $optionValue)>{{ $optionLabel }}</option>
            @endforeach
        </select>

    @elseif ($type === 'textarea')
        <textarea id="{{ $name }}" name="{{ $name }}" rows="{{ $field['rows'] ?? 4 }}"
                  @isset($field['placeholder']) placeholder="{{ $field['placeholder'] }}" @endisset
                  @disabled(! $canUpdate)
                  class="{{ $inputClasses }} resize-y @if (($field['rows'] ?? 4) > 5) font-mono text-xs @endif">{{ old($name, $value) }}</textarea>

    @else
        <input type="{{ $htmlType }}"
               id="{{ $name }}"
               name="{{ $name }}"
               value="{{ $isSecret ? '' : old($name, $value) }}"
               @isset($field['placeholder']) placeholder="{{ $field['placeholder'] }}" @endisset
               @if ($isSecret) autocomplete="new-password" @endif
               @disabled(! $canUpdate)
               class="{{ $inputClasses }}">

        @if ($isSecret)
            <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 mt-1.5">
                @if ($hasSecret)
                    <x-admin.badge tone="green">Saved</x-admin.badge>
                    <span>Stored encrypted. Leave blank to keep it, or type a new value to replace it.</span>
                @else
                    <span>Stored encrypted. It is never shown again after saving.</span>
                @endif
            </p>
        @endif
    @endif
</x-admin.field-row>
