@extends('layouts.admin')

@section('title', 'Integration')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Settings</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Integration</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>{{ $definition['label'] }}</span>
@endsection

@section('content')
    <x-admin.settings-shell
        title="Integration"
        description="Configure third party service integrations for the Smart Digital Creative system."
        :tabs="$tabs"
        :active-tab="$activeTab"
        route="admin.settings.integration">

        <x-admin.section-intro
            :title="$definition['intro']['title']"
            :description="$definition['intro']['description']"
            :icon="$definition['intro']['icon']"
            :accent="$definition['intro']['accent']" />

        <form action="{{ route('admin.settings.integration.update', ['tab' => $activeTab]) }}" method="POST">
            @csrf
            @method('PUT')

            @foreach ($panels as $panelTitle => $panel)
                @php
                    // A panel tied to a gateway is rendered but hidden unless
                    // that gateway is the selected one. Keeping them all in the
                    // DOM means switching provider needs no page reload, and the
                    // server ignores fields that are not for the chosen gateway.
                    $panelProvider = $panel['provider'] ?? null;
                    $isHiddenPanel = $panelProvider !== null && $panelProvider !== ($selectedProvider ?? null);
                @endphp

                {{-- The gap lives on this wrapper, not on the panel inside it.
                     x-admin.panel spaces itself with `mb-5 last:mb-0`, which works
                     when panels are siblings but not here: each panel is the only
                     child of its own wrapper, so `last:` matched every one of them
                     and cancelled every gap.

                     Kept on the element rather than as `space-y` on the form,
                     because a hidden provider panel must take up no space at all,
                     and a parent driven gap would leave one behind. --}}
                {{-- One class attribute, not two. A literal class="mb-5" beside an
                     @class directive emits the attribute twice, and a browser keeps
                     the first and discards the rest, so the `hidden` never applied
                     and every gateway's credentials showed at once. --}}
                <div @class(['mb-5', 'hidden' => $isHiddenPanel])
                     @if ($panelProvider) data-provider-panel="{{ $panelProvider }}" @endif>
                    <x-admin.panel :title="$panelTitle" :icon="$panel['icon']">
                        @foreach ($panel['fields'] as $key)
                            <x-admin.schema-field
                                :name="$key"
                                :field="$definition['fields'][$key]"
                                :value="$values[$key] ?? null"
                                :has-secret="$secretsPresent[$key] ?? false"
                                :can-update="$canUpdate" />
                        @endforeach
                    </x-admin.panel>
                </div>
            @endforeach

            {{-- What to paste into the gateway portal. Read only, because these
                 URLs are decided by this application's routes. --}}
            @if ($callbackUrls)
                <div @class(['mb-5', 'hidden' => ($selectedProvider ?? null) !== 'chip'])
                     data-provider-panel="chip">
                    <x-admin.panel title="Register These In CHIP" icon="globe">
                        @foreach ($callbackUrls as $item)
                            <x-admin.field-row :label="$item['label']" :help="$item['help']">
                                <div class="flex items-center gap-2">
                                    <input type="text" readonly value="{{ $item['url'] }}"
                                           data-copy-source
                                           class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3.5 py-2.5 text-sm text-gray-700 font-mono">
                                    <button type="button" data-copy-button
                                            class="shrink-0 rounded-lg border border-gray-300 px-3 py-2.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 transition">
                                        Copy
                                    </button>
                                </div>
                            </x-admin.field-row>
                        @endforeach

                        <x-admin.field-row
                            label="Events To Subscribe"
                            help="Tick at least these in the CHIP webhook screen. Extra events are acknowledged and ignored.">
                            <div class="flex flex-wrap gap-1.5 md:pt-1.5">
                                @foreach ($chipEvents as $event)
                                    <code class="rounded bg-gray-100 px-2 py-1 text-xs text-gray-700">{{ $event }}</code>
                                @endforeach
                            </div>
                        </x-admin.field-row>

                        <x-admin.field-row label="Signature Check">
                            <div class="md:pt-1.5">
                                @if ($webhooksVerifiable)
                                    <x-admin.badge tone="green" :dot="true">Active</x-admin.badge>
                                    <p class="text-xs text-gray-500 mt-1.5">
                                        Callbacks are verified against the stored public key before they
                                        are acted on.
                                    </p>
                                @else
                                    <x-admin.badge tone="amber" :dot="true">Not possible yet</x-admin.badge>
                                    <p class="text-xs text-gray-500 mt-1.5">
                                        Until the Webhook Public Key above is filled in, incoming
                                        callbacks cannot be proven to come from CHIP and are refused.
                                    </p>
                                @endif
                            </div>
                        </x-admin.field-row>
                    </x-admin.panel>
                </div>
            @endif

            {{-- What the application is actually running on. Only meaningful for
                 mail, where the running config comes from .env. --}}
            @if ($effectiveMail)
                <x-admin.panel title="Currently Active" icon="activity">
                    @foreach ($effectiveMail as $key => $value)
                        <x-admin.field-row :label="$key">
                            <p class="text-sm text-gray-900 md:pt-2.5 break-all">{{ $value ?: '—' }}</p>
                        </x-admin.field-row>
                    @endforeach

                    <div class="px-5 py-4">
                        <div role="note" class="flex items-start gap-3 bg-blue-50 border border-blue-200 rounded-lg p-4">
                            <svg class="w-5 h-5 shrink-0 text-blue-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <p class="text-sm text-blue-800">
                                This is what mail goes out as. The values saved above take
                                precedence over <code class="font-mono text-xs">.env</code>; anything
                                left blank here falls back to it, so a half filled profile cannot
                                stop mail from sending.
                            </p>
                        </div>
                    </div>
                </x-admin.panel>
            @endif

            <div class="flex items-center justify-between gap-4 bg-white rounded-lg border border-gray-200 px-5 py-4 mt-5">
                @if ($canUpdate)
                    <p class="text-xs text-gray-500">Secrets are encrypted before they are stored.</p>
                    <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm shrink-0">
                        Save Settings
                    </button>
                @else
                    <p class="text-xs text-gray-500">Your role can view these settings but not change them.</p>
                @endif
            </div>
        </form>

        {{-- Sits outside the settings form, because a form cannot be nested
             inside another one. --}}
        @if ($activeTab === 'email' && $canUpdate)
            <div class="mt-5">
                <x-admin.panel title="Send a Test Email" icon="send">
                    <div class="px-5 py-4">
                        <p class="text-sm text-gray-600 mb-4">
                            Sends one message using the settings above, so you can confirm they
                            reach a real inbox. Save first if you have just changed anything.
                        </p>

                        @if (session('test_email_success'))
                            <div role="status" class="flex items-start gap-3 bg-green-50 border border-green-200 rounded-lg p-4 mb-4">
                                <svg class="w-5 h-5 shrink-0 text-green-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <div class="text-sm text-green-800">
                                    <p class="font-semibold mb-0.5">Sent</p>
                                    <p>{{ session('test_email_success') }}</p>
                                    <p class="text-xs mt-1.5">
                                        The mail server accepted it. If it does not arrive, check the
                                        spam folder, then the server's own delivery logs; from here on
                                        it is out of this application's hands.
                                    </p>
                                </div>
                            </div>
                        @endif

                        @if (session('test_email_error'))
                            {{-- The transport's own words. A tidied up message would
                                 hide the one detail that makes this diagnosable. --}}
                            <div role="alert" class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
                                <div class="flex items-start gap-3">
                                    <svg class="w-5 h-5 shrink-0 text-red-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                    </svg>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-red-900 mb-1">Could not send</p>
                                        <pre class="text-xs text-red-800 whitespace-pre-wrap break-words font-mono bg-red-100/60 rounded p-2">{{ session('test_email_error') }}</pre>
                                        <p class="text-xs text-red-700 mt-2">
                                            That message comes from the mail server. Nothing was delivered.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        @endif

                        <form action="{{ route('admin.settings.integration.email.test') }}" method="POST"
                              class="flex flex-wrap items-start gap-3">
                            @csrf

                            <div class="flex-1 min-w-64">
                                <label for="test_recipient" class="block text-xs font-semibold text-gray-700 mb-1">
                                    Send to
                                </label>
                                <input type="email" id="test_recipient" name="test_recipient" required maxlength="190"
                                       value="{{ old('test_recipient') }}"
                                       placeholder="you@example.com"
                                       class="w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                                @error('test_recipient')
                                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            <button type="submit"
                                    class="mt-5 inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm shrink-0">
                                <x-admin.icon name="send" class="w-4 h-4" />
                                Send Test
                            </button>
                        </form>

                        @if (($effectiveMail['Mailer'] ?? null) === 'log')
                            <div role="note" class="flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-lg p-3 mt-4">
                                <x-admin.icon name="archive" class="w-4 h-4 mt-0.5 shrink-0 text-amber-600" />
                                <p class="text-xs text-amber-800">
                                    The mailer is set to <strong>Log file</strong>, so a test writes the
                                    message to <code class="font-mono">storage/logs</code> and delivers
                                    nothing. Switch it to SMTP to test real delivery.
                                </p>
                            </div>
                        @endif
                    </div>
                </x-admin.panel>
            </div>
        @endif

        {{-- ---------------- SMS delivery reports ---------------- --}}
        @if ($smsDeliveryUrl)
            <div class="mt-5">
                <x-admin.panel title="Delivery Reports" icon="activity">
                    <x-admin.field-row
                        label="Report URL"
                        help="Sent with every message, so there is nothing to configure in the Infobip portal. Shown here in case their support ever asks for it.">
                        <div class="flex items-center gap-2">
                            <input type="text" readonly value="{{ $smsDeliveryUrl }}"
                                   data-copy-source
                                   class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3.5 py-2.5 text-xs text-gray-700 font-mono">
                            <button type="button" data-copy-button
                                    class="shrink-0 rounded-lg border border-gray-300 px-3 py-2.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 transition">
                                Copy
                            </button>
                        </div>

                        {{-- Said here because a copy button invites somebody to paste
                             the address into a browser to see whether it works. It
                             answers, rather than looking broken. --}}
                        <p class="text-xs text-gray-500 mt-1.5">
                            Opening this in a browser is safe and reads nothing. It replies
                            <code class="font-mono">ready</code> to confirm the address is live;
                            Infobip uses POST to send the actual reports.
                        </p>
                    </x-admin.field-row>

                    <div class="px-5 py-4">
                        <div role="note" class="flex items-start gap-3 bg-blue-50 border border-blue-200 rounded-lg p-4">
                            <x-admin.icon name="activity" class="w-5 h-5 mt-0.5 shrink-0 text-blue-600" />
                            <div class="text-sm text-blue-800">
                                <p class="font-semibold mb-0.5">Why this matters</p>
                                <p>
                                    Infobip accepting a message is not the same as a handset
                                    receiving it. A phone switched off, out of credit or simply not
                                    that number all look identical at the moment of sending. This
                                    address is how the difference gets recorded.
                                </p>
                                @if (str_contains($smsDeliveryUrl, 'localhost') || str_contains($smsDeliveryUrl, '127.0.0.1'))
                                    <p class="text-xs mt-2 font-semibold">
                                        This is a local address, so Infobip cannot reach it. Reports
                                        will only start arriving once the site is on a public domain,
                                        and until then every text will read as handed over with no
                                        report.
                                    </p>
                                @endif
                            </div>
                        </div>
                    </div>
                </x-admin.panel>
            </div>
        @endif

        {{-- ---------------- SMS test ---------------- --}}
        @if ($activeTab === 'sms' && $canUpdate)
            <div class="mt-5">
                <x-admin.panel title="Send a Test SMS" icon="send">
                    <div class="px-5 py-4">
                        <div class="flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 p-3 mb-4">
                            <x-admin.icon name="mobile" class="w-4 h-4 mt-0.5 shrink-0 text-gray-500" />
                            <p class="text-sm text-gray-700">{{ $smsSummary }}</p>
                        </div>

                        {{-- Said plainly, because unlike the email test this one
                             spends money and reaches a real handset. --}}
                        <p class="text-sm text-gray-600 mb-4">
                            Sends one real text using the settings above, so it costs one message
                            and the recipient will actually receive it. Save first if you have just
                            changed anything. Any format works; 017-859 1411 and 0178591411 are
                            both understood.
                        </p>

                        @if (session('test_sms_success'))
                            <div role="status" class="flex items-start gap-3 bg-green-50 border border-green-200 rounded-lg p-4 mb-4">
                                <svg class="w-5 h-5 shrink-0 text-green-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <div class="text-sm text-green-800">
                                    <p class="font-semibold mb-0.5">Accepted by the gateway</p>
                                    <p>{{ session('test_sms_success') }}</p>
                                </div>
                            </div>
                        @endif

                        @if (session('test_sms_error'))
                            <div role="alert" class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
                                <div class="flex items-start gap-3">
                                    <svg class="w-5 h-5 shrink-0 text-red-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                    </svg>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-red-900 mb-1">Could not send</p>
                                        <pre class="text-xs text-red-800 whitespace-pre-wrap break-words font-mono bg-red-100/60 rounded p-2">{{ session('test_sms_error') }}</pre>
                                        <p class="text-xs text-red-700 mt-2">
                                            That message comes from the gateway. Nothing was sent.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        @endif

                        <form action="{{ route('admin.settings.integration.sms.test') }}" method="POST"
                              class="flex flex-wrap items-start gap-3"
                              onsubmit="return confirm('Send a real test SMS? This costs one message and the number you entered will receive it.');">
                            @csrf

                            <div class="flex-1 min-w-64">
                                <label for="test_number" class="block text-xs font-semibold text-gray-700 mb-1">
                                    Send to
                                </label>
                                <input type="tel" id="test_number" name="test_number" required maxlength="30"
                                       value="{{ old('test_number') }}"
                                       placeholder="017-859 1411"
                                       class="w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                                @error('test_number')
                                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            <button type="submit"
                                    class="mt-5 inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm shrink-0">
                                <x-admin.icon name="send" class="w-4 h-4" />
                                Send Test
                            </button>
                        </form>
                    </div>
                </x-admin.panel>
            </div>
        @endif

        {{-- ---------------- Telegram test ---------------- --}}
        @if ($activeTab === 'telegram' && $canUpdate)
            <div class="mt-5">
                <x-admin.panel title="Post a Test Message" icon="send">
                    <div class="px-5 py-4">
                        <div class="flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 p-3 mb-4">
                            <x-admin.icon name="chat" class="w-4 h-4 mt-0.5 shrink-0 text-gray-500" />
                            <p class="text-sm text-gray-700">{{ $telegramSummary }}</p>
                        </div>

                        <p class="text-sm text-gray-600 mb-4">
                            Checks the bot token, then posts one message into the group. The bot has
                            to have been added to the group first, otherwise the token will pass and
                            the post will still fail.
                        </p>

                        @if (session('test_telegram_success'))
                            <div role="status" class="flex items-start gap-3 bg-green-50 border border-green-200 rounded-lg p-4 mb-4">
                                <svg class="w-5 h-5 shrink-0 text-green-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <div class="text-sm text-green-800">
                                    <p class="font-semibold mb-0.5">Posted</p>
                                    <p>{{ session('test_telegram_success') }}</p>
                                </div>
                            </div>
                        @endif

                        @if (session('test_telegram_error'))
                            <div role="alert" class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
                                <div class="flex items-start gap-3">
                                    <svg class="w-5 h-5 shrink-0 text-red-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                    </svg>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-red-900 mb-1">Could not post</p>
                                        <pre class="text-xs text-red-800 whitespace-pre-wrap break-words font-mono bg-red-100/60 rounded p-2">{{ session('test_telegram_error') }}</pre>
                                        <p class="text-xs text-red-700 mt-2">
                                            That message comes from Telegram. Nothing was posted.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        @endif

                        <form action="{{ route('admin.settings.integration.telegram.test') }}" method="POST">
                            @csrf
                            <button type="submit"
                                    class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm">
                                <x-admin.icon name="send" class="w-4 h-4" />
                                Post Test Message
                            </button>
                        </form>
                    </div>
                </x-admin.panel>
            </div>
        @endif
    </x-admin.settings-shell>
@endsection

@push('scripts')
<script>
    (function () {
        // Show only the credential panel for the gateway currently chosen.
        const providerSelect = document.getElementById('provider');

        if (providerSelect) {
            const panels = Array.from(document.querySelectorAll('[data-provider-panel]'));

            providerSelect.addEventListener('change', function () {
                panels.forEach(function (panel) {
                    panel.classList.toggle('hidden', panel.dataset.providerPanel !== providerSelect.value);
                });
            });
        }

        // Copy buttons next to the read only callback URLs.
        document.querySelectorAll('[data-copy-button]').forEach(function (button) {
            button.addEventListener('click', function () {
                const input = button.parentElement?.querySelector('[data-copy-source]');

                if (!input) {
                    return;
                }

                input.select();

                const done = function () {
                    const original = button.textContent;
                    button.textContent = 'Copied';
                    setTimeout(function () { button.textContent = original; }, 1500);
                };

                if (navigator.clipboard) {
                    navigator.clipboard.writeText(input.value).then(done).catch(function () {
                        // Clipboard API needs a secure context. The text is
                        // already selected, so the user can copy it manually.
                    });
                    return;
                }

                done();
            });
        });
    })();
</script>
@endpush
