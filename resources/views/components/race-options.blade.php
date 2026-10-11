{{--
    The <option> list for a race select, grouped.

    One component because four screens offer this field and the list is 77 long:
    repeating the loops would be four places to forget an optgroup.

    @param array       $groups    group label => [key => label], from ParticipantOptions::raceGroups()
    @param string|null $selected  the value already stored, to mark as chosen

    A group keyed '' is drawn as plain options with no heading. That is "Other",
    and it sits at the bottom. The empty "Select" or "Not recorded" option stays
    with the caller, because the wording differs between the public form and the
    admin screens.
--}}
@props(['groups' => [], 'selected' => null])

@foreach ($groups as $groupLabel => $options)
    @if ($groupLabel === '')
        @foreach ($options as $raceKey => $raceText)
            <option value="{{ $raceKey }}" @selected((string) $selected === (string) $raceKey)>{{ $raceText }}</option>
        @endforeach
    @else
        <optgroup label="{{ $groupLabel }}">
            @foreach ($options as $raceKey => $raceText)
                <option value="{{ $raceKey }}" @selected((string) $selected === (string) $raceKey)>{{ $raceText }}</option>
            @endforeach
        </optgroup>
    @endif
@endforeach
