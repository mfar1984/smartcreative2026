{{--
    One person on a registration form.

    $index may be a real number for a row rendered server side, or the literal
    string __INDEX__ when this markup sits inside a <template> that JavaScript
    clones. Keeping both paths on the same component means the added rows can
    never drift from the original ones.

    @param int|string $index
    @param array      $values     previously submitted values for this row
    @param array      $roles      role slug => label
    @param array      $genders
    @param array      $races
    @param array      $states
    @param array      $countries
    @param string     $role       decided by the event mode, submitted hidden
    @param bool       $removable
    @param string|null $title      heading for the block, which conveys the role
    @param array      $ignFields  field key => [label, required], only those asked
    @param array      $positions  position key => label; empty means no selector
    @param string     $position   the selected position key
    @param bool       $alsoPlays  whether this person holds a playing place too
--}}
@props([
    'index',
    'values' => [],
    'genders' => [],
    'races' => [],
    'states' => [],
    'countries' => [],
    'role' => 'participant',
    'removable' => false,
    'title' => null,
    'ignFields' => [],
    'positions' => [],
    'position' => 'manager_only',
    'alsoPlays' => false,
])

@php
    $name = fn (string $field) => "participants[{$index}][{$field}]";
    $id = fn (string $field) => "p-{$index}-{$field}";
    $value = fn (string $field, $default = null) => $values[$field] ?? $default;

    // Errors are keyed by the real index, which never matches __INDEX__, so
    // template rows simply never show one.
    $errorKey = fn (string $field) => "participants.{$index}.{$field}";

    $field = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
    $label = 'block text-xs font-semibold text-gray-700 mb-1';
@endphp

<fieldset class="participant-block rounded-lg border border-gray-200 bg-gray-50 p-4" data-participant>
    <legend class="sr-only">{{ $title ?? 'Participant details' }}</legend>

    <div class="flex items-center justify-between gap-3 mb-3">
        <div class="flex items-center gap-2 min-w-0">
            <span class="w-6 h-6 rounded-full bg-blue-600 text-white flex items-center justify-center text-xs font-bold shrink-0" data-participant-number aria-hidden="true">
                &nbsp;
            </span>
            <span class="text-sm font-bold text-gray-900 truncate">{{ $title ?? 'Person' }}</span>
        </div>

        @if ($removable)
            <button type="button" data-remove-participant
                    class="inline-flex items-center gap-1 text-xs font-semibold text-red-600 hover:text-red-700 transition shrink-0">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
                Remove
            </button>
        @endif
    </div>

    @if ($positions !== [])
        {{--
            The one block where the position is a real question. A squad short of
            people needs its manager on the roster, and asking here means they are
            entered once instead of twice under the same identity card.

            The select drives two hidden inputs rather than posting its own value,
            so the server still receives the plain role it has always received and
            no rule had to learn a third role name. The JS keeps them in step and
            recounts the players; without JS the pair still carries whatever the
            page was rendered with, so the form degrades to the chosen default.
        --}}
        <div class="mb-3">
            <label for="{{ $id('position') }}" class="{{ $label }}">
                Your position <span class="text-red-600" aria-hidden="true">*</span>
            </label>
            <select id="{{ $id('position') }}" data-position-select class="{{ $field }} bg-white sm:max-w-xs">
                @foreach ($positions as $positionKey => $positionLabel)
                    <option value="{{ $positionKey }}" @selected($positionKey === $position)>{{ $positionLabel }}</option>
                @endforeach
            </select>
            <p class="text-xs text-gray-500 mt-1">
                Choose "Manager and Player" if you are managing the squad and playing in it.
                You will only be entered once.
            </p>
        </div>

        <input type="hidden" name="{{ $name('role') }}" value="{{ $role }}" data-position-role>
        <input type="hidden" name="{{ $name('also_plays') }}" value="{{ $alsoPlays ? '1' : '0' }}" data-position-plays>
    @else
        {{--
            Everywhere else the role is not a question: the event's registration
            mode settles it. An individual entry is a participant, and the people a
            manager adds are players. The block heading says which this is.
        --}}
        <input type="hidden" name="{{ $name('role') }}" value="{{ $role }}">
        <input type="hidden" name="{{ $name('also_plays') }}" value="0">
    @endif
    @error($errorKey('role'))<p class="text-xs text-red-600 mb-2">{{ $message }}</p>@enderror

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">

        {{-- Identity --}}
        <div>
            <label for="{{ $id('full_name') }}" class="{{ $label }}">Full Name as per I.C. <span class="text-red-600" aria-hidden="true">*</span></label>
            <input type="text" id="{{ $id('full_name') }}" name="{{ $name('full_name') }}" required maxlength="180"
                   value="{{ $value('full_name') }}" class="{{ $field }}">
            @error($errorKey('full_name'))<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="{{ $id('ic_number') }}" class="{{ $label }}">Identity Card (IC) <span class="text-red-600" aria-hidden="true">*</span></label>
            <input type="text" id="{{ $id('ic_number') }}" name="{{ $name('ic_number') }}" required maxlength="32"
                   value="{{ $value('ic_number') }}" placeholder="900101011234" class="{{ $field }}">
            @error($errorKey('ic_number'))<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>

        {{--
            Game account. Asked only for events that need it, and placed next to
            the identity card because an organiser reads the two together: the
            card says who the person is, this says which account is playing.
        --}}
        @if ($ignFields !== [])
            @php
                // Placeholders and the monospace treatment belong to the field, not
                // to the loop, so they are looked up rather than branched on.
                $ignHints = [
                    'ign_player_id' => ['e.g. 5123456789', true],
                    'ign_server_id' => ['e.g. Asia', false],
                    'ign_name' => ['e.g. ShadowX', false],
                ];
            @endphp

            <div class="sm:col-span-2 rounded-lg border border-blue-200 bg-blue-50/60 p-3">
                <p class="text-xs font-bold text-blue-900 mb-2">In-Game Details</p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    @foreach ($ignFields as $ignField => $ignMeta)
                        @php
                            [$ignLabel, $ignRequired] = $ignMeta;
                            [$ignPlaceholder, $ignMono] = $ignHints[$ignField] ?? ['', false];
                        @endphp

                        <div>
                            <label for="{{ $id($ignField) }}" class="{{ $label }}">
                                {{ $ignLabel }}
                                @if ($ignRequired)
                                    <span class="text-red-600" aria-hidden="true">*</span>
                                @endif
                            </label>
                            <input type="text" id="{{ $id($ignField) }}" name="{{ $name($ignField) }}"
                                   @required($ignRequired) maxlength="60"
                                   value="{{ $value($ignField) }}"
                                   placeholder="{{ $ignPlaceholder }}"
                                   class="{{ $field }}{{ $ignMono ? ' font-mono' : '' }}">
                            @error($errorKey($ignField))<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div>
            <label for="{{ $id('date_of_birth') }}" class="{{ $label }}">Date of Birth</label>
            <input type="date" id="{{ $id('date_of_birth') }}" name="{{ $name('date_of_birth') }}"
                   value="{{ $value('date_of_birth') }}" class="{{ $field }}">
            @error($errorKey('date_of_birth'))<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="{{ $id('gender') }}" class="{{ $label }}">Gender <span class="text-red-600" aria-hidden="true">*</span></label>
            <select id="{{ $id('gender') }}" name="{{ $name('gender') }}" required class="{{ $field }} bg-white">
                <option value="">Select</option>
                @foreach ($genders as $genderValue => $genderLabel)
                    <option value="{{ $genderValue }}" @selected($value('gender') === $genderValue)>{{ $genderLabel }}</option>
                @endforeach
            </select>
            @error($errorKey('gender'))<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="sm:col-span-2">
            <label for="{{ $id('race') }}" class="{{ $label }}">Race <span class="text-red-600" aria-hidden="true">*</span></label>
            <select id="{{ $id('race') }}" name="{{ $name('race') }}" required class="{{ $field }} bg-white">
                <option value="">Select</option>
                @foreach ($races as $raceValue => $raceLabel)
                    <option value="{{ $raceValue }}" @selected($value('race') === $raceValue)>{{ $raceLabel }}</option>
                @endforeach
            </select>
            @error($errorKey('race'))<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>

        {{-- Address --}}
        <div class="sm:col-span-2">
            <label for="{{ $id('address_line_1') }}" class="{{ $label }}">Address 1 <span class="text-red-600" aria-hidden="true">*</span></label>
            <input type="text" id="{{ $id('address_line_1') }}" name="{{ $name('address_line_1') }}" required maxlength="180"
                   value="{{ $value('address_line_1') }}" class="{{ $field }}">
            @error($errorKey('address_line_1'))<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="sm:col-span-2">
            <label for="{{ $id('address_line_2') }}" class="{{ $label }}">Address 2</label>
            <input type="text" id="{{ $id('address_line_2') }}" name="{{ $name('address_line_2') }}" maxlength="180"
                   value="{{ $value('address_line_2') }}" class="{{ $field }}">
            @error($errorKey('address_line_2'))<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="{{ $id('postcode') }}" class="{{ $label }}">Postcode</label>
            <input type="text" id="{{ $id('postcode') }}" name="{{ $name('postcode') }}" maxlength="12"
                   value="{{ $value('postcode') }}" class="{{ $field }}">
            @error($errorKey('postcode'))<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="{{ $id('city') }}" class="{{ $label }}">City <span class="text-red-600" aria-hidden="true">*</span></label>
            <input type="text" id="{{ $id('city') }}" name="{{ $name('city') }}" required maxlength="100"
                   value="{{ $value('city') }}" class="{{ $field }}">
            @error($errorKey('city'))<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="{{ $id('state') }}" class="{{ $label }}">State <span class="text-red-600" aria-hidden="true">*</span></label>
            <input type="text" id="{{ $id('state') }}" name="{{ $name('state') }}" required maxlength="100"
                   list="state-options" value="{{ $value('state') }}" class="{{ $field }}">
            @error($errorKey('state'))<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="{{ $id('country') }}" class="{{ $label }}">Country <span class="text-red-600" aria-hidden="true">*</span></label>
            <select id="{{ $id('country') }}" name="{{ $name('country') }}" required class="{{ $field }} bg-white">
                @foreach ($countries as $countryOption)
                    <option value="{{ $countryOption }}" @selected($value('country', 'Malaysia') === $countryOption)>{{ $countryOption }}</option>
                @endforeach
            </select>
            @error($errorKey('country'))<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>

        {{-- Contact --}}
        <div>
            <label for="{{ $id('phone') }}" class="{{ $label }}">Telephone Number <span class="text-red-600" aria-hidden="true">*</span></label>
            <input type="tel" id="{{ $id('phone') }}" name="{{ $name('phone') }}" required maxlength="30"
                   value="{{ $value('phone') }}" placeholder="019-866 6898" class="{{ $field }}">
            @error($errorKey('phone'))<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="{{ $id('email') }}" class="{{ $label }}">Email <span class="text-red-600" aria-hidden="true">*</span></label>
            <input type="email" id="{{ $id('email') }}" name="{{ $name('email') }}" required maxlength="190"
                   value="{{ $value('email') }}" class="{{ $field }}">
            @error($errorKey('email'))<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>

        {{-- Emergency contact --}}
        <div>
            <label for="{{ $id('emergency_contact_name') }}" class="{{ $label }}">Emergency Contact Name</label>
            <input type="text" id="{{ $id('emergency_contact_name') }}" name="{{ $name('emergency_contact_name') }}" maxlength="180"
                   value="{{ $value('emergency_contact_name') }}" class="{{ $field }}">
            @error($errorKey('emergency_contact_name'))<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="{{ $id('emergency_contact_phone') }}" class="{{ $label }}">Emergency Contact Number</label>
            <input type="tel" id="{{ $id('emergency_contact_phone') }}" name="{{ $name('emergency_contact_phone') }}" maxlength="30"
                   value="{{ $value('emergency_contact_phone') }}" class="{{ $field }}">
            @error($errorKey('emergency_contact_phone'))<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>
    </div>

    {{--
        Agreement to be contacted about anything other than this entry.

        Kept apart from the fields above and never ticked by default. Entering the
        event is one thing and hearing from us afterwards is another, so the answer
        has to be given rather than assumed.

        A hidden 0 goes first because an unticked box sends nothing at all, and
        without it the absence would read as "not answered" instead of "no".
    --}}
    <div class="mt-4 pt-4 border-t border-gray-200">
        <input type="hidden" name="{{ $name('marketing_consent') }}" value="0">

        <label for="{{ $id('marketing_consent') }}" class="flex items-start gap-2.5 cursor-pointer group">
            <input type="checkbox"
                   id="{{ $id('marketing_consent') }}"
                   name="{{ $name('marketing_consent') }}"
                   value="1"
                   data-consent-box
                   @checked((bool) $value('marketing_consent'))
                   class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-400 text-blue-600 focus:ring-2 focus:ring-blue-500/40">

            <span class="text-xs text-gray-600 group-hover:text-gray-800">
                Happy to hear about future events, news and offers by email or SMS.
                <span class="block text-gray-400 mt-0.5">
                    Optional, and separate from this registration. You can stop at any time
                    using the link in any message we send.
                </span>
            </span>
        </label>
    </div>
</fieldset>
