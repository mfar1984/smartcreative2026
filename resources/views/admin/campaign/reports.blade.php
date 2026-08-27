@extends('layouts.admin')

@section('title', 'Campaign Reports')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.campaigns.index') }}" class="hover:text-gray-700 transition">Campaign</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Reports</span>
@endsection

@section('content')
    @php
        $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';

        $openRate = $emailSent > 0 ? round($totals['opened'] / $emailSent * 100, 1) : null;
        $clickRate = $emailSent > 0 ? round($totals['clicked'] / $emailSent * 100, 1) : null;
    @endphp

    <x-admin.page-card
        title="Campaign Reports"
        description="What every campaign achieved, across both channels.">

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-5">
            <x-admin.money-card label="Campaigns" :value="number_format($totals['campaigns'])" tone="gray" icon="send" />
            <x-admin.money-card label="Delivered" :value="number_format($totals['sent'])" :note="$totals['failed'] . ' failed'" tone="green" />
            <x-admin.money-card
                label="Opened"
                :value="$openRate === null ? '—' : number_format($totals['opened']) . ' (' . $openRate . '%)'"
                note="email only, approximate"
                tone="gray" />
            <x-admin.money-card
                label="Clicked"
                :value="$clickRate === null ? '—' : number_format($totals['clicked']) . ' (' . $clickRate . '%)'"
                note="email only, reliable"
                tone="gray" />
            <x-admin.money-card
                label="Unsubscribed"
                :value="number_format($totals['unsubscribed'])"
                note="the cost in goodwill"
                :tone="$totals['unsubscribed'] > 0 ? 'amber' : 'gray'" />
        </div>

        <x-admin.panel title="Every Campaign" icon="database" :flush="true">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left">
                        <tr>
                            <th scope="col" class="{{ $head }}">Campaign</th>
                            <th scope="col" class="{{ $head }}">Channel</th>
                            <th scope="col" class="{{ $head }}">Audience</th>
                            <th scope="col" class="{{ $head }} text-right">Sent</th>
                            <th scope="col" class="{{ $head }} text-right">Opened</th>
                            <th scope="col" class="{{ $head }} text-right">Clicked</th>
                            <th scope="col" class="{{ $head }} text-right">Left</th>
                            <th scope="col" class="{{ $head }}">When</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        @forelse ($campaigns as $campaign)
                            <tr class="hover:bg-blue-50/40 align-top">
                                <td class="px-5 py-3">
                                    <a href="{{ route('admin.campaigns.reports.show', $campaign) }}"
                                       class="font-semibold text-blue-600 hover:underline">{{ $campaign->name }}</a>
                                    <span class="block text-xs text-gray-400 mt-0.5">
                                        by {{ $campaign->creator?->name ?? 'a removed account' }}
                                    </span>
                                </td>

                                <td class="px-5 py-3">
                                    <x-admin.badge :tone="$campaign->isEmail() ? 'blue' : 'amber'">
                                        {{ $campaign->channelLabel() }}
                                    </x-admin.badge>
                                </td>

                                <td class="px-5 py-3 text-gray-700">{{ $campaign->audienceLabel() }}</td>

                                <td class="px-5 py-3 text-right tabular-nums text-gray-900">
                                    {{ $campaign->sent_count }}
                                    @if ($campaign->failed_count > 0)
                                        <span class="block text-xs text-red-600">{{ $campaign->failed_count }} failed</span>
                                    @endif
                                </td>

                                <td class="px-5 py-3 text-right tabular-nums">
                                    @if ($campaign->isEmail())
                                        <span class="text-gray-900">{{ $campaign->opened_count }}</span>
                                        <span class="block text-xs text-gray-400">{{ $campaign->openRate() ?? 0 }}%</span>
                                    @else
                                        <span class="text-xs text-gray-300">—</span>
                                    @endif
                                </td>

                                <td class="px-5 py-3 text-right tabular-nums">
                                    @if ($campaign->isEmail())
                                        <span class="text-gray-900">{{ $campaign->clicked_count }}</span>
                                        <span class="block text-xs text-gray-400">{{ $campaign->clickRate() ?? 0 }}%</span>
                                    @else
                                        <span class="text-xs text-gray-300">—</span>
                                    @endif
                                </td>

                                <td class="px-5 py-3 text-right tabular-nums {{ $campaign->unsubscribed_count > 0 ? 'text-amber-700 font-semibold' : 'text-gray-400' }}">
                                    {{ $campaign->unsubscribed_count }}
                                </td>

                                <td class="px-5 py-3 whitespace-nowrap text-xs text-gray-500">
                                    {{ $campaign->started_at?->format('d M Y, g:i a') ?? '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-5 py-12 text-center">
                                    <x-admin.icon name="activity" class="w-10 h-10 mx-auto text-gray-300" />
                                    <p class="text-sm font-semibold text-gray-700 mt-3">Nothing has been sent yet</p>
                                    <p class="text-sm text-gray-500 mt-1">A campaign appears here once it has been sent.</p>
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
                    <p class="text-xs text-gray-500">{{ $campaigns->count() }} shown</p>
                @endif
            </div>
        </x-admin.panel>
    </x-admin.page-card>
@endsection
