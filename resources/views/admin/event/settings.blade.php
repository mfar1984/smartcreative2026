@extends('layouts.admin')

@section('title', 'Event Settings')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Event</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.event.settings') }}" class="hover:text-gray-700 transition">Settings</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $definition['label'] }}</span>
@endsection

@section('content')
    @php
        use App\Support\EventTemplates;

        $input = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';

        // Only email carries a subject line. Passed in rather than derived here
        // so the view does not have to know which channels those are.
        $isEmail = $hasSubject;
    @endphp

    <x-admin.settings-shell
        title="Event Settings"
        description="Wording and behaviour shared by every event, kept in one place rather than repeated per event."
        :tabs="$tabs"
        :active-tab="$activeTab"
        route="admin.event.settings">

        <x-admin.section-intro
            :title="$definition['label']"
            :description="$definition['description']"
            :icon="$definition['icon']"
            :accent="$accent" />

        @if (session('status'))
            <div role="status" class="flex items-start gap-3 bg-green-50 border border-green-200 rounded-lg p-4 mb-5">
                <svg class="w-5 h-5 shrink-0 text-green-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-sm font-semibold text-green-800">{{ session('status') }}</p>
            </div>
        @endif

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

        {{-- Whether anything can deliver on this channel. Said before the editor
             so nobody writes four messages believing they are being sent. --}}
        <div @class([
            'flex items-start gap-3 rounded-lg border p-4 mb-5',
            'bg-amber-50 border-amber-200' => ! $delivery['wired'],
            'bg-green-50 border-green-200' => $delivery['wired'],
        ])>
            <svg @class(['w-5 h-5 shrink-0 mt-0.5', 'text-amber-600' => ! $delivery['wired'], 'text-green-600' => $delivery['wired']])
                 fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                @if ($delivery['wired'])
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                @else
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                @endif
            </svg>
            <div @class(['text-sm', 'text-amber-800' => ! $delivery['wired'], 'text-green-800' => $delivery['wired']])>
                <p class="font-semibold mb-1">
                    {{ $delivery['wired'] ? 'Sending' : 'Not sending yet' }}
                </p>
                <p>{{ $delivery['note'] }}</p>
                <p class="text-xs mt-2">
                    Transport: {{ $delivery['summary'] }}
                    <a href="{{ $delivery['settingsRoute'] }}" class="underline font-semibold">Open the connection settings</a>.
                </p>
            </div>
        </div>

        <form action="{{ route('admin.event.settings.update', ['tab' => $activeTab]) }}" method="POST">
            @csrf
            @method('PUT')

            @foreach ($templates as $key => $template)
                @php
                    $formKey = EventTemplates::formKey($key);
                    $model = $template['model'];
                    $field = fn (string $name) => "templates[{$formKey}][{$name}]";
                    $old = fn (string $name, $fallback) => old("templates.{$formKey}.{$name}", $fallback);
                @endphp

                <x-admin.panel :title="$template['definition']['label']" :icon="$definition['icon']">
                    <div class="px-5 py-4 border-b border-gray-100 bg-gray-50/60">
                        <p class="text-sm text-gray-700">{{ $template['definition']['description'] }}</p>
                        <p class="text-xs text-gray-500 mt-1">
                            <span class="font-semibold">Goes to:</span> {{ $template['definition']['audience'] }}
                        </p>
                    </div>

                    <x-admin.field-row
                        label="Send this message"
                        help="Switch off to keep the wording without sending it."
                        :error="'templates.' . $formKey . '.is_active'">

                        {{-- An unticked box sends nothing, so a 0 is queued first
                             and the checkbox overrides it when ticked. --}}
                        <input type="hidden" name="{{ $field('is_active') }}" value="0">
                        <x-admin.toggle
                            :name="$field('is_active')"
                            :id="'active-' . $formKey"
                            :checked="(bool) $old('is_active', $model?->is_active ?? true)"
                            label="Active" />
                    </x-admin.field-row>

                    @if ($isEmail)
                        <x-admin.field-row
                            label="Subject"
                            help="Placeholders work here too."
                            :for="'subject-' . $formKey"
                            :required="true"
                            :error="'templates.' . $formKey . '.subject'">

                            <input type="text" id="subject-{{ $formKey }}" name="{{ $field('subject') }}"
                                   maxlength="200" required
                                   value="{{ $old('subject', $model?->subject) }}"
                                   class="{{ $input }}">
                        </x-admin.field-row>
                    @endif

                    <x-admin.field-row
                        label="Message"
                        :help="$isEmail ? 'Plain text. Line breaks are kept, and the message is wrapped in the site email layout when it goes out.' : 'Plain text. Keep it under 160 characters to stay within one SMS segment.'"
                        :for="'body-' . $formKey"
                        :required="true"
                        :error="'templates.' . $formKey . '.body'">

                        <textarea id="body-{{ $formKey }}" name="{{ $field('body') }}"
                                  rows="{{ $isEmail ? 16 : 4 }}" maxlength="10000" required
                                  data-template-body
                                  class="{{ $input }} resize-y font-mono text-xs leading-relaxed">{{ $old('body', $model?->body) }}</textarea>

                        @unless ($isEmail)
                            <p class="text-xs text-gray-500 mt-1.5">
                                <span data-sms-count="{{ $formKey }}">0</span> characters
                                &middot; <span data-sms-segments="{{ $formKey }}">0</span> segment(s)
                            </p>
                        @endunless
                    </x-admin.field-row>

                    {{-- Only the placeholders this message is allowed to use. The
                         player notice is not offered the payment link, because
                         that link can settle the invoice. --}}
                    <div class="px-5 py-4 border-t border-gray-100">
                        <p class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-2">
                            Placeholders you can use here
                        </p>

                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($template['placeholders'] as $placeholder => $meaning)
                                @php $token = EventTemplates::token($placeholder); @endphp

                                <button type="button"
                                        data-insert-placeholder="{{ $token }}"
                                        data-target="body-{{ $formKey }}"
                                        title="{{ $meaning }}"
                                        class="rounded-md border border-gray-300 bg-white px-2 py-1 font-mono text-[11px] text-gray-700 hover:border-blue-400 hover:bg-blue-50 hover:text-blue-700 transition">
                                    {{ $token }}
                                </button>
                            @endforeach
                        </div>

                        <p class="text-xs text-gray-500 mt-2">
                            Click one to insert it where the cursor is. Hover to see what it means.
                            Anything the system does not recognise is left in the message as typed,
                            so a misspelt placeholder shows up rather than vanishing.
                        </p>

                        @if ($model)
                            <a href="{{ route('admin.event.settings.preview', ['tab' => $activeTab, 'key' => $key]) }}"
                               target="_blank" rel="noopener"
                               class="inline-flex items-center gap-1.5 mt-3 text-xs font-semibold text-blue-600 hover:underline">
                                <x-admin.icon name="search" class="w-3.5 h-3.5" />
                                Preview the saved version with sample data
                            </a>
                        @else
                            <p class="text-xs text-gray-400 mt-3">Save this template to enable preview.</p>
                        @endif
                    </div>
                </x-admin.panel>
            @endforeach

            <div class="flex items-center justify-between gap-4 bg-white rounded-lg border border-gray-200 px-5 py-4 mt-5">
                @if ($canUpdate)
                    <p class="text-xs text-gray-500">
                        Preview reflects the last saved version, so save before previewing a change.
                    </p>
                    <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm shrink-0">
                        Save Templates
                    </button>
                @else
                    <p class="text-xs text-gray-500">Your role can view these templates but not change them.</p>
                @endif
            </div>
        </form>
    </x-admin.settings-shell>
@endsection

@push('scripts')
<script>
    (function () {
        // Insert a placeholder at the cursor rather than appending, so it lands
        // in the sentence being written.
        document.querySelectorAll('[data-insert-placeholder]').forEach(function (button) {
            button.addEventListener('click', function () {
                const target = document.getElementById(button.dataset.target);

                if (!target) {
                    return;
                }

                const token = button.dataset.insertPlaceholder;
                const start = target.selectionStart ?? target.value.length;
                const end = target.selectionEnd ?? target.value.length;

                target.value = target.value.slice(0, start) + token + target.value.slice(end);

                // Leave the caret after what was inserted, ready to keep typing.
                const caret = start + token.length;
                target.focus();
                target.setSelectionRange(caret, caret);

                // Fires the SMS counter, which listens for input.
                target.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });

        // SMS length. One GSM segment is 160 characters; past that a message is
        // billed as several, which is worth seeing while writing.
        document.querySelectorAll('[data-sms-count]').forEach(function (counter) {
            const formKey = counter.dataset.smsCount;
            const body = document.getElementById('body-' + formKey);
            const segments = document.querySelector('[data-sms-segments="' + formKey + '"]');

            if (!body) {
                return;
            }

            function update() {
                const length = body.value.length;
                counter.textContent = String(length);

                if (segments) {
                    segments.textContent = String(length === 0 ? 0 : Math.ceil(length / 160));
                }

                // Placeholders expand when sent, so the count is a floor rather
                // than a promise. Amber past one segment is the useful signal.
                counter.classList.toggle('text-amber-700', length > 160);
                counter.classList.toggle('font-semibold', length > 160);
            }

            body.addEventListener('input', update);
            update();
        });
    })();
</script>
@endpush
