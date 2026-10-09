@extends('layouts.admin')

@section('title', 'General Config')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Settings</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">General Config</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>{{ $tabs[$activeTab]['label'] }}</span>
@endsection

@section('content')
    @php
        $input = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition disabled:bg-gray-100 disabled:text-gray-500';
    @endphp

    <x-admin.settings-shell
        title="General Config"
        description="System-wide configuration settings for the Smart Digital Creative admin panel."
        :tabs="$tabs"
        :active-tab="$activeTab"
        route="admin.settings.general">

        {{-- ==================== General ==================== --}}
        @if ($activeTab === 'general')
            <x-admin.section-intro
                title="General Settings"
                description="Core system settings — site identity, company registration, contact details and regional preferences."
                icon="sliders" />

            {{-- enctype matters: without it the browser posts field names with no file
                 data, so the uploads below would silently never arrive. --}}
            <form action="{{ route('admin.settings.general.update') }}" method="POST" enctype="multipart/form-data">
                @csrf
                @method('PUT')

                {{-- ==================== Branding ====================
                     Three cards. The whole card is the button: clicking it opens the
                     file picker, because a card sized target is far easier to hit than
                     a browser's own file input, and the preview then sits where the
                     click happened. The real input stays in the DOM, hidden, so the
                     form still posts normally and needs no JavaScript to submit. --}}
                <x-admin.panel title="Branding" icon="grid" :flush="true">
                    <div class="px-5 py-4 border-b border-gray-100">
                        <p class="text-sm text-gray-600">
                            Click a card to choose a new image. Nothing is uploaded until you press
                            Save Changes, and leaving a card alone keeps the image it already has.
                        </p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 p-5">
                        @foreach ($branding as $card)
                            @php
                                $hasImage = filled($card['url']);
                                $box = $card['preview'] === 'square'
                                    ? 'w-12 h-12'
                                    : 'w-full h-12';
                            @endphp

                            <div class="rounded-lg border border-gray-200 bg-white overflow-hidden"
                                 data-branding-card="{{ $card['field'] }}">

                                <label for="{{ $card['field'] }}"
                                       @class([
                                           'block px-4 py-4 text-center',
                                           'cursor-pointer hover:bg-blue-50/50 transition' => $canUpdateGeneral,
                                           'cursor-not-allowed' => ! $canUpdateGeneral,
                                       ])>

                                    <span class="flex items-center justify-center h-14 mb-3">
                                        <img data-branding-preview="{{ $card['field'] }}"
                                             src="{{ $card['url'] }}"
                                             alt="{{ $card['title'] }} preview"
                                             @class([$box, 'object-contain', 'hidden' => ! $hasImage])>

                                        <span data-branding-empty="{{ $card['field'] }}"
                                              @class(['text-xs text-gray-400', 'hidden' => $hasImage])>
                                            Nothing set
                                        </span>
                                    </span>

                                    <span class="block text-sm font-semibold text-gray-900">{{ $card['title'] }}</span>
                                    <span class="block text-xs text-gray-500 mt-0.5">{{ $card['description'] }}</span>

                                    @if ($canUpdateGeneral)
                                        <span class="inline-block mt-2 text-xs font-semibold text-blue-600">
                                            {{ $hasImage ? 'Choose a different file' : 'Choose a file' }}
                                        </span>
                                    @endif

                                    {{-- Hidden, not removed. The card above is its label, so a click
                                         or Enter on the card opens the picker, and the input still
                                         posts with the form. --}}
                                    <input type="file"
                                           id="{{ $card['field'] }}"
                                           name="{{ $card['field'] }}"
                                           accept="{{ $card['accept'] }}"
                                           @disabled(! $canUpdateGeneral)
                                           class="sr-only"
                                           data-branding-input="{{ $card['field'] }}">
                                </label>

                                <div class="px-4 pb-3 space-y-1.5">
                                    <p class="text-xs text-gray-400">{{ $card['help'] }}</p>

                                    <p data-branding-filename="{{ $card['field'] }}"
                                       class="hidden text-xs font-semibold text-blue-700 truncate"></p>

                                    @error($card['field'])
                                        <p class="text-xs text-red-600">{{ $message }}</p>
                                    @enderror

                                    {{-- Only offered when an upload is in place. There is nothing to
                                         remove while the card is showing the image shipped with the
                                         project. --}}
                                    @if ($canUpdateGeneral && $card['custom'])
                                        <label class="inline-flex items-center gap-2 cursor-pointer select-none">
                                            <input type="hidden" name="remove_{{ $card['field'] }}" value="0">
                                            <input type="checkbox" name="remove_{{ $card['field'] }}" value="1"
                                                   @checked(old('remove_' . $card['field']))
                                                   class="w-3.5 h-3.5 rounded border-gray-300 text-red-600 focus:ring-2 focus:ring-red-500/40">
                                            <span class="text-xs text-gray-600">Remove and use the default</span>
                                        </label>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-admin.panel>

                <x-admin.panel title="Site Identity" icon="identification">
                    <x-admin.field-row label="Site Name" help="Displayed in the browser tab and in outgoing emails." for="site_name" :required="true" error="site_name">
                        <input type="text" id="site_name" name="site_name" required maxlength="150"
                               value="{{ old('site_name', $general['site_name']) }}"
                               @disabled(! $canUpdateGeneral)
                               class="{{ $input }}">
                    </x-admin.field-row>

                    <x-admin.field-row label="Tagline" help="Short line shown under the site name." for="tagline" error="tagline">
                        <input type="text" id="tagline" name="tagline" maxlength="200"
                               value="{{ old('tagline', $general['tagline']) }}"
                               @disabled(! $canUpdateGeneral)
                               class="{{ $input }}">
                    </x-admin.field-row>
                </x-admin.panel>

                <x-admin.panel title="Company Registration" icon="building">
                    <x-admin.field-row label="Company Reg. No." help="SSM registration number." for="registration_no" error="registration_no">
                        <input type="text" id="registration_no" name="registration_no" maxlength="100"
                               value="{{ old('registration_no', $general['registration_no']) }}"
                               @disabled(! $canUpdateGeneral)
                               class="{{ $input }}">
                    </x-admin.field-row>
                </x-admin.panel>

                <x-admin.panel title="Contact Details" icon="phone">
                    <x-admin.field-row label="Contact Email" help="Where website enquiries are sent." for="contact_email" :required="true" error="contact_email">
                        <input type="email" id="contact_email" name="contact_email" required maxlength="190"
                               value="{{ old('contact_email', $general['contact_email']) }}"
                               @disabled(! $canUpdateGeneral)
                               class="{{ $input }}">
                    </x-admin.field-row>

                    <x-admin.field-row label="Contact Phone" help="Shown in the top header and footer." for="contact_phone" :required="true" error="contact_phone">
                        <input type="tel" id="contact_phone" name="contact_phone" required maxlength="30"
                               value="{{ old('contact_phone', $general['contact_phone']) }}"
                               @disabled(! $canUpdateGeneral)
                               class="{{ $input }}">
                    </x-admin.field-row>

                    <x-admin.field-row label="WhatsApp Number" help="Used for the WhatsApp link on the contact page." for="whatsapp" error="whatsapp">
                        <input type="tel" id="whatsapp" name="whatsapp" maxlength="30"
                               value="{{ old('whatsapp', $general['whatsapp']) }}"
                               @disabled(! $canUpdateGeneral)
                               class="{{ $input }}">
                    </x-admin.field-row>

                    <x-admin.field-row label="Office Address" help="One line per row." for="address" error="address">
                        <textarea id="address" name="address" rows="4" maxlength="500"
                                  @disabled(! $canUpdateGeneral)
                                  class="{{ $input }} resize-y">{{ old('address', $general['address']) }}</textarea>
                    </x-admin.field-row>
                </x-admin.panel>

                <x-admin.panel title="Locale & Regional" icon="globe">
                    <x-admin.field-row label="Timezone" help="Drives the clock in the site top header and all timestamps." for="timezone" :required="true" error="timezone">
                        <select id="timezone" name="timezone" required @disabled(! $canUpdateGeneral) class="{{ $input }} bg-white">
                            @foreach ($timezones as $tz)
                                <option value="{{ $tz }}" @selected(old('timezone', $general['timezone']) === $tz)>{{ $tz }}</option>
                            @endforeach
                        </select>
                    </x-admin.field-row>

                    <x-admin.field-row label="Date Format" help="How dates are shown across the site. Leave on the default to keep the current look." for="date_format" error="date_format">
                        <select id="date_format" name="date_format" @disabled(! $canUpdateGeneral) class="{{ $input }} bg-white">
                            <option value="" @selected(old('date_format', $general['date_format']) === '')>Use current default (13 Oct 2026)</option>
                            @foreach ($dateFormats as $sample)
                                <option value="{{ $sample }}" @selected(old('date_format', $general['date_format']) === $sample)>{{ $sample }}</option>
                            @endforeach
                        </select>
                    </x-admin.field-row>

                    <x-admin.field-row label="Time Format" help="How times are shown across the site. Leave on the default to keep the current look." for="time_format" error="time_format">
                        <select id="time_format" name="time_format" @disabled(! $canUpdateGeneral) class="{{ $input }} bg-white">
                            <option value="" @selected(old('time_format', $general['time_format']) === '')>Use current default (1:00 pm)</option>
                            @foreach ($timeFormats as $sample)
                                <option value="{{ $sample }}" @selected(old('time_format', $general['time_format']) === $sample)>{{ $sample }}</option>
                            @endforeach
                        </select>
                    </x-admin.field-row>
                </x-admin.panel>

                <div class="flex items-center justify-between gap-4 bg-white rounded-lg border border-gray-200 px-5 py-4 mt-5">
                    @if ($canUpdateGeneral)
                        <p class="text-xs text-gray-500">Changes take effect immediately after saving.</p>
                        <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm shrink-0">
                            Save Changes
                        </button>
                    @else
                        <p class="text-xs text-gray-500">Your role can view these settings but not change them.</p>
                    @endif
                </div>
            </form>
        @endif

        {{-- ==================== Security ====================
             Password policy and session controls. Grouped under clear section
             headings so Parts 2 and 3 (banned IPs, rate limit, IP allowlist,
             audit toggle) can be appended as further panels on this same tab. --}}
        @if ($activeTab === 'security')
            <x-admin.section-intro
                title="Security"
                description="Password policy and session controls for the admin area. Defaults match the current behaviour, so saving without changes leaves everything as it is."
                icon="shield"
                accent="purple" />

            <form action="{{ route('admin.settings.security.update') }}" method="POST">
                @csrf
                @method('PUT')

                <x-admin.panel title="Password Policy" icon="lock">
                    <x-admin.field-row label="Minimum Length" help="The shortest password a new or changed account password may be. Cannot be lower than 8." for="password_min" :required="true" error="password_min">
                        <input type="number" id="password_min" name="password_min" required
                               min="{{ \App\Support\SecuritySettings::MIN_PASSWORD_MIN }}"
                               max="{{ \App\Support\SecuritySettings::MAX_PASSWORD_MIN }}"
                               value="{{ old('password_min', $security['password_min']) }}"
                               @disabled(! $canUpdateSecurity)
                               class="{{ $input }}">
                    </x-admin.field-row>

                    <x-admin.field-row label="Require Uppercase" help="Force both an upper and a lower case letter. Off by default, matching today's rule." for="password_require_upper" error="password_require_upper">
                        <div class="md:pt-2">
                            <label for="password_require_upper" class="inline-flex items-center gap-2.5 cursor-pointer">
                                <input type="hidden" name="password_require_upper" value="0">
                                <input type="checkbox" id="password_require_upper" name="password_require_upper" value="1"
                                       @checked(old('password_require_upper', $security['password_require_upper']) === '1')
                                       @disabled(! $canUpdateSecurity)
                                       class="rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                                <span class="text-sm text-gray-700">Require a mix of upper and lower case</span>
                            </label>
                        </div>
                    </x-admin.field-row>

                    <x-admin.field-row label="Require Number" help="At least one digit." for="password_require_number" error="password_require_number">
                        <div class="md:pt-2">
                            <label for="password_require_number" class="inline-flex items-center gap-2.5 cursor-pointer">
                                <input type="hidden" name="password_require_number" value="0">
                                <input type="checkbox" id="password_require_number" name="password_require_number" value="1"
                                       @checked(old('password_require_number', $security['password_require_number']) === '1')
                                       @disabled(! $canUpdateSecurity)
                                       class="rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                                <span class="text-sm text-gray-700">Require at least one number</span>
                            </label>
                        </div>
                    </x-admin.field-row>

                    <x-admin.field-row label="Require Symbol" help="At least one symbol such as ! or @." for="password_require_symbol" error="password_require_symbol">
                        <div class="md:pt-2">
                            <label for="password_require_symbol" class="inline-flex items-center gap-2.5 cursor-pointer">
                                <input type="hidden" name="password_require_symbol" value="0">
                                <input type="checkbox" id="password_require_symbol" name="password_require_symbol" value="1"
                                       @checked(old('password_require_symbol', $security['password_require_symbol']) === '1')
                                       @disabled(! $canUpdateSecurity)
                                       class="rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                                <span class="text-sm text-gray-700">Require at least one symbol</span>
                            </label>
                        </div>
                    </x-admin.field-row>

                    <x-admin.field-row label="Password Expiry (days)" help="Days a password stays valid. 0 means it never expires, which is the current behaviour." for="password_expiry_days" :required="true" error="password_expiry_days">
                        <input type="number" id="password_expiry_days" name="password_expiry_days" required
                               min="0" max="{{ \App\Support\SecuritySettings::MAX_PASSWORD_EXPIRY_DAYS }}"
                               value="{{ old('password_expiry_days', $security['password_expiry_days']) }}"
                               @disabled(! $canUpdateSecurity)
                               class="{{ $input }}">
                        <p class="text-xs text-gray-500 mt-1.5">
                            The change date is now recorded. Forcing an expired user to change their
                            password is a planned follow-up, so a value above 0 is stored but not yet
                            enforced.
                        </p>
                    </x-admin.field-row>
                </x-admin.panel>

                <x-admin.panel title="Sessions" icon="activity">
                    <x-admin.field-row label="Inactivity Timeout (minutes)" help="Log an admin out after this many minutes with no activity. Defaults to the current session lifetime." for="session_timeout_minutes" :required="true" error="session_timeout_minutes">
                        <input type="number" id="session_timeout_minutes" name="session_timeout_minutes" required
                               min="{{ \App\Support\SecuritySettings::MIN_SESSION_TIMEOUT_MINUTES }}"
                               max="{{ \App\Support\SecuritySettings::MAX_SESSION_TIMEOUT_MINUTES }}"
                               value="{{ old('session_timeout_minutes', $security['session_timeout_minutes']) }}"
                               @disabled(! $canUpdateSecurity)
                               class="{{ $input }}">
                    </x-admin.field-row>

                    <x-admin.field-row label="One Session Per User" help="When on, logging in ends this user's other sessions. Off by default." for="single_session" error="single_session">
                        <div class="md:pt-2">
                            <label for="single_session" class="inline-flex items-center gap-2.5 cursor-pointer">
                                <input type="hidden" name="single_session" value="0">
                                <input type="checkbox" id="single_session" name="single_session" value="1"
                                       @checked(old('single_session', $security['single_session']) === '1')
                                       @disabled(! $canUpdateSecurity)
                                       class="rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                                <span class="text-sm text-gray-700">Allow only one active session per user</span>
                            </label>
                        </div>
                    </x-admin.field-row>

                    <x-admin.field-row label="Clear Session On Logout" help="Delete the session record on logout and on auto-logout, instead of leaving an empty row behind." for="destroy_session_on_logout" error="destroy_session_on_logout">
                        <div class="md:pt-2">
                            <label for="destroy_session_on_logout" class="inline-flex items-center gap-2.5 cursor-pointer">
                                <input type="hidden" name="destroy_session_on_logout" value="0">
                                <input type="checkbox" id="destroy_session_on_logout" name="destroy_session_on_logout" value="1"
                                       @checked(old('destroy_session_on_logout', $security['destroy_session_on_logout']) === '1')
                                       @disabled(! $canUpdateSecurity)
                                       class="rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                                <span class="text-sm text-gray-700">Remove the session record on logout</span>
                            </label>
                        </div>
                    </x-admin.field-row>
                </x-admin.panel>

                {{-- Part 2. Everything below applies to the admin sign in and the admin
                     area only. The public website, registration, checkout and the
                     payment callbacks are never banned, limited or allowlisted. --}}
                <x-admin.panel title="Failed Sign-in Bans" icon="warning">
                    <x-admin.field-row label="Ban Repeated Failures" help="Block sign in from one IP address after too many failed attempts. A super admin can still sign in from a banned address." for="ban_enabled" error="ban_enabled">
                        <div class="md:pt-2">
                            <label for="ban_enabled" class="inline-flex items-center gap-2.5 cursor-pointer">
                                <input type="hidden" name="ban_enabled" value="0">
                                <input type="checkbox" id="ban_enabled" name="ban_enabled" value="1"
                                       @checked(old('ban_enabled', $security['ban_enabled']) === '1')
                                       @disabled(! $canUpdateSecurity)
                                       class="rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                                <span class="text-sm text-gray-700">Ban an IP address after repeated failed sign ins</span>
                            </label>
                        </div>
                    </x-admin.field-row>

                    <x-admin.field-row label="Failures Before Ban" help="Failed sign ins from one IP address that trigger a ban. At least {{ \App\Support\SecuritySettings::MIN_BAN_AFTER_FAILURES }}." for="ban_after_failures" :required="true" error="ban_after_failures">
                        <input type="number" id="ban_after_failures" name="ban_after_failures" required
                               min="{{ \App\Support\SecuritySettings::MIN_BAN_AFTER_FAILURES }}"
                               max="{{ \App\Support\SecuritySettings::MAX_BAN_AFTER_FAILURES }}"
                               value="{{ old('ban_after_failures', $security['ban_after_failures']) }}"
                               @disabled(! $canUpdateSecurity)
                               class="{{ $input }}">
                    </x-admin.field-row>

                    <x-admin.field-row label="Counting Window (minutes)" help="Only failures within this many minutes of each other count towards a ban." for="ban_window_minutes" :required="true" error="ban_window_minutes">
                        <input type="number" id="ban_window_minutes" name="ban_window_minutes" required
                               min="{{ \App\Support\SecuritySettings::MIN_BAN_WINDOW_MINUTES }}"
                               max="{{ \App\Support\SecuritySettings::MAX_BAN_WINDOW_MINUTES }}"
                               value="{{ old('ban_window_minutes', $security['ban_window_minutes']) }}"
                               @disabled(! $canUpdateSecurity)
                               class="{{ $input }}">
                    </x-admin.field-row>

                    <x-admin.field-row label="Ban Duration (minutes)" help="How long a ban lasts. It lifts on its own afterwards, or sooner from the list below." for="ban_duration_minutes" :required="true" error="ban_duration_minutes">
                        <input type="number" id="ban_duration_minutes" name="ban_duration_minutes" required
                               min="{{ \App\Support\SecuritySettings::MIN_BAN_DURATION_MINUTES }}"
                               max="{{ \App\Support\SecuritySettings::MAX_BAN_DURATION_MINUTES }}"
                               value="{{ old('ban_duration_minutes', $security['ban_duration_minutes']) }}"
                               @disabled(! $canUpdateSecurity)
                               class="{{ $input }}">
                    </x-admin.field-row>
                </x-admin.panel>

                <x-admin.panel title="Rate Limits" icon="pulse">
                    <x-admin.field-row label="Sign-in Attempts Per Minute" help="Sign in attempts allowed from one IP address per minute. 10 is the current behaviour." for="login_attempts_per_minute" :required="true" error="login_attempts_per_minute">
                        <input type="number" id="login_attempts_per_minute" name="login_attempts_per_minute" required
                               min="{{ \App\Support\SecuritySettings::MIN_LOGIN_ATTEMPTS_PER_MINUTE }}"
                               max="{{ \App\Support\SecuritySettings::MAX_LOGIN_ATTEMPTS_PER_MINUTE }}"
                               value="{{ old('login_attempts_per_minute', $security['login_attempts_per_minute']) }}"
                               @disabled(! $canUpdateSecurity)
                               class="{{ $input }}">
                    </x-admin.field-row>

                    <x-admin.field-row label="Admin Requests Per Minute" help="Requests one signed-in user may make per minute in the admin area. 0 means no limit, the current behaviour. Otherwise at least {{ \App\Support\SecuritySettings::MIN_ADMIN_REQUESTS_PER_MINUTE }}." for="admin_requests_per_minute" :required="true" error="admin_requests_per_minute">
                        <input type="number" id="admin_requests_per_minute" name="admin_requests_per_minute" required
                               min="0"
                               max="{{ \App\Support\SecuritySettings::MAX_ADMIN_REQUESTS_PER_MINUTE }}"
                               value="{{ old('admin_requests_per_minute', $security['admin_requests_per_minute']) }}"
                               @disabled(! $canUpdateSecurity)
                               class="{{ $input }}">
                    </x-admin.field-row>
                </x-admin.panel>

                <x-admin.panel title="IP Allowlist" icon="globe">
                    <x-admin.field-row label="Allowed IP Addresses" help="One IP address or CIDR range per line. Leave empty to allow sign in from anywhere, the current behaviour." for="ip_allowlist" error="ip_allowlist">
                        <textarea id="ip_allowlist" name="ip_allowlist" rows="5"
                                  maxlength="{{ \App\Support\SecuritySettings::MAX_IP_ALLOWLIST_LENGTH }}"
                                  placeholder="203.0.113.10&#10;198.51.100.0/24"
                                  spellcheck="false"
                                  @disabled(! $canUpdateSecurity)
                                  class="{{ $input }} resize-y font-mono">{{ old('ip_allowlist', $security['ip_allowlist']) }}</textarea>

                        <p class="text-xs text-gray-500 mt-1.5">
                            Your current IP: <span class="font-mono font-semibold text-gray-700">{{ $currentIp }}</span>
                        </p>
                        <p class="text-xs text-amber-700 mt-1">
                            When the list is not empty, anyone who is not a super admin is refused sign in, and
                            signed out, unless their IP is on it. Add your own address before saving. A super
                            admin is never blocked by this list, and failed sign ins from a listed address are
                            never counted towards a ban.
                        </p>
                    </x-admin.field-row>
                </x-admin.panel>

                <div class="flex items-center justify-between gap-4 bg-white rounded-lg border border-gray-200 px-5 py-4 mt-5">
                    @if ($canUpdateSecurity)
                        <p class="text-xs text-gray-500">Changes take effect immediately after saving.</p>
                        <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm shrink-0">
                            Save Changes
                        </button>
                    @else
                        <p class="text-xs text-gray-500">Your role can view these settings but not change them.</p>
                    @endif
                </div>
            </form>

            {{-- Banned IPs. Outside the settings form above on purpose: HTML does not
                 allow one form inside another, and every button here is a DELETE of
                 its own. Bans currently in force only; expired ones no longer bar
                 anybody and are not listed. --}}
            <x-admin.panel title="Banned IPs" icon="shield" :flush="true" class="mt-5">
                @if ($canUpdateSecurity && $bans->isNotEmpty())
                    <x-slot:actions>
                        <form action="{{ route('admin.settings.security.bans.clear') }}" method="POST"
                              onsubmit="return confirm('Lift every ban in this list?\n\nEach address can try to sign in again straight away.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-red-600 hover:bg-red-50 transition">
                                <x-admin.icon name="trash" class="w-4 h-4" />
                                Clear all
                            </button>
                        </form>
                    </x-slot:actions>
                @endif

                @if (! $bansEnforced && $bans->isNotEmpty())
                    <p class="px-5 py-3 text-xs text-amber-800 bg-amber-50 border-b border-amber-200">
                        Bans are switched off, so the addresses below are not being blocked.
                    </p>
                @endif

                @if ($bans->isEmpty())
                    <p class="px-5 py-10 text-sm text-gray-500 text-center">
                        No IP address is banned right now.
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 text-left">
                                <tr>
                                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">IP Address</th>
                                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Failed Attempts</th>
                                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Banned At</th>
                                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Expires At</th>
                                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Reason</th>
                                    @if ($canUpdateSecurity)
                                        <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 text-center">Actions</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($bans as $ban)
                                    <tr>
                                        <td class="px-5 py-3 font-mono text-gray-900 break-all">{{ $ban->ip_address }}</td>
                                        <td class="px-5 py-3 text-gray-600 tabular-nums">{{ $ban->failed_attempts }}</td>
                                        <td class="px-5 py-3 text-gray-600 whitespace-nowrap">{{ \App\Support\LocalTime::format($ban->banned_at) }}</td>
                                        <td class="px-5 py-3 text-gray-600 whitespace-nowrap">{{ \App\Support\LocalTime::format($ban->expires_at) }}</td>
                                        <td class="px-5 py-3 text-gray-600">{{ $ban->reason }}</td>
                                        @if ($canUpdateSecurity)
                                            <td class="px-5 py-3 text-center whitespace-nowrap">
                                                <form action="{{ route('admin.settings.security.bans.destroy', $ban) }}" method="POST">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit"
                                                            class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-red-600 hover:bg-red-50 transition"
                                                            aria-label="Remove the ban on {{ $ban->ip_address }}">
                                                        <x-admin.icon name="trash" class="w-4 h-4" />
                                                        Remove
                                                    </button>
                                                </form>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-admin.panel>
        @endif

        {{-- ==================== Backup & Restore ==================== --}}
        @if ($activeTab === 'backup')
            <x-admin.section-intro
                title="Backup & Restore"
                description="Database status and the backup files currently held on disk."
                icon="database"
                accent="purple" />

            @if (! $canViewBackup)
                <x-admin.panel title="Backup Files" icon="archive" :flush="true">
                    <p class="px-5 py-10 text-sm text-gray-500 text-center">
                        Your role does not include permission to view backups.
                    </p>
                </x-admin.panel>
            @else
                {{-- Taking a backup is live. Reading one back is not, and will not be
                     until it has its own confirmation path: a restore overwrites data
                     that cannot be recovered afterwards. --}}
                <div role="note" class="flex items-start gap-3 bg-blue-50 border border-blue-200 rounded-lg p-4 mb-5">
                    <svg class="w-5 h-5 shrink-0 text-blue-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <div class="text-sm text-blue-800">
                        <p class="font-semibold mb-1">Backups run. Restore comes later.</p>
                        <p>
                            Each archive holds the whole database and everything uploaded. An automatic
                            backup is taken daily at {{ $backup['daily_at'] }}
                            ({{ \App\Support\LocalTime::zone() }}) and the newest {{ $backup['keep'] }} are kept;
                            backups you take by hand are never removed automatically. Reading an archive
                            back overwrites live data and cannot be undone, so it is deliberately not a
                            button here yet.
                        </p>
                    </div>
                </div>

                <div role="alert" class="flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-lg p-4 mb-5">
                    <svg class="w-5 h-5 shrink-0 text-amber-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <p class="text-sm text-amber-800">
                        An archive carries every participant's identity card number and every password
                        hash. It is kept outside the website's reach and can only be fetched through the
                        button below, which records who did it. Treat a downloaded copy the same way.
                    </p>
                </div>

                <x-admin.panel title="Database" icon="database">
                    @foreach ([
                        'Connection' => $backup['connection'],
                        'Driver' => $backup['driver'],
                        'Database' => $backup['database'],
                        'Host' => $backup['host'],
                        'Tables' => $backup['table_count'] !== null ? number_format($backup['table_count']) : 'Unavailable',
                    ] as $key => $value)
                        <x-admin.field-row :label="$key">
                            <p class="text-sm text-gray-900 md:pt-2.5 break-all">{{ $value ?: '—' }}</p>
                        </x-admin.field-row>
                    @endforeach
                </x-admin.panel>

                {{-- Back up now. A POST of its own, outside any other form, and it
                     only queues the work: zipping the uploads folder takes longer
                     than a web request may live, so the cron worker does it and the
                     archive turns up in the list below. --}}
                <x-admin.panel title="Backup Files" icon="archive" :flush="true">
                    @if ($canCreateBackup)
                        <x-slot:actions>
                            <form action="{{ route('admin.settings.backup.run') }}" method="POST"
                                  onsubmit="return confirm('Take a backup now?\n\nIt runs in the background and appears in this list within a few minutes.');">
                                @csrf
                                <button type="submit"
                                        class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-blue-600 hover:bg-blue-50 transition">
                                    <x-admin.icon name="database" class="w-4 h-4" />
                                    Back up now
                                </button>
                            </form>
                        </x-slot:actions>
                    @endif

                    @if ($backup['pending'])
                        <p class="px-5 py-3 text-xs text-blue-800 bg-blue-50 border-b border-blue-200">
                            A backup is queued. It will appear here within a few minutes, once the
                            background worker has picked it up.
                        </p>
                    @endif

                    @if (count($backup['files']) === 0)
                        <p class="px-5 py-10 text-sm text-gray-500 text-center">
                            No backups yet. Nothing has been written to <code class="text-xs">{{ $backup['path'] }}</code>.
                        </p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 text-left">
                                    <tr>
                                        <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">File</th>
                                        <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Type</th>
                                        <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Created</th>
                                        <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Size</th>
                                        <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Dump</th>
                                        @if ($canDownloadBackup || $canDeleteBackup)
                                            <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 text-center">Actions</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($backup['files'] as $file)
                                        <tr>
                                            <td class="px-5 py-3 text-gray-900 break-all font-mono text-xs">{{ $file['name'] }}</td>
                                            <td class="px-5 py-3 whitespace-nowrap">
                                                <x-admin.badge :tone="$file['type'] === 'auto' ? 'blue' : 'purple'">
                                                    {{ $file['type'] === 'auto' ? 'Automatic' : 'Manual' }}
                                                </x-admin.badge>
                                            </td>
                                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap">{{ \App\Support\LocalTime::format($file['created_at']) }}</td>
                                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap tabular-nums">{{ \App\Services\Backup\BackupStore::humanBytes($file['bytes']) }}</td>
                                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap">{{ $file['method'] ?? '—' }}</td>
                                            @if ($canDownloadBackup || $canDeleteBackup)
                                                <td class="px-5 py-3 text-center whitespace-nowrap">
                                                    <div class="inline-flex items-center gap-0.5">
                                                        @if ($canDownloadBackup)
                                                            <a href="{{ route('admin.settings.backup.download', $file['name']) }}"
                                                               class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-blue-600 hover:bg-blue-50 transition"
                                                               aria-label="Download {{ $file['name'] }}">
                                                                <x-admin.icon name="archive" class="w-4 h-4" />
                                                                Download
                                                            </a>
                                                        @endif

                                                        @if ($canDeleteBackup)
                                                            <form action="{{ route('admin.settings.backup.destroy', $file['name']) }}" method="POST"
                                                                  onsubmit="return confirm('Delete {{ $file['name'] }}?\n\nThis removes the archive from the server. If it is your only copy, it cannot be recovered.');">
                                                                @csrf
                                                                @method('DELETE')
                                                                <button type="submit"
                                                                        class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-red-600 hover:bg-red-50 transition"
                                                                        aria-label="Delete {{ $file['name'] }}">
                                                                    <x-admin.icon name="trash" class="w-4 h-4" />
                                                                    Delete
                                                                </button>
                                                            </form>
                                                        @endif
                                                    </div>
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- The total matters on shared hosting: the account has a disk
                             quota, and seven full archives add up quietly. --}}
                        <div class="flex items-center justify-between gap-4 px-5 py-3 bg-gray-50 border-t border-gray-200">
                            <p class="text-xs text-gray-500">
                                {{ count($backup['files']) }} {{ count($backup['files']) === 1 ? 'archive' : 'archives' }}
                                in <code class="text-xs">{{ $backup['path'] }}</code>
                            </p>
                            <p class="text-xs font-semibold text-gray-700 tabular-nums">
                                Total {{ \App\Services\Backup\BackupStore::humanBytes($backup['total_bytes']) }}
                            </p>
                        </div>
                    @endif
                </x-admin.panel>
            @endif
        @endif

        {{-- ==================== Maintenance ==================== --}}
        @if ($activeTab === 'maintenance')
            <x-admin.section-intro
                title="Maintenance"
                description="Show a holding page to website visitors while work is in progress."
                icon="wrench"
                accent="amber" />

            <div role="note" class="flex items-start gap-3 bg-blue-50 border border-blue-200 rounded-lg p-4 mb-5">
                <svg class="w-5 h-5 shrink-0 text-blue-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-sm text-blue-800">
                    This affects the public website only. The admin area stays reachable, so you
                    cannot lock yourself out by turning it on.
                </p>
            </div>

            <form action="{{ route('admin.settings.maintenance.update') }}" method="POST">
                @csrf
                @method('PUT')

                <x-admin.panel title="Maintenance Mode" icon="power">
                    <x-admin.field-row label="Status" help="Turn the holding page on or off." for="enabled" error="enabled">
                        <div class="md:pt-2">
                            <label for="enabled" class="inline-flex items-center gap-2.5 cursor-pointer">
                                <input type="checkbox" id="enabled" name="enabled" value="1"
                                       @checked(old('enabled', $maintenance['enabled'] === '1'))
                                       @disabled(! $canUpdateMaintenance)
                                       class="rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                                <span class="text-sm text-gray-700">Enable maintenance mode</span>
                            </label>

                            <p class="text-xs text-gray-500 mt-1.5">
                                Currently
                                @if ($maintenance['enabled'] === '1')
                                    <span class="font-semibold text-red-600">ON</span> &mdash; visitors see the holding page.
                                @else
                                    <span class="font-semibold text-green-600">OFF</span> &mdash; the website is live.
                                @endif
                            </p>
                        </div>
                    </x-admin.field-row>
                </x-admin.panel>

                <x-admin.panel title="Holding Page Content" icon="clipboard">
                    <x-admin.field-row label="Heading" help="Large text at the top of the holding page." for="heading" :required="true" error="heading">
                        <input type="text" id="heading" name="heading" required maxlength="150"
                               value="{{ old('heading', $maintenance['heading']) }}"
                               @disabled(! $canUpdateMaintenance)
                               class="{{ $input }}">
                    </x-admin.field-row>

                    <x-admin.field-row label="Message" help="Explain what is happening and when to come back." for="message" :required="true" error="message">
                        <textarea id="message" name="message" rows="4" required maxlength="1000"
                                  @disabled(! $canUpdateMaintenance)
                                  class="{{ $input }} resize-y">{{ old('message', $maintenance['message']) }}</textarea>
                    </x-admin.field-row>
                </x-admin.panel>

                <div class="flex items-center justify-between gap-4 bg-white rounded-lg border border-gray-200 px-5 py-4 mt-5">
                    @if ($canUpdateMaintenance)
                        <p class="text-xs text-gray-500">Changes take effect immediately after saving.</p>
                        <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm shrink-0">
                            Save Changes
                        </button>
                    @else
                        <p class="text-xs text-gray-500">Your role can view this setting but not change it.</p>
                    @endif
                </div>
            </form>
        @endif
    </x-admin.settings-shell>
@endsection

@push('scripts')
<script>
    /*
     | Show the picked file in the card before it is uploaded.
     |
     | Purely a preview. The input is a normal form field, so the upload works with
     | this script blocked; all it saves is submitting blind and finding out on the
     | next page whether the right file was chosen.
     */
    (function () {
        document.querySelectorAll('[data-branding-input]').forEach(function (input) {
            input.addEventListener('change', function () {
                const field = input.dataset.brandingInput;
                const file = input.files && input.files[0];

                if (!file) {
                    return;
                }

                const preview = document.querySelector('[data-branding-preview="' + field + '"]');
                const empty = document.querySelector('[data-branding-empty="' + field + '"]');
                const name = document.querySelector('[data-branding-filename="' + field + '"]');

                if (name) {
                    name.textContent = file.name;
                    name.classList.remove('hidden');
                }

                if (preview) {
                    // Released once drawn, so choosing several files in a row does not
                    // hold every one of them in memory.
                    const url = URL.createObjectURL(file);

                    preview.addEventListener('load', function () {
                        URL.revokeObjectURL(url);
                    }, { once: true });

                    preview.src = url;
                    preview.classList.remove('hidden');
                }

                if (empty) {
                    empty.classList.add('hidden');
                }
            });
        });
    })();
</script>
@endpush
