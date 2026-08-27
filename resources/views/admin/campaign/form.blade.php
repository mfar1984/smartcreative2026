@extends('layouts.admin')

@section('title', $campaign->exists ? 'Edit Campaign' : 'New Campaign')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.campaigns.index') }}" class="hover:text-gray-700 transition">Campaign</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $campaign->exists ? $campaign->name : 'New' }}</span>
@endsection

@section('content')
    @php
        use App\Support\CampaignAudience;
        use App\Support\EventTemplates;

        $input = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
        $channel = old('channel', $campaign->channel ?: EventTemplates::CHANNEL_EMAIL);
    @endphp

    <x-admin.page-card
        :title="$campaign->exists ? 'Edit Campaign' : 'New Campaign'"
        description="Send Now writes the campaign and sends it in one press. Save Draft keeps it for later."
        :back="route('admin.campaigns.index')">

        @if ($errors->any())
            <div role="alert" class="bg-red-50 border border-red-200 rounded-lg p-4 mb-5">
                <p class="text-sm font-bold text-red-900 mb-1">Nothing was saved</p>
                <ul class="text-sm text-red-800 space-y-0.5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @unless ($delivery['ready'])
            <div role="alert" class="flex items-start gap-3 bg-red-50 border border-red-200 rounded-lg p-4 mb-5">
                <x-admin.icon name="lock" class="w-5 h-5 mt-0.5 shrink-0 text-red-600" />
                <p class="text-sm text-red-800">
                    {{ $delivery['summary'] }}
                    <a href="{{ $delivery['settingsRoute'] }}" class="underline font-semibold">Open the settings</a>.
                </p>
            </div>
        @endunless

        <form action="{{ $campaign->exists ? route('admin.campaigns.update', $campaign) : route('admin.campaigns.store') }}"
              method="POST">
            @csrf
            @if ($campaign->exists) @method('PUT') @endif

            <x-admin.panel title="What And Who" icon="clipboard">
                <x-admin.field-row label="Name" help="For your own reference. Recipients never see it." for="name" :required="true" error="name">
                    <input type="text" id="name" name="name" required maxlength="190"
                           value="{{ old('name', $campaign->name) }}"
                           placeholder="e.g. September tournament announcement"
                           class="{{ $input }}">
                </x-admin.field-row>

                <x-admin.field-row label="Channel" help="One campaign is one channel. The wording and the report differ too much to share." for="channel" :required="true" error="channel">
                    <select id="channel" name="channel" class="{{ $input }} bg-white" data-channel>
                        @foreach ($channels as $value => $label)
                            <option value="{{ $value }}" @selected($channel === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-admin.field-row>

                <x-admin.field-row label="Audience" help="Narrows the list below. You then choose from it." for="audience_type" :required="true" error="audience_type">
                    <select id="audience_type" name="audience_type" class="{{ $input }} bg-white" data-audience>
                        @foreach ($segments as $value => $definition)
                            <option value="{{ $value }}" @selected(old('audience_type', $campaign->audience_type) === $value)>
                                {{ $definition['label'] }}
                            </option>
                        @endforeach
                    </select>

                    <p class="text-xs text-gray-500 mt-1.5" data-audience-help>
                        {{ $segments[old('audience_type', $campaign->audience_type)]['description'] ?? '' }}
                    </p>
                </x-admin.field-row>

                {{-- Only meaningful for the one-event segment, so it is hidden
                     rather than shown empty and ignored. --}}
                <div data-event-row @class(['hidden' => old('audience_type', $campaign->audience_type) !== CampaignAudience::EVENT])>
                    <x-admin.field-row label="Which event" for="audience_event_id" error="audience_event_id">
                        <select id="audience_event_id" name="audience_event_id" class="{{ $input }} bg-white">
                            <option value="">Choose an event</option>
                            @foreach ($events as $id => $title)
                                <option value="{{ $id }}" @selected((string) old('audience_event_id', $campaign->audience_event_id) === (string) $id)>{{ $title }}</option>
                            @endforeach
                        </select>
                    </x-admin.field-row>
                </div>
            </x-admin.panel>

            {{-- Who it actually goes to.

                 Named people with a tick against each, rather than a rule and a
                 count, because an operator about to send something irreversible
                 should be able to see the list they are sending it to.

                 People who unsubscribed, bounced or reported spam are not in this
                 list at all. That is not a display choice: they cannot be ticked
                 because they may not be sent to. --}}
            <x-admin.panel title="Who Will Receive This" icon="users">
                <div class="px-5 py-4">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                        <label class="inline-flex items-center gap-2 cursor-pointer select-none">
                            <input type="checkbox" data-pick-all
                                   class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                            <span class="text-sm font-semibold text-gray-800">Select all</span>
                        </label>

                        <div class="flex flex-wrap items-center gap-3">
                            <div class="relative">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                                    <x-admin.icon name="search" class="w-4 h-4" />
                                </span>
                                <label for="pick-search" class="sr-only">Filter this list</label>
                                <input type="search" id="pick-search" data-pick-search placeholder="Filter by name or address..."
                                       class="w-64 rounded-lg border border-gray-300 pl-9 pr-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                            </div>

                            <span class="text-sm font-semibold text-gray-700 tabular-nums" data-pick-count>0 chosen</span>
                        </div>
                    </div>

                    @error('contact_ids')
                        <p class="text-sm text-red-600 mb-3">{{ $message }}</p>
                    @enderror

                    <div class="rounded-lg border border-gray-200 overflow-hidden">
                        <div class="max-h-96 overflow-y-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 text-left sticky top-0">
                                    <tr>
                                        <th scope="col" class="w-10 px-4 py-2.5"><span class="sr-only">Choose</span></th>
                                        <th scope="col" class="px-4 py-2.5 text-xs font-bold uppercase tracking-wide text-gray-500">Name</th>
                                        <th scope="col" data-address-head class="px-4 py-2.5 text-xs font-bold uppercase tracking-wide text-gray-500">
                                            {{ $channel === EventTemplates::CHANNEL_SMS ? 'Number' : 'Email' }}
                                        </th>
                                        <th scope="col" class="px-4 py-2.5 text-xs font-bold uppercase tracking-wide text-gray-500">Event</th>
                                        <th scope="col" class="px-4 py-2.5 text-xs font-bold uppercase tracking-wide text-gray-500">Consent</th>
                                    </tr>
                                </thead>
                                <tbody data-pick-body class="divide-y divide-gray-100"></tbody>
                            </table>

                            <p data-pick-empty class="hidden px-4 py-10 text-center text-sm text-gray-500"></p>
                        </div>
                    </div>

                    <p class="text-xs text-gray-500 mt-2" data-pick-note></p>
                </div>
            </x-admin.panel>

            <x-admin.panel title="The Message" icon="mail">
                @if ($templates->isNotEmpty())
                    <x-admin.field-row label="Start from a template" help="Copies the wording into the boxes below. Editing here does not change the template." for="campaign_template_id">
                        <select id="campaign_template_id" name="campaign_template_id" class="{{ $input }} bg-white" data-template-picker>
                            <option value="">Write it from scratch</option>
                            @foreach ($templates as $template)
                                <option value="{{ $template->id }}"
                                        data-subject="{{ $template->subject }}"
                                        data-body="{{ $template->body }}"
                                        @selected((string) old('campaign_template_id', $campaign->campaign_template_id) === (string) $template->id)>
                                    {{ $template->name }}
                                </option>
                            @endforeach
                        </select>
                    </x-admin.field-row>
                @endif

                <div data-subject-row @class(['hidden' => $channel !== EventTemplates::CHANNEL_EMAIL])>
                    <x-admin.field-row label="Subject" help="Placeholders work here too." for="subject" error="subject">
                        <input type="text" id="subject" name="subject" maxlength="200"
                               value="{{ old('subject', $campaign->subject) }}"
                               class="{{ $input }}">
                    </x-admin.field-row>
                </div>

                <x-admin.field-row label="Message" for="body" :required="true" error="body">
                    <textarea id="body" name="body" rows="12" required
                              class="{{ $input }} resize-y font-mono text-xs"
                              data-body>{{ old('body', $campaign->body) }}</textarea>

                    <div class="flex flex-wrap items-center justify-between gap-2 mt-1.5">
                        <p class="text-xs text-gray-500">
                            Plain text. Any web address becomes a tracked link automatically.
                        </p>
                        <p class="text-xs text-gray-500" data-length>
                            <span data-count>0</span> characters<span data-segments></span>
                        </p>
                    </div>
                </x-admin.field-row>

                <x-admin.field-row label="Placeholders" help="Typed into the subject or the message.">
                    <div class="md:pt-1 space-y-1">
                        @foreach ($placeholders as $key => $description)
                            <p class="text-xs text-gray-600">
                                <code class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-gray-800">{{ \App\Services\Campaign\CampaignRenderer::token($key) }}</code>
                                <span class="ml-1.5">{{ $description }}</span>
                            </p>
                        @endforeach

                        <p class="text-xs text-gray-500 pt-1.5">
                            An unsubscribe link is added to every email whether or not you
                            include one, because a marketing message without one gets the whole
                            domain reported as spam.
                        </p>
                    </div>
                </x-admin.field-row>
            </x-admin.panel>

            {{-- Two intents, one form. Send Now is the ordinary case and so it is the
                 primary button; Save Draft is there for wording that is not finished.
                 Which one was pressed travels in the intent field. --}}
            <div class="flex flex-wrap items-center justify-between gap-4 bg-white rounded-lg border border-gray-200 px-5 py-4 mt-5">
                <p class="text-xs text-gray-500 max-w-md">
                    @if ($canSend)
                        Send Now cannot be recalled once the messages leave. Save Draft changes
                        nothing for anybody and lets you send from the next screen.
                    @else
                        Your account may write campaigns but not send them. This saves as a draft
                        for somebody with permission to send.
                    @endif
                </p>

                <div class="flex items-center gap-3 shrink-0">
                    <button type="submit" name="intent" value="draft"
                            class="rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                        {{ $campaign->exists ? 'Save Changes' : 'Save Draft' }}
                    </button>

                    @if ($canSend)
                        {{-- Transparent border only so this matches the height of Save
                             Draft, which has a visible one. --}}
                        <button type="submit" name="intent" value="send" data-send
                                class="inline-flex items-center gap-2 border border-blue-600 bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                            <x-admin.icon name="send" class="w-4 h-4" />
                            Send Now
                        </button>
                    @endif
                </div>
            </div>
        </form>
    </x-admin.page-card>
@endsection

@push('scripts')
<script>
    (function () {
        const channel = document.querySelector('[data-channel]');
        const subjectRow = document.querySelector('[data-subject-row]');
        const audience = document.querySelector('[data-audience]');
        const audienceHelp = document.querySelector('[data-audience-help]');
        const eventRow = document.querySelector('[data-event-row]');
        const body = document.querySelector('[data-body]');
        const count = document.querySelector('[data-count]');
        const segments = document.querySelector('[data-segments]');
        const picker = document.querySelector('[data-template-picker]');
        const eventSelect = document.getElementById('audience_event_id');

        const pickBody = document.querySelector('[data-pick-body]');
        const pickAll = document.querySelector('[data-pick-all]');
        const pickCount = document.querySelector('[data-pick-count]');
        const pickNote = document.querySelector('[data-pick-note]');
        const pickEmpty = document.querySelector('[data-pick-empty]');
        const pickSearch = document.querySelector('[data-pick-search]');
        const addressHead = document.querySelector('[data-address-head]');

        const descriptions = @json(collect($segments)->map(fn ($s) => $s['description']));
        const recipientsUrl = @json(route('admin.campaigns.recipients'));

        // Ticked when the page opened: on a draft being edited, the choice made last
        // time. Kept as strings so the comparison against a value attribute holds.
        let chosen = new Set(@json(collect($picked)->map(fn ($id) => (string) $id)->values()));

        function syncChannel() {
            const isEmail = channel.value === 'email';
            subjectRow?.classList.toggle('hidden', !isEmail);
            document.getElementById('subject')?.toggleAttribute('disabled', !isEmail);

            if (addressHead) {
                addressHead.textContent = isEmail ? 'Email' : 'Number';
            }

            syncLength();
        }

        // The cost of an SMS is per segment, not per message, so a body that runs
        // three characters over 160 doubles the bill for the whole campaign.
        function syncLength() {
            if (!body || !count) {
                return;
            }

            const length = body.value.length;
            count.textContent = length;

            if (channel.value === 'sms') {
                const parts = length <= 160 ? 1 : Math.ceil(length / 153);
                segments.textContent = ' · ' + parts + (parts === 1 ? ' SMS segment' : ' SMS segments, billed per segment');
                segments.className = parts > 1 ? 'text-amber-700 font-semibold' : '';
            } else {
                segments.textContent = '';
                segments.className = '';
            }
        }

        function syncAudience() {
            eventRow?.classList.toggle('hidden', audience.value !== 'event');

            if (audienceHelp) {
                audienceHelp.textContent = descriptions[audience.value] || '';
            }
        }

        /* -----------------------------------------------------------------
         | The recipient picker
         * -------------------------------------------------------------- */

        function escapeHtml(value) {
            const div = document.createElement('div');
            div.textContent = value === null || value === undefined ? '' : String(value);

            return div.innerHTML;
        }

        function renderRows(contacts) {
            if (contacts.length === 0) {
                pickBody.innerHTML = '';

                return;
            }

            pickBody.innerHTML = contacts.map(function (c) {
                const ticked = chosen.has(String(c.id)) ? ' checked' : '';
                const consent = c.consented
                    ? '<span class="rounded bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800">Agreed</span>'
                    : '<span class="rounded bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600">Not on record</span>';

                return '<tr class="hover:bg-blue-50/40" data-pick-row'
                    + ' data-haystack="' + escapeHtml(((c.name || '') + ' ' + (c.address || '')).toLowerCase()) + '">'
                    + '<td class="px-4 py-2.5"><input type="checkbox" name="contact_ids[]" value="' + escapeHtml(c.id) + '"'
                    + ' data-pick class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40"' + ticked + '></td>'
                    + '<td class="px-4 py-2.5 text-gray-900">' + escapeHtml(c.name) + '</td>'
                    + '<td class="px-4 py-2.5 text-gray-600 break-all">' + escapeHtml(c.address) + '</td>'
                    + '<td class="px-4 py-2.5 text-gray-500">' + escapeHtml(c.event || '—') + '</td>'
                    + '<td class="px-4 py-2.5">' + consent + '</td>'
                    + '</tr>';
            }).join('');
        }

        function syncCount() {
            const boxes = Array.from(pickBody.querySelectorAll('[data-pick]'));
            const ticked = boxes.filter(function (b) { return b.checked; });

            pickCount.textContent = ticked.length + (ticked.length === 1 ? ' chosen' : ' chosen');

            // Reflects the rows on screen. Indeterminate rather than false when only
            // some are ticked, so the box never claims a state it is not in.
            if (pickAll) {
                pickAll.checked = boxes.length > 0 && ticked.length === boxes.length;
                pickAll.indeterminate = ticked.length > 0 && ticked.length < boxes.length;
            }
        }

        function loadRecipients() {
            if (!pickBody) {
                return;
            }

            const params = new URLSearchParams({
                channel: channel.value,
                audience_type: audience.value,
            });

            if (audience.value === 'event' && eventSelect?.value) {
                params.set('audience_event_id', eventSelect.value);
            }

            pickBody.innerHTML = '';
            pickEmpty.classList.remove('hidden');
            pickEmpty.textContent = 'Loading...';
            pickNote.textContent = '';

            fetch(recipientsUrl + '?' + params.toString(), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('The list could not be loaded.');
                    }

                    return response.json();
                })
                .then(function (data) {
                    renderRows(data.contacts);

                    const empty = data.contacts.length === 0;
                    pickEmpty.classList.toggle('hidden', !empty);

                    if (empty) {
                        pickEmpty.textContent = data.note
                            || 'Nobody in this audience has ' + (channel.value === 'sms' ? 'a usable number' : 'an email address') + '.';
                    }

                    pickNote.textContent = data.note && !empty ? data.note : '';
                    applyFilter();
                    syncCount();
                })
                .catch(function (error) {
                    pickEmpty.classList.remove('hidden');
                    pickEmpty.textContent = error.message;
                    syncCount();
                });
        }

        // Filters the rows already loaded rather than asking the server again, so
        // ticks survive typing in the box.
        function applyFilter() {
            const needle = (pickSearch?.value || '').trim().toLowerCase();

            pickBody.querySelectorAll('[data-pick-row]').forEach(function (row) {
                row.classList.toggle('hidden', needle !== '' && !row.dataset.haystack.includes(needle));
            });
        }

        channel?.addEventListener('change', syncChannel);
        audience?.addEventListener('change', function () {
            syncAudience();
            loadRecipients();
        });

        body?.addEventListener('input', syncLength);

        // Changing channel changes both the address column and who is addressable,
        // so the list is fetched again rather than re-labelled.
        channel?.addEventListener('change', loadRecipients);
        eventSelect?.addEventListener('change', loadRecipients);
        pickSearch?.addEventListener('input', applyFilter);

        // Ticks are remembered across a reload of the list, so switching audience to
        // check something and switching back does not undo the work.
        pickBody?.addEventListener('change', function (event) {
            if (!event.target.matches('[data-pick]')) {
                return;
            }

            event.target.checked ? chosen.add(event.target.value) : chosen.delete(event.target.value);
            syncCount();
        });

        // Applies to the rows on screen, which is what the operator can see. With a
        // filter typed in, "select all" means all of the matches.
        pickAll?.addEventListener('change', function () {
            pickBody.querySelectorAll('[data-pick-row]').forEach(function (row) {
                if (row.classList.contains('hidden')) {
                    return;
                }

                const box = row.querySelector('[data-pick]');
                box.checked = pickAll.checked;
                box.checked ? chosen.add(box.value) : chosen.delete(box.value);
            });

            syncCount();
        });

        /*
         | Confirm only when Send Now was the button pressed.
         |
         | Hung off submit rather than off the button's click so the browser's own
         | required-field check runs first: asking somebody to confirm a send and then
         | refusing it over an empty name field would be the wrong order. The submit
         | event carries which button caused it.
         */
        document.querySelector('[data-send]')?.form?.addEventListener('submit', function (event) {
            if (event.submitter?.dataset.send === undefined) {
                return;
            }

            const picked = pickBody.querySelectorAll('[data-pick]:checked').length;

            if (picked === 0) {
                event.preventDefault();
                alert('Choose at least one person from the list before sending.');

                return;
            }

            const isEmail = channel.value === 'email';
            const message = 'Send this ' + (isEmail ? 'email' : 'text message') + ' now to '
                + picked + (picked === 1 ? ' person' : ' people') + '?'
                + (isEmail ? '' : '\n\nSMS is billed per segment, so this costs money.')
                + '\n\nNothing can be recalled once it has left.';

            if (!confirm(message)) {
                event.preventDefault();
            }
        });

        picker?.addEventListener('change', function () {
            const option = picker.options[picker.selectedIndex];

            if (!option.value) {
                return;
            }

            // Only fills empty boxes, so choosing a template by accident cannot
            // wipe out something already written.
            const subject = document.getElementById('subject');

            if (subject && !subject.value) {
                subject.value = option.dataset.subject || '';
            }

            if (body && !body.value) {
                body.value = option.dataset.body || '';
                syncLength();
            }
        });

        syncChannel();
        syncAudience();
        syncLength();
        loadRecipients();
    })();
</script>
@endpush
