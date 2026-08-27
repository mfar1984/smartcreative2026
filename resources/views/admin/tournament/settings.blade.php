@extends('layouts.admin')

@section('title', 'Tournament Settings')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Tournament</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $intro['label'] }}</span>
@endsection

@section('content')
    @php
        $input = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
    @endphp

    <x-admin.settings-shell
        title="Tournament Settings"
        description="How the day is run, shared by every tournament. What a result is worth lives in Point Rules."
        :tabs="$tabs"
        :active-tab="$activeTab"
        :route="$route">

        @if (session('status'))
            <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-5">
                <p class="text-sm text-green-800">{{ session('status') }}</p>
            </div>
        @endif

        @if ($errors->any())
            <div role="alert" class="bg-red-50 border border-red-200 rounded-lg p-4 mb-5">
                <ul class="text-sm text-red-800 space-y-0.5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <x-admin.section-intro
            :title="$intro['title']"
            :description="$intro['description']"
            :icon="$intro['icon']"
            :accent="$intro['accent']" />

        <form action="{{ route('admin.tournaments.settings.update', $activeTab) }}" method="POST">
            @csrf
            @method('PUT')

            {{-- ==================== Match Day ==================== --}}
            @if ($activeTab === 'match')
                <x-admin.panel title="Running The Day" icon="clipboard">
                    <x-admin.field-row label="Buffer between matches" help="Used to space the fixtures when a draw is generated. Every time can be changed afterwards." for="buffer_minutes" :required="true" error="buffer_minutes">
                        <div class="flex items-center gap-2">
                            <input type="number" id="buffer_minutes" name="buffer_minutes" min="5" max="180" required
                                   value="{{ old('buffer_minutes', $values['buffer_minutes']) }}"
                                   @disabled(! $canUpdate)
                                   class="{{ $input }} max-w-28">
                            <span class="text-sm text-gray-600">minutes</span>
                        </div>
                    </x-admin.field-row>

                    <x-admin.field-row label="Forfeit after" help="How late a team may be before the match is given to the other side." for="lateness_minutes" :required="true" error="lateness_minutes">
                        <div class="flex items-center gap-2">
                            <input type="number" id="lateness_minutes" name="lateness_minutes" min="0" max="120" required
                                   value="{{ old('lateness_minutes', $values['lateness_minutes']) }}"
                                   @disabled(! $canUpdate)
                                   class="{{ $input }} max-w-28">
                            <span class="text-sm text-gray-600">minutes late</span>
                        </div>
                    </x-admin.field-row>

                    <x-admin.field-row label="Result screenshot" help="When required, a fixture cannot be closed without one. It is the only evidence if a score is disputed.">
                        <label class="inline-flex items-center gap-2 md:pt-2 cursor-pointer select-none">
                            <input type="hidden" name="require_proof" value="0">
                            <input type="checkbox" name="require_proof" value="1"
                                   @checked(old('require_proof', $values['require_proof']) == '1')
                                   @disabled(! $canUpdate)
                                   class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                            <span class="text-sm text-gray-700">Require a screenshot before closing a fixture</span>
                        </label>
                    </x-admin.field-row>

                    <x-admin.field-row label="Best-of per round" help="Suggested when a bracket stage is added. Single games early to save time, longer series later." for="default_best_of" error="default_best_of">
                        <input type="text" id="default_best_of" name="default_best_of" maxlength="40"
                               value="{{ old('default_best_of', $values['default_best_of']) }}"
                               placeholder="1,1,3,3,5"
                               @disabled(! $canUpdate)
                               class="{{ $input }} max-w-64">
                        <p class="text-xs text-gray-500 mt-1.5">
                            One number per round, separated by commas. Round one first.
                        </p>
                    </x-admin.field-row>
                </x-admin.panel>
            @endif

            {{-- ==================== Maps ==================== --}}
            @if ($activeTab === 'maps')
                <x-admin.panel title="Map Pools" icon="globe">
                    <x-admin.field-row label="Maps available" help="One per line. These are the maps a battle royale stage may draw from." for="map_pool" error="map_pool">
                        <textarea id="map_pool" name="map_pool" rows="6"
                                  @disabled(! $canUpdate)
                                  class="{{ $input }} resize-y font-mono text-xs">{{ old('map_pool', $values['map_pool']) }}</textarea>
                    </x-admin.field-row>

                    <x-admin.field-row label="Rotation" help="The order maps are given out when a stage is generated. It cycles, so three matches take the first three." for="map_rotation" error="map_rotation">
                        <input type="text" id="map_rotation" name="map_rotation" maxlength="500"
                               value="{{ old('map_rotation', $values['map_rotation']) }}"
                               placeholder="Erangel, Miramar, Erangel, Sanhok, Miramar"
                               @disabled(! $canUpdate)
                               class="{{ $input }}">
                        <p class="text-xs text-gray-500 mt-1.5">
                            Separated by commas. Playing one map five times is the thing this avoids.
                        </p>
                    </x-admin.field-row>
                </x-admin.panel>
            @endif

            {{-- ==================== Public Display ==================== --}}
            @if ($activeTab === 'display')
                <x-admin.panel title="What The Website Shows" icon="trophy">
                    <x-admin.field-row label="Live rankings" help="When off, nothing appears on the public site until the podium is published.">
                        <label class="inline-flex items-center gap-2 md:pt-2 cursor-pointer select-none">
                            <input type="hidden" name="public_rankings_live" value="0">
                            <input type="checkbox" name="public_rankings_live" value="1"
                                   @checked(old('public_rankings_live', $values['public_rankings_live']) == '1')
                                   @disabled(! $canUpdate)
                                   class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                            <span class="text-sm text-gray-700">Show standings while a tournament is still being played</span>
                        </label>
                        <p class="text-xs text-gray-500 mt-1.5">
                            The public page says how many matches of how many are done, so a
                            half-played table is not taken for a final result.
                        </p>
                    </x-admin.field-row>

                    <x-admin.field-row label="Devices" help="Recorded on each tournament and shown in its rules." for="device_rule" :required="true" error="device_rule">
                        <select id="device_rule" name="device_rule" required @disabled(! $canUpdate)
                                class="{{ $input }} bg-white max-w-sm">
                            @foreach ($deviceRules as $value => $label)
                                <option value="{{ $value }}" @selected(old('device_rule', $values['device_rule']) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-admin.field-row>

                    <x-admin.field-row label="Public pages" help="Where these figures end up.">
                        <div class="md:pt-2 space-y-1">
                            <p class="text-sm">
                                <a href="{{ url('/hall-of-fame') }}" target="_blank" rel="noopener"
                                   class="underline font-semibold text-blue-600">/hall-of-fame</a>
                                <span class="text-gray-500">— frozen champions, unaffected by later corrections</span>
                            </p>
                            <p class="text-sm text-gray-500">
                                <code class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs text-gray-800">/events/&#123;slug&#125;/ranking</code>
                                — live standings for one event
                            </p>
                        </div>
                    </x-admin.field-row>
                </x-admin.panel>
            @endif

            @if ($canUpdate)
                <div class="flex flex-wrap items-center justify-between gap-4 bg-white rounded-lg border border-gray-200 px-5 py-4 mt-5">
                    <p class="text-xs text-gray-500 max-w-md">
                        A new tournament copies these when it is created. Tournaments already under
                        way keep the rules they started with.
                    </p>
                    <button type="submit"
                            class="rounded-lg border border-blue-600 bg-blue-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm shrink-0">
                        Save Changes
                    </button>
                </div>
            @else
                <p class="text-xs text-gray-500 mt-4">
                    Your role can view these settings but not change them.
                </p>
            @endif
        </form>
    </x-admin.settings-shell>
@endsection
