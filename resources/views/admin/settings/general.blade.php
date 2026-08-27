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
                <div role="alert" class="flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-lg p-4 mb-5">
                    <svg class="w-5 h-5 shrink-0 text-amber-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <div class="text-sm text-amber-800">
                        <p class="font-semibold mb-1">Backup and restore actions are not enabled yet.</p>
                        <p>
                            A restore overwrites live data and cannot be undone, so it needs an agreed
                            strategy before being switched on. This tab currently shows the database
                            status and any backup files already on disk.
                        </p>
                    </div>
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

                <x-admin.panel title="Backup Files" icon="archive" :flush="true">
                    @if (count($backup['files']) === 0)
                        <p class="px-5 py-10 text-sm text-gray-500 text-center">
                            No backup files found in <code class="text-xs">{{ $backup['path'] }}</code>.
                        </p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 text-left">
                                    <tr>
                                        <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">File</th>
                                        <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Size</th>
                                        <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Created</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($backup['files'] as $file)
                                        <tr>
                                            <td class="px-5 py-3 text-gray-900 break-all">{{ $file['name'] }}</td>
                                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap">{{ number_format($file['size'] / 1024, 1) }} KB</td>
                                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap">{{ \Illuminate\Support\Carbon::createFromTimestamp($file['modified'])->format('d M Y, g:i a') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
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
