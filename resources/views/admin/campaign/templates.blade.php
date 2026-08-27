@extends('layouts.admin')

@section('title', 'Campaign Templates')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.campaigns.index') }}" class="hover:text-gray-700 transition">Campaign</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $intro['label'] }}</span>
@endsection

@section('content')
    @php
        use App\Support\EventTemplates;

        $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
        $isSms = $activeTab === EventTemplates::CHANNEL_SMS;
    @endphp

    <x-admin.settings-shell
        title="Campaign Templates"
        description="Wording you expect to reuse. Separate from the event templates, which cover fixed moments and never change in number."
        :tabs="$tabs"
        :active-tab="$activeTab"
        route="admin.campaigns.templates">

        <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
            <x-admin.section-intro
                :title="$intro['title']"
                :description="$intro['description']"
                :icon="$intro['icon']"
                :accent="$intro['accent']"
                class="mb-0" />

            {{-- One line, so the count matches the height of the button next to it. --}}
            <div class="flex flex-wrap items-center gap-3 shrink-0">
                <div class="inline-flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-3.5 py-2.5">
                    <span class="text-xs font-semibold uppercase tracking-wide text-green-700">Available</span>
                    <span class="text-sm font-bold text-green-900 tabular-nums">{{ $activeCount }}</span>
                </div>

                @if ($canCreate)
                    <a href="{{ route('admin.campaigns.templates.create', ['channel' => $activeTab]) }}"
                       class="inline-flex items-center gap-2 rounded-lg border border-blue-600 bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm">
                        <x-admin.icon name="plus" class="w-4 h-4" />
                        New Template
                    </a>
                @endif
            </div>
        </div>

        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <x-admin.filter-bar
                :action="route('admin.campaigns.templates')"
                :reset="$isFiltered ? route('admin.campaigns.templates', ['tab' => $activeTab]) : null">

                <input type="hidden" name="tab" value="{{ $activeTab }}">

                <div class="relative flex-1 min-w-56">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                        <x-admin.icon name="search" class="w-4 h-4" />
                    </span>
                    <label for="q" class="sr-only">Search templates</label>
                    <input type="search" id="q" name="q" value="{{ $search }}"
                           placeholder="Search by name..."
                           class="w-full rounded-lg border border-gray-300 pl-9 pr-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                </div>
            </x-admin.filter-bar>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left">
                        <tr>
                            <th scope="col" class="{{ $head }}">Name</th>
                            <th scope="col" class="{{ $head }}">Preview</th>
                            <th scope="col" class="{{ $head }} text-center">Active</th>
                            @if ($isSms)
                                <th scope="col" class="{{ $head }} text-right">Cost</th>
                            @endif
                            @if ($canUpdate || $canDelete)
                                <th scope="col" class="{{ $head }} text-center">Actions</th>
                            @endif
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        @forelse ($templates as $template)
                            <tr class="hover:bg-blue-50/40 align-top">
                                <td class="px-5 py-3">
                                    @if ($canUpdate)
                                        <a href="{{ route('admin.campaigns.templates.edit', $template) }}"
                                           class="font-semibold text-blue-600 hover:underline">{{ $template->name }}</a>
                                    @else
                                        <span class="font-semibold text-gray-900">{{ $template->name }}</span>
                                    @endif

                                    @if ($template->isEmail() && filled($template->subject))
                                        <span class="block text-xs text-gray-500 mt-0.5">{{ Str::limit($template->subject, 60) }}</span>
                                    @endif
                                </td>

                                <td class="px-5 py-3 text-xs text-gray-600">
                                    {{ Str::limit(str_replace("\n", ' ', $template->body), 90) }}
                                </td>

                                <td class="px-5 py-3 text-center">
                                    <x-admin.badge :tone="$template->is_active ? 'green' : 'gray'">
                                        {{ $template->is_active ? 'Active' : 'Off' }}
                                    </x-admin.badge>
                                </td>

                                @if ($isSms)
                                    <td class="px-5 py-3 text-right whitespace-nowrap">
                                        @php $segments = $template->smsSegments(); @endphp
                                        <span @class(['tabular-nums text-sm', 'text-amber-700 font-semibold' => $segments > 1, 'text-gray-600' => $segments === 1])>
                                            {{ $segments }} {{ Str::plural('segment', $segments) }}
                                        </span>
                                        <span class="block text-xs text-gray-400">{{ mb_strlen($template->body) }} chars</span>
                                    </td>
                                @endif

                                @if ($canUpdate || $canDelete)
                                    {{-- Icon buttons, matching the Roles and Users tables. The template
                                         name is carried in title and aria-label rather than on screen. --}}
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <div class="flex items-center justify-center gap-1">
                                            @if ($canUpdate)
                                                <a href="{{ route('admin.campaigns.templates.edit', $template) }}"
                                                   class="p-1.5 rounded-lg text-amber-600 hover:bg-amber-50 transition"
                                                   title="Edit {{ $template->name }}" aria-label="Edit {{ $template->name }}">
                                                    <x-admin.icon name="pencil" class="w-4 h-4" />
                                                </a>
                                            @endif

                                            @if ($canDelete)
                                                <form action="{{ route('admin.campaigns.templates.destroy', $template) }}" method="POST"
                                                      onsubmit="return confirm('Delete {{ addslashes($template->name) }}?\n\nCampaigns already sent keep their own copy of the wording.');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit"
                                                            class="p-1.5 rounded-lg text-red-600 hover:bg-red-50 transition"
                                                            title="Delete {{ $template->name }}" aria-label="Delete {{ $template->name }}">
                                                        <x-admin.icon name="trash" class="w-4 h-4" />
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-12 text-center">
                                    <x-admin.icon name="mail" class="w-10 h-10 mx-auto text-gray-300" />
                                    <p class="text-sm font-semibold text-gray-700 mt-3">
                                        {{ $isFiltered ? 'Nothing matches that search' : 'No templates yet' }}
                                    </p>
                                    <p class="text-sm text-gray-500 mt-1">
                                        Templates are optional. A campaign can be written straight into its
                                        own box.
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-3.5 border-t border-gray-200">
                <p class="text-xs text-gray-500">
                    {{ $templates->count() }} {{ Str::plural('template', $templates->count()) }}
                    @if ($isFiltered) matching that search @endif
                </p>
            </div>
        </div>
    </x-admin.settings-shell>
@endsection
