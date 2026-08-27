@extends('layouts.admin')

@section('title', 'Campaigns')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Campaign</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $intro['label'] }}</span>
@endsection

@section('content')
    @php
        use App\Models\Campaign;

        $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
        $filterInput = 'rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';

        $statusTones = [
            Campaign::STATUS_DRAFT => 'gray',
            Campaign::STATUS_SENDING => 'amber',
            Campaign::STATUS_SENT => 'green',
            Campaign::STATUS_CANCELLED => 'red',
        ];
    @endphp

    <x-admin.settings-shell
        title="Campaigns"
        description="Reach people who agreed to hear from you, about news and future events."
        :tabs="$tabs"
        :active-tab="$activeTab"
        route="admin.campaigns.index">

        <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
            <x-admin.section-intro
                :title="$intro['title']"
                :description="$intro['description']"
                :icon="$intro['icon']"
                :accent="$intro['accent']"
                class="mb-0" />

            {{-- Totals across both channels, so switching tabs does not make the
                 pair look as though it contradicts itself.

                 Label and figure sit on one line rather than stacked, so these end
                 up the same height as the button beside them. The button carries a
                 transparent border for the same reason: without it the two borders
                 on the pills would leave it two pixels shorter. --}}
            <div class="flex flex-wrap items-center gap-3 shrink-0">
                <div class="inline-flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-3.5 py-2.5">
                    <span class="text-xs font-semibold uppercase tracking-wide text-green-700">Delivered</span>
                    <span class="text-sm font-bold text-green-900 tabular-nums">{{ number_format($totals['sent']) }}</span>
                </div>
                <div class="inline-flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-2.5">
                    <span class="text-xs font-semibold uppercase tracking-wide text-amber-700">Unsubscribed</span>
                    <span class="text-sm font-bold text-amber-900 tabular-nums">{{ number_format($totals['unsubscribed']) }}</span>
                </div>

                @if ($canUpdate)
                    <a href="{{ route('admin.campaigns.create', ['channel' => $activeTab]) }}"
                       class="inline-flex items-center gap-2 rounded-lg border border-blue-600 bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm">
                        <x-admin.icon name="plus" class="w-4 h-4" />
                        New Campaign
                    </a>
                @endif
            </div>
        </div>

        {{-- Only the failure. A channel that works needs no announcing; a channel
             that cannot deliver is the only state worth taking up the space. --}}
        @unless ($delivery['ready'])
            <div class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 mb-5">
                <x-admin.icon name="lock" class="w-5 h-5 mt-0.5 shrink-0 text-amber-600" />
                <p class="text-sm text-amber-800">
                    {{ $delivery['summary'] }}
                    <a href="{{ $delivery['settingsRoute'] }}" class="underline font-semibold">Open the settings</a>.
                </p>
            </div>
        @endunless

        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <x-admin.filter-bar
                :action="route('admin.campaigns.index')"
                :reset="$isFiltered ? route('admin.campaigns.index', ['tab' => $activeTab]) : null">

                <input type="hidden" name="tab" value="{{ $activeTab }}">

                <div class="relative flex-1 min-w-56">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                        <x-admin.icon name="search" class="w-4 h-4" />
                    </span>
                    <label for="q" class="sr-only">Search campaigns</label>
                    <input type="search" id="q" name="q" value="{{ $filters['search'] }}"
                           placeholder="Search by name or subject..."
                           class="w-full rounded-lg border border-gray-300 pl-9 pr-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                </div>

                <label for="status" class="sr-only">Status</label>
                <select id="status" name="status" class="{{ $filterInput }}">
                    <option value="">All Statuses</option>
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </x-admin.filter-bar>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left">
                        <tr>
                            <th scope="col" class="{{ $head }}">Campaign</th>
                            <th scope="col" class="{{ $head }}">Audience</th>
                            <th scope="col" class="{{ $head }}">Status</th>
                            <th scope="col" class="{{ $head }} text-right">Sent</th>
                            <th scope="col" class="{{ $head }} text-right">Opened</th>
                            <th scope="col" class="{{ $head }} text-right">Clicked</th>
                            <th scope="col" class="{{ $head }}">When</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        @forelse ($campaigns as $campaign)
                            <tr class="hover:bg-blue-50/40 align-top">
                                <td class="px-5 py-3">
                                    <a href="{{ route('admin.campaigns.show', $campaign) }}"
                                       class="font-semibold text-blue-600 hover:underline">{{ $campaign->name }}</a>
                                    @if ($campaign->isEmail() && filled($campaign->subject))
                                        <span class="block text-xs text-gray-500 mt-0.5">{{ Str::limit($campaign->subject, 60) }}</span>
                                    @endif
                                    <span class="block text-xs text-gray-400 mt-0.5">
                                        by {{ $campaign->creator?->name ?? 'a removed account' }}
                                    </span>
                                </td>

                                <td class="px-5 py-3 text-gray-700">{{ $campaign->audienceLabel() }}</td>

                                <td class="px-5 py-3 whitespace-nowrap">
                                    <x-admin.badge :tone="$statusTones[$campaign->status] ?? 'gray'" dot>
                                        {{ $campaign->statusLabel() }}
                                    </x-admin.badge>
                                </td>

                                <td class="px-5 py-3 text-right tabular-nums text-gray-900">
                                    {{ $campaign->sent_count }}
                                    @if ($campaign->failed_count > 0)
                                        <span class="block text-xs text-red-600">{{ $campaign->failed_count }} failed</span>
                                    @endif
                                </td>

                                <td class="px-5 py-3 text-right tabular-nums text-gray-700">
                                    @if ($campaign->isEmail() && $campaign->sent_count > 0)
                                        {{ $campaign->opened_count }}
                                        <span class="block text-xs text-gray-400">{{ $campaign->openRate() }}%</span>
                                    @else
                                        <span class="text-xs text-gray-300">—</span>
                                    @endif
                                </td>

                                <td class="px-5 py-3 text-right tabular-nums text-gray-700">
                                    @if ($campaign->isEmail() && $campaign->sent_count > 0)
                                        {{ $campaign->clicked_count }}
                                        <span class="block text-xs text-gray-400">{{ $campaign->clickRate() }}%</span>
                                    @else
                                        <span class="text-xs text-gray-300">—</span>
                                    @endif
                                </td>

                                <td class="px-5 py-3 whitespace-nowrap text-xs text-gray-500">
                                    {{ ($campaign->started_at ?? $campaign->created_at)?->format('d M Y, g:i a') }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-12 text-center">
                                    <x-admin.icon name="send" class="w-10 h-10 mx-auto text-gray-300" />
                                    <p class="text-sm font-semibold text-gray-700 mt-3">
                                        {{ $isFiltered ? 'Nothing matches these filters' : 'No ' . strtolower($intro['label']) . 's yet' }}
                                    </p>
                                    <p class="text-sm text-gray-500 mt-1">
                                        @if ($isFiltered)
                                            Try a different search, or clear the filters.
                                        @else
                                            Check the Audiences screen first to see how many people can be reached.
                                        @endif
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-3.5 border-t border-gray-200">
                @if ($campaigns->hasPages())
                    {{ $campaigns->links() }}
                @else
                    <p class="text-xs text-gray-500">
                        {{ $campaigns->total() }} {{ Str::plural('campaign', $campaigns->total()) }}
                        @if ($isFiltered) matching these filters @endif
                    </p>
                @endif
            </div>
        </div>
    </x-admin.settings-shell>
@endsection
