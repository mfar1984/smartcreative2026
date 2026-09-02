@extends('layouts.admin')

@php
    use App\Models\Event;
    use App\Support\ParticipantOptions;

    $isCreate = $mode === 'create';
    $heading = $isCreate ? 'Create Registration Event' : 'Edit Registration Event';
    $action = $isCreate
        ? route('admin.event.registration.store')
        : route('admin.event.registration.update', $event);

    $input = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
    $isManagerMode = old('registration_mode', $event->registration_mode) === Event::MODE_MANAGER;
@endphp

@section('title', $heading)

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Event</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.event.registration') }}" class="hover:text-gray-700 transition">Registration</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $isCreate ? 'Create' : 'Edit' }}</span>
@endsection

@section('content')
    <form action="{{ $action }}" method="POST" enctype="multipart/form-data" id="event-form">
        @csrf
        @unless ($isCreate)
            @method('PUT')
        @endunless

        <x-admin.page-card
            :title="$heading"
            description="Set up the event and decide how people may register for it."
            :back="route('admin.event.registration')">

            <x-slot:actions>
                <a href="{{ route('admin.event.registration') }}"
                   class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                    Cancel
                </a>
                <button type="submit"
                        class="inline-flex items-center gap-2 bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1-4l-4 4m0 0L8 3m4 4V3"/>
                    </svg>
                    {{ $isCreate ? 'Save Event' : 'Save Changes' }}
                </button>
            </x-slot:actions>

            <x-admin.section-intro
                title="Event Details"
                description="What the event is, when it runs, and where."
                icon="clipboard" />

            {{-- ---------------- Poster ---------------- --}}
            <x-admin.panel title="Poster" icon="grid">
                <x-admin.field-row
                    label="Event Poster"
                    help="JPG, PNG or WebP up to 4 MB. Shown at the top of the event card."
                    for="poster"
                    error="poster">

                    <div class="flex flex-wrap items-start gap-4">
                        <div class="w-40 h-28 shrink-0 rounded-lg border border-gray-200 bg-gray-50 overflow-hidden flex items-center justify-center">
                            <img id="poster-preview"
                                 src="{{ $event->posterUrl() ?? '' }}"
                                 alt="Poster preview"
                                 @class(['w-full h-full object-cover', 'hidden' => ! $event->posterUrl()])>
                            <span id="poster-empty"
                                  @class(['text-xs text-gray-400 px-3 text-center', 'hidden' => (bool) $event->posterUrl()])>
                                No poster uploaded
                            </span>
                        </div>

                        <div class="flex-1 min-w-48 space-y-2">
                            <input type="file" id="poster" name="poster" accept="image/jpeg,image/png,image/webp"
                                   class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-blue-700 file:cursor-pointer">

                            @if ($event->posterUrl())
                                <x-admin.toggle name="remove_poster" :checked="old('remove_poster')" label="Remove the current poster" />
                            @endif
                        </div>
                    </div>
                </x-admin.field-row>
            </x-admin.panel>

            {{-- ---------------- Identity ---------------- --}}
            <x-admin.panel title="Event Identity" icon="identification">
                <x-admin.field-row label="Event Name" help="Shown as the card title on the public site." for="title" :required="true" error="title">
                    <input type="text" id="title" name="title" required maxlength="180"
                           value="{{ old('title', $event->title) }}"
                           placeholder="e.g. National Futsal Championship 2026"
                           class="{{ $input }}">
                </x-admin.field-row>

                <x-admin.field-row label="URL Slug" help="Leave blank to build one from the name." for="slug" error="slug">
                    <input type="text" id="slug" name="slug" maxlength="180"
                           value="{{ old('slug', $event->slug) }}"
                           placeholder="national-futsal-championship-2026"
                           class="{{ $input }}">
                </x-admin.field-row>

                <x-admin.field-row label="Category" help="Groups the event on the public list and in reporting." for="category" :required="true" error="category">
                    <input type="text" id="category" name="category" required maxlength="100" list="category-options"
                           value="{{ old('category', $event->category) }}"
                           placeholder="e.g. Sports Event"
                           class="{{ $input }}">
                    <datalist id="category-options">
                        @foreach ($categories as $option)
                            <option value="{{ $option }}"></option>
                        @endforeach
                    </datalist>
                </x-admin.field-row>

                <x-admin.field-row label="Description" help="A short paragraph shown on the card." for="description" error="description">
                    <textarea id="description" name="description" rows="4" maxlength="5000"
                              class="{{ $input }} resize-y">{{ old('description', $event->description) }}</textarea>
                </x-admin.field-row>

                <x-admin.field-row
                    label="Rules"
                    help="Shown beside the registration form so entrants read them before submitting."
                    for="rules"
                    error="rules">

                    <textarea id="rules" name="rules" rows="10" maxlength="10000"
                              placeholder="One rule per line, for example:&#10;Squads must have 4 players and up to 2 reserves.&#10;All players must be 16 or older.&#10;Emulators are not permitted."
                              class="{{ $input }} resize-y">{{ old('rules', $event->rules) }}</textarea>

                    <p class="text-xs text-gray-500 mt-2">
                        One rule per line. Each line becomes a bullet point. Plain text only, so
                        anything that looks like HTML is shown as typed rather than rendered.
                        Leave blank and no rules section appears.
                    </p>
                </x-admin.field-row>
            </x-admin.panel>

            {{-- ---------------- When and where ---------------- --}}
            <x-admin.panel title="Schedule &amp; Venue" icon="globe">
                <x-admin.field-row label="Date From" help="First day of the event." for="starts_at" :required="true" error="starts_at">
                    <input type="date" id="starts_at" name="starts_at" required
                           value="{{ old('starts_at', $event->starts_at?->toDateString()) }}"
                           class="{{ $input }}">
                </x-admin.field-row>

                <x-admin.field-row label="Date End" help="Same as the start date for a one day event." for="ends_at" :required="true" error="ends_at">
                    <input type="date" id="ends_at" name="ends_at" required
                           value="{{ old('ends_at', $event->ends_at?->toDateString()) }}"
                           class="{{ $input }}">
                </x-admin.field-row>

                <x-admin.field-row label="Time" help="Free text, for example 9:00 am - 5:00 pm." for="time" error="time">
                    <input type="text" id="time" name="time" maxlength="100"
                           value="{{ old('time', $event->time) }}"
                           placeholder="9:00 am - 5:00 pm"
                           class="{{ $input }}">
                </x-admin.field-row>

                <x-admin.field-row label="Location" help="Venue name, shown on the card." for="location" :required="true" error="location">
                    <input type="text" id="location" name="location" required maxlength="180"
                           value="{{ old('location', $event->location) }}"
                           placeholder="e.g. Kompleks Sukan Negara, Bukit Jalil"
                           class="{{ $input }}">
                </x-admin.field-row>

                <x-admin.field-row label="Address" help="Full postal address. One line per row." for="address" error="address">
                    <textarea id="address" name="address" rows="3" maxlength="500"
                              class="{{ $input }} resize-y">{{ old('address', $event->address) }}</textarea>
                </x-admin.field-row>
            </x-admin.panel>

            {{-- ---------------- Price ---------------- --}}
            <x-admin.panel title="Price &amp; Capacity" icon="credit-card">
                <x-admin.field-row
                    label="Price For Event"
                    help="One flat charge per registration, not per head. Leave blank for a free event."
                    for="fee"
                    error="fee">

                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-gray-500 shrink-0">{{ $payment['currency'] }}</span>
                        <input type="number" id="fee" name="fee" step="0.01" min="0" max="999999.99"
                               value="{{ old('fee', $event->fee) }}"
                               placeholder="0.00"
                               class="{{ $input }}">
                    </div>

                    {{-- Spells out the manager case, which is the one that is
                         easy to misread as a per head price. --}}
                    <p class="text-xs text-gray-500 mt-2" id="fee-basis-note">
                        <span @class(['hidden' => ! $isManagerMode]) data-fee-basis="manager">
                            A manager pays this once for the whole squad, whether they enter 2 players or 20.
                        </span>
                        <span @class(['hidden' => $isManagerMode]) data-fee-basis="individual">
                            Each person registering pays this once.
                        </span>
                    </p>

                    <div @class([
                        'flex items-start gap-2 rounded-lg border p-3 mt-2',
                        'bg-green-50 border-green-200' => $payment['ready'],
                        'bg-amber-50 border-amber-200' => ! $payment['ready'],
                    ])>
                        <x-admin.icon :name="$payment['ready'] ? 'shield' : 'lock'"
                                      @class(['w-4 h-4 mt-0.5 shrink-0', 'text-green-600' => $payment['ready'], 'text-amber-600' => ! $payment['ready']]) />
                        <p @class(['text-xs', 'text-green-800' => $payment['ready'], 'text-amber-800' => ! $payment['ready']])>
                            <span class="font-semibold">Payment gateway:</span> {{ $payment['summary'] }}
                            @unless ($payment['ready'])
                                A paid event cannot be collected until this is finished.
                                <a href="{{ route('admin.settings.integration', ['tab' => 'payments']) }}" class="underline font-semibold">Open payment settings</a>.
                            @endunless
                        </p>
                    </div>
                </x-admin.field-row>

                <x-admin.field-row label="Total Seats" help="Use 0 for unlimited capacity." for="seats_total" :required="true" error="seats_total">
                    <input type="number" id="seats_total" name="seats_total" required min="0" max="100000"
                           value="{{ old('seats_total', $event->seats_total ?? 0) }}"
                           class="{{ $input }}">
                </x-admin.field-row>

                <x-admin.field-row label="Status" help="Only Open and Closing Soon accept registrations." for="status" :required="true" error="status">
                    <select id="status" name="status" required class="{{ $input }} bg-white">
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected(old('status', $event->status) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-admin.field-row>
            </x-admin.panel>

            {{-- ---------------- Paid add-ons ---------------- --}}
            @include('admin.event.partials.addons', [
                'event' => $event,
                'input' => $input,
                'currency' => $payment['currency'],
            ])

            {{-- ---------------- How people register ---------------- --}}
            <x-admin.panel title="Registration Rules" icon="users">
                <x-admin.field-row label="Registration Mode" help="Decides what the public form asks for." for="registration_mode" :required="true" error="registration_mode">
                    <select id="registration_mode" name="registration_mode" required class="{{ $input }} bg-white">
                        @foreach ($modes as $value => $label)
                            <option value="{{ $value }}" @selected(old('registration_mode', $event->registration_mode) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-admin.field-row>

                <div id="player-bounds" @class(['divide-y divide-gray-100', 'hidden' => ! $isManagerMode])>
                    <x-admin.field-row label="Minimum Players" help="Fewest players a manager must enter." for="min_players" error="min_players">
                        <input type="number" id="min_players" name="min_players" min="1" max="1000"
                               value="{{ old('min_players', $event->min_players ?? 1) }}"
                               class="{{ $input }}">
                    </x-admin.field-row>

                    <x-admin.field-row label="Maximum Players" help="Leave blank for no limit." for="max_players" error="max_players">
                        <input type="number" id="max_players" name="max_players" min="1" max="1000"
                               value="{{ old('max_players', $event->max_players) }}"
                               placeholder="No limit"
                               class="{{ $input }}">
                    </x-admin.field-row>
                </div>

                {{--
                    In-Game fields. Each is asked and made compulsory separately,
                    because the two are different questions: a tournament may want
                    an in-game name on the scoreboard without insisting on a server
                    id nobody can find.

                    The compulsory box is disabled until the field is asked for, so
                    the form cannot express "not asked but required". The request
                    forces it false as well, since a disabled box is only a UI
                    courtesy and sends nothing anyway.
                --}}
                <x-admin.field-row
                    label="In-Game Fields"
                    help="For tournaments, where a name on an identity card does not say which account is playing.">

                    <div class="space-y-3" data-ign-fields>
                        @foreach ([
                            ['ask' => 'asks_player_id', 'req' => 'requires_player_id', 'label' => 'Player ID', 'hint' => 'The numeric account id, for example 5123456789.'],
                            ['ask' => 'asks_server_id', 'req' => 'requires_server_id', 'label' => 'Server ID', 'hint' => 'The region or server the account plays on, for example Asia.'],
                            ['ask' => 'asks_ign_name', 'req' => 'requires_ign_name', 'label' => 'Player In-Game Name', 'hint' => 'The display name shown in the game, which is not the name on the I.C.'],
                        ] as $ignRow)
                            @php
                                $asked = (bool) old($ignRow['ask'], $event->{$ignRow['ask']});
                                $required = (bool) old($ignRow['req'], $event->{$ignRow['req']});
                            @endphp

                            <div class="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-lg border border-gray-200 bg-gray-50 px-3.5 py-3">
                                <div class="min-w-56">
                                    {{-- An unticked box sends nothing, so a 0 is
                                         queued first and the checkbox overrides it. --}}
                                    <input type="hidden" name="{{ $ignRow['ask'] }}" value="0">
                                    <x-admin.toggle
                                        :name="$ignRow['ask']"
                                        :checked="$asked"
                                        :label="'Ask for ' . $ignRow['label']" />
                                </div>

                                <label for="{{ $ignRow['req'] }}" class="inline-flex items-center gap-2 cursor-pointer">
                                    <input type="hidden" name="{{ $ignRow['req'] }}" value="0">
                                    <input type="checkbox" id="{{ $ignRow['req'] }}" name="{{ $ignRow['req'] }}" value="1"
                                           @checked($required)
                                           @disabled(! $asked)
                                           data-ign-required="{{ $ignRow['ask'] }}"
                                           class="h-4 w-4 rounded border-gray-400 text-blue-600 focus:ring-2 focus:ring-blue-500/40 disabled:opacity-40">
                                    <span @class(['text-sm', $asked ? 'text-gray-700' : 'text-gray-400']) data-ign-required-label>Compulsory</span>
                                </label>

                                <p class="basis-full text-xs text-gray-500">{{ $ignRow['hint'] }}</p>
                            </div>
                        @endforeach
                    </div>

                    <p class="text-xs text-gray-500 mt-3">
                        Applies to both modes. A squad is asked once per player, a solo entry once
                        for that person. Leave all three off for a course or a conference, where
                        there is no game account to give.
                    </p>
                </x-admin.field-row>

                <x-admin.field-row
                    label="Logo"
                    help="One image per entry, uploaded by whoever registers."
                    error="requires_logo">

                    @php
                        $logoAsked = (bool) old('asks_logo', $event->asks_logo);
                        $logoRequired = (bool) old('requires_logo', $event->requires_logo);
                    @endphp

                    <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                        <div class="min-w-56">
                            <input type="hidden" name="asks_logo" value="0">
                            <x-admin.toggle
                                name="asks_logo"
                                :checked="$logoAsked"
                                label="Ask for a logo" />
                        </div>

                        <label for="requires_logo" class="inline-flex items-center gap-2 cursor-pointer">
                            <input type="hidden" name="requires_logo" value="0">
                            <input type="checkbox" id="requires_logo" name="requires_logo" value="1"
                                   @checked($logoRequired)
                                   @disabled(! $logoAsked)
                                   data-ign-required="asks_logo"
                                   class="h-4 w-4 rounded border-gray-400 text-blue-600 focus:ring-2 focus:ring-blue-500/40 disabled:opacity-40">
                            <span @class(['text-sm', $logoAsked ? 'text-gray-700' : 'text-gray-400']) data-ign-required-label>Compulsory</span>
                        </label>
                    </div>

                    <p class="text-xs text-gray-500 mt-2">
                        A squad uploads one crest, not one per player, so in Manager mode this is the
                        manager's job. An individual entry uploads one image for themselves.
                        JPG, PNG, WebP or SVG up to 2 MB.
                    </p>
                </x-admin.field-row>

                <x-admin.field-row label="Registration Opens" help="Leave blank to accept entries immediately." for="registration_opens_at" error="registration_opens_at">
                    <input type="date" id="registration_opens_at" name="registration_opens_at"
                           value="{{ old('registration_opens_at', $event->registration_opens_at?->toDateString()) }}"
                           class="{{ $input }}">
                </x-admin.field-row>

                <x-admin.field-row label="Registration Closes" help="Leave blank to keep entries open until the event ends." for="registration_closes_at" error="registration_closes_at">
                    <input type="date" id="registration_closes_at" name="registration_closes_at"
                           value="{{ old('registration_closes_at', $event->registration_closes_at?->toDateString()) }}"
                           class="{{ $input }}">
                </x-admin.field-row>
            </x-admin.panel>

            {{-- ---------------- What registrants are asked ---------------- --}}
            <x-admin.panel title="Details Collected From Each Person" icon="lock">
                <div class="px-5 py-4">
                    <p class="text-sm text-gray-600 mb-3">
                        Everyone named on a registration fills in the same set of fields. These are
                        fixed for all events, so they are shown here for reference rather than
                        configured per event.
                    </p>

                    {{-- The role is never asked of the visitor: the mode settles
                         it, which keeps a course attendee from being labelled a
                         player. --}}
                    <div class="flex items-start gap-2 rounded-lg bg-blue-50 border border-blue-200 p-3 mb-4">
                        <x-admin.icon name="users" class="w-4 h-4 mt-0.5 shrink-0 text-blue-600" />
                        <p class="text-xs text-blue-800">
                            <span @class(['hidden' => ! $isManagerMode]) data-role-note="manager">
                                Squad mode: the first person is recorded as the <strong>Manager</strong>
                                and everyone they add is recorded as a <strong>Player</strong>.
                            </span>
                            <span @class(['hidden' => $isManagerMode]) data-role-note="individual">
                                Individual mode: the person is recorded as a
                                <strong>Participant</strong>. No manager or player choice is shown,
                                so the form suits a course or conference as well as a match.
                            </span>
                        </p>
                    </div>

                    {{-- Listed separately because these two appear only when the
                         In-Game ID setting above is switched on. --}}
                    <div class="flex items-start gap-2 rounded-lg bg-gray-50 border border-gray-200 p-3 mb-4">
                        <x-admin.icon name="mobile" class="w-4 h-4 mt-0.5 shrink-0 text-gray-500" />
                        <p class="text-xs text-gray-600">
                            With <strong>In-Game ID</strong> switched on, each person is also asked for a
                            <strong>Player ID</strong> and a <strong>Server ID</strong>.
                        </p>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-3 gap-x-6 gap-y-1.5">
                        @foreach ([
                            'Full Name as per I.C.',
                            'Identity Card (IC)',
                            'Date of Birth',
                            'Address 1',
                            'Address 2',
                            'Postcode',
                            'City',
                            'State',
                            'Country',
                            'Telephone Number',
                            'Email',
                            'Gender',
                            'Race',
                            'Emergency Contact',
                        ] as $field)
                            <p class="flex items-center gap-2 text-sm text-gray-700">
                                <span class="w-1.5 h-1.5 rounded-full bg-blue-500 shrink-0" aria-hidden="true"></span>
                                {{ $field }}
                            </p>
                        @endforeach
                    </div>
                </div>
            </x-admin.panel>

            <div class="flex items-center justify-between gap-4 bg-white rounded-lg border border-gray-200 px-5 py-4 mt-5">
                <p class="text-xs text-gray-500">
                    Once saved with an open status, the event appears on the public registration page.
                </p>
                <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm shrink-0">
                    {{ $isCreate ? 'Save Event' : 'Save Changes' }}
                </button>
            </div>
        </x-admin.page-card>
    </form>
@endsection

@push('scripts')
<script>
    (function () {
        const modeSelect = document.getElementById('registration_mode');
        const bounds = document.getElementById('player-bounds');

        // Player limits only apply to squad registration, so the pair of fields
        // is hidden for individual events.
        const managerMode = @json(\App\Models\Event::MODE_MANAGER);

        if (modeSelect) {
            modeSelect.addEventListener('change', function () {
                const isManager = modeSelect.value === managerMode;

                bounds?.classList.toggle('hidden', !isManager);

                // Keep the price wording honest about what the mode charges.
                document.querySelector('[data-fee-basis="manager"]')?.classList.toggle('hidden', !isManager);
                document.querySelector('[data-fee-basis="individual"]')?.classList.toggle('hidden', isManager);

                // And keep the role explanation matching the mode too.
                document.querySelector('[data-role-note="manager"]')?.classList.toggle('hidden', !isManager);
                document.querySelector('[data-role-note="individual"]')?.classList.toggle('hidden', isManager);
            });
        }

        /*
         | Compulsory follows asked.
         |
         | A field nobody is asked for cannot be compulsory, so the box is
         | disabled and cleared when its toggle goes off. Clearing matters as
         | well as disabling: leaving it ticked but greyed would save a pair
         | that reads "not asked, but required" the moment the toggle came
         | back on. The request enforces the same rule, because a disabled
         | box is only a courtesy.
         */
        document.querySelectorAll('[data-ign-required]').forEach(function (box) {
            const toggle = document.querySelector('input[type="checkbox"][name="' + box.dataset.ignRequired + '"]');

            if (!toggle) {
                return;
            }

            const label = box.parentElement?.querySelector('[data-ign-required-label]');

            function sync() {
                box.disabled = !toggle.checked;

                if (box.disabled) {
                    box.checked = false;
                }

                label?.classList.toggle('text-gray-400', box.disabled);
                label?.classList.toggle('text-gray-700', !box.disabled);
            }

            toggle.addEventListener('change', sync);
        });

        // Local preview so the operator sees the poster before saving.
        const posterInput = document.getElementById('poster');
        const preview = document.getElementById('poster-preview');
        const empty = document.getElementById('poster-empty');

        if (posterInput && preview) {
            posterInput.addEventListener('change', function () {
                const file = posterInput.files && posterInput.files[0];

                if (!file) {
                    return;
                }

                preview.src = URL.createObjectURL(file);
                preview.classList.remove('hidden');
                empty?.classList.add('hidden');
            });
        }
    })();
</script>

<script>
    /* ---------------------------------------------------------------------
     | Paid add-ons builder
     |
     | Rows are cloned from <template> blocks with __INDEX__ / __VINDEX__ swapped
     | for a counter. The counter only ever goes up, so removing row 1 and adding
     | another cannot collide with a name still on the page.
     * ------------------------------------------------------------------ */
    (function () {
        const list = document.getElementById('addon-list');
        const emptyState = document.getElementById('addon-empty');
        const addButton = document.getElementById('addon-add');
        const addonTemplate = document.getElementById('addon-template');
        const variantTemplate = document.getElementById('variant-template');

        if (!list || !addButton || !addonTemplate || !variantTemplate) {
            return;
        }

        // Start past the rows already rendered so a new row never reuses an index.
        let addonSeq = list.querySelectorAll('[data-addon-row]').length;

        function renderFrom(template, replacements) {
            let html = template.innerHTML;

            Object.keys(replacements).forEach(function (token) {
                html = html.split(token).join(replacements[token]);
            });

            const holder = document.createElement('div');
            holder.innerHTML = html.trim();

            return holder.firstElementChild;
        }

        // Card numbers are cosmetic, so they are renumbered on every change
        // rather than tied to the field index.
        function renumber() {
            const rows = list.querySelectorAll('[data-addon-row]');

            rows.forEach(function (row, i) {
                const badge = row.querySelector('[data-addon-number]');

                if (badge) {
                    badge.textContent = i + 1;
                }
            });

            emptyState?.classList.toggle('hidden', rows.length > 0);
        }

        function syncVariantChrome(row) {
            const variantList = row.querySelector('[data-variant-list]');

            if (!variantList) {
                return;
            }

            const count = variantList.querySelectorAll('[data-variant-row]').length;

            row.querySelector('[data-variant-empty]')?.classList.toggle('hidden', count > 0);

            // The heading row carries sm:grid, so toggling 'hidden' alone would
            // lose to it. Both classes are managed together.
            const head = row.querySelector('[data-variant-head]');

            if (head) {
                head.classList.toggle('hidden', count === 0);
                head.classList.toggle('sm:grid', count > 0);
            }
        }

        function nextVariantIndex(row) {
            // Read the highest index in use rather than counting rows, so a
            // removal in the middle cannot cause two options to share a name.
            let highest = -1;

            row.querySelectorAll('[data-variant-row] input[name]').forEach(function (input) {
                const match = input.name.match(/\[variants\]\[(\d+)\]/);

                if (match) {
                    highest = Math.max(highest, parseInt(match[1], 10));
                }
            });

            return highest + 1;
        }

        function addonIndexOf(row) {
            const input = row.querySelector('input[name^="addons["]');
            const match = input && input.name.match(/^addons\[(\d+)\]/);

            return match ? match[1] : null;
        }

        addButton.addEventListener('click', function () {
            const row = renderFrom(addonTemplate, { '__INDEX__': addonSeq++ });

            list.appendChild(row);
            renumber();
            syncVariantChrome(row);

            // Straight into the name field, since that is the first thing to type.
            row.querySelector('[data-addon-name]')?.focus();
        });

        list.addEventListener('click', function (event) {
            const removeAddon = event.target.closest('[data-addon-remove]');

            if (removeAddon) {
                const row = removeAddon.closest('[data-addon-row]');
                const sold = row.querySelectorAll('[data-variant-locked]').length > 0;

                // Removing an add-on people have already bought would orphan
                // their order lines, so it is refused here as well as on save.
                if (sold) {
                    window.alert(
                        'This add-on already has orders, so it cannot be removed.\n\n' +
                        'Untick "Offer this add-on" to stop selling it while keeping the records.'
                    );

                    return;
                }

                row.remove();
                renumber();

                return;
            }

            const addVariant = event.target.closest('[data-variant-add]');

            if (addVariant) {
                const row = addVariant.closest('[data-addon-row]');
                const index = addonIndexOf(row);

                if (index === null) {
                    return;
                }

                const variantRow = renderFrom(variantTemplate, {
                    '__INDEX__': index,
                    '__VINDEX__': nextVariantIndex(row),
                });

                row.querySelector('[data-variant-list]').appendChild(variantRow);
                syncVariantChrome(row);

                // A fresh option starts blank, so say straight away that it will
                // charge the add-on price rather than leaving the note empty.
                syncVariantCharges(row);
                variantRow.querySelector('input[type="text"]')?.focus();

                return;
            }

            const removeVariant = event.target.closest('[data-variant-remove]');

            if (removeVariant) {
                if (removeVariant.hasAttribute('data-variant-locked')) {
                    window.alert(
                        'This option has ' + removeVariant.getAttribute('data-variant-locked') +
                        ' order(s), so it cannot be removed.\n\n' +
                        'Set its stock to the number already ordered to stop selling it.'
                    );

                    return;
                }

                const row = removeVariant.closest('[data-addon-row]');
                removeVariant.closest('[data-variant-row]').remove();
                syncVariantChrome(row);
            }
        });

        // Keep the card header showing the add-on's name as it is typed.
        list.addEventListener('input', function (event) {
            if (!event.target.matches('[data-addon-name]')) {
                return;
            }

            const row = event.target.closest('[data-addon-row]');
            const title = row.querySelector('[data-addon-title]');

            if (title) {
                title.textContent = event.target.value.trim() || 'New add-on';
            }
        });

        /*
         | The three states of an add-on, kept consistent as they are clicked.
         |
         | Compulsory wins: there is nothing to opt out of, so "ticked by default"
         | is disabled and cleared, and the reminder goes away with it. Delegated
         | rather than wired per row so a card cloned from the template is covered
         | without repeating the wiring.
         */
        function syncAddonStates(row) {
            const required = row.querySelector('[data-addon-required]');
            const ticked = row.querySelector('[data-addon-ticked]');
            const label = row.querySelector('[data-addon-ticked-label]');
            const reminder = row.querySelector('[data-addon-reminder-wrap]');

            if (!ticked) {
                return;
            }

            const isRequired = !!required?.checked;

            ticked.disabled = isRequired;

            if (isRequired) {
                ticked.checked = false;
            }

            label?.classList.toggle('text-gray-400', isRequired);
            label?.classList.toggle('text-gray-700', !isRequired);

            reminder?.classList.toggle('hidden', isRequired || !ticked.checked);
        }

        list.addEventListener('change', function (event) {
            if (!event.target.matches('[data-addon-required], [data-addon-ticked]')) {
                return;
            }

            syncAddonStates(event.target.closest('[data-addon-row]'));
        });

        /*
         | Print what each option will actually be charged.
         |
         | Blank and zero mean different things here, and telling them apart used to
         | be guesswork: the spinner lands on zero at the first click, and a shirt
         | already covered by the event fee is priced at zero deliberately. Rather
         | than forbidding one of the two, the resolved figure is shown, so the
         | difference is visible while it is being typed.
         */
        function syncVariantCharges(row) {
            const addonPrice = parseFloat(row.querySelector('[data-addon-price]')?.value ?? '');

            row.querySelectorAll('[data-variant-row]').forEach(function (variant) {
                const input = variant.querySelector('[data-variant-price]');
                const note = variant.querySelector('[data-variant-charge]');

                if (!input || !note) {
                    return;
                }

                const base = Number.isFinite(addonPrice) ? addonPrice : 0;
                const extra = input.value.trim() === '' ? 0 : parseFloat(input.value);

                if (!Number.isFinite(extra)) {
                    note.textContent = '';

                    return;
                }

                // Always the figure the buyer pays, so an extra never has to be
                // added up in somebody's head.
                const charged = 'Charges RM ' + (base + extra).toFixed(2);

                note.textContent = extra > 0
                    ? charged + ', RM ' + extra.toFixed(2) + ' extra'
                    : charged;
            });
        }

        // Delegated so options added later, and the add-on price above them, both
        // keep the notes in step.
        list.addEventListener('input', function (event) {
            if (!event.target.matches('[data-variant-price], [data-addon-price]')) {
                return;
            }

            syncVariantCharges(event.target.closest('[data-addon-row]'));
        });

        // A row rendered from old() may already have options, so the chrome has
        // to be brought in line on load rather than only on change.
        list.querySelectorAll('[data-addon-row]').forEach(function (row) {
            syncVariantChrome(row);
            syncAddonStates(row);
            syncVariantCharges(row);
        });
        renumber();
    })();
</script>
@endpush
