@extends('layouts.admin')

@section('title', 'Report: ' . $campaign->name)

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.campaigns.reports') }}" class="hover:text-gray-700 transition">Reports</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ Str::limit($campaign->name, 40) }}</span>
@endsection

@section('content')
    @php
        use App\Models\CampaignRecipient;

        $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';

        $rowTones = [
            CampaignRecipient::STATUS_QUEUED => 'gray',
            CampaignRecipient::STATUS_SENT => 'green',
            CampaignRecipient::STATUS_FAILED => 'red',
            CampaignRecipient::STATUS_SKIPPED => 'amber',
        ];
    @endphp

    <x-admin.page-card
        :title="$campaign->name"
        :description="$campaign->channelLabel() . ' · ' . $campaign->audienceLabel() . ' · sent ' . ($campaign->started_at?->format('d M Y, g:i a') ?? 'unknown')"
        :back="route('admin.campaigns.reports')">

        <x-slot:actions>
            @if ($canExport)
                <a href="{{ route('admin.campaigns.reports.export', $campaign) }}"
                   class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                    <x-admin.icon name="archive" class="w-4 h-4" />
                    Export CSV
                </a>
            @endif

            <a href="{{ route('admin.campaigns.show', $campaign) }}"
               class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                The Message
            </a>
        </x-slot:actions>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-5">
            <x-admin.money-card label="Sent" :value="number_format($campaign->sent_count)" :note="'of ' . $campaign->recipients_total" tone="green" />
            <x-admin.money-card label="Failed" :value="number_format($campaign->failed_count)" :tone="$campaign->failed_count > 0 ? 'red' : 'gray'" />

            @if ($campaign->isEmail())
                <x-admin.money-card label="Opened" :value="number_format($campaign->opened_count)" :note="($campaign->openRate() ?? 0) . '% approximate'" tone="gray" />
                <x-admin.money-card label="Clicked" :value="number_format($campaign->clicked_count)" :note="($campaign->clickRate() ?? 0) . '% of sent'" tone="gray" />
            @else
                <x-admin.money-card label="Opened" value="—" note="not measurable in SMS" tone="gray" />
                <x-admin.money-card label="Clicked" value="—" note="not measurable in SMS" tone="gray" />
            @endif

            <x-admin.money-card
                label="Unsubscribed"
                :value="number_format($campaign->unsubscribed_count)"
                note="asked to stop"
                :tone="$campaign->unsubscribed_count > 0 ? 'amber' : 'gray'" />
        </div>

        @if ($campaign->isEmail() && $campaign->clickThroughRate() !== null)
            <div class="flex items-start gap-3 bg-blue-50 border border-blue-200 rounded-lg p-4 mb-5">
                <x-admin.icon name="activity" class="w-5 h-5 mt-0.5 shrink-0 text-blue-600" />
                <p class="text-sm text-blue-800">
                    <strong>{{ $campaign->clickThroughRate() }}%</strong> of the people who opened it
                    pressed something. That is the figure worth watching: it says whether the message
                    persuaded the people who actually read it, and unlike the open count it cannot be
                    inflated by a mail client loading images on its own.
                </p>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            {{-- What people pressed --}}
            <x-admin.panel title="Links" icon="globe" :flush="true">
                @if ($links->isEmpty())
                    <p class="px-5 py-8 text-sm text-gray-500 text-center">
                        The message carried no links, so there was nothing to press.
                    </p>
                @else
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="{{ $head }}">Destination</th>
                                <th scope="col" class="{{ $head }} text-right">Presses</th>
                                <th scope="col" class="{{ $head }} text-right">People</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($links as $link)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3">
                                        <span class="block text-xs text-gray-700 break-all">{{ $link->shortUrl(70) }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-right tabular-nums font-semibold text-gray-900">{{ $link->clicks_count }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums text-gray-600">{{ $link->unique_clicks_count }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="px-5 py-3 border-t border-gray-100 bg-gray-50">
                        <p class="text-xs text-gray-500">
                            Presses counts every press; People counts how many different recipients
                            made them. A gap between the two means somebody went back more than once.
                        </p>
                    </div>
                @endif
            </x-admin.panel>

            {{-- Where everybody ended up --}}
            <x-admin.panel title="Outcome" icon="clipboard" :flush="true">
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($statuses as $value => $label)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3">
                                    <x-admin.badge :tone="$rowTones[$value] ?? 'gray'">{{ $label }}</x-admin.badge>
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums font-semibold text-gray-900">
                                    {{ $statusCounts[$value] ?? 0 }}
                                </td>
                                <td class="px-5 py-3 text-right w-24">
                                    @if (($statusCounts[$value] ?? 0) > 0)
                                        <a href="{{ route('admin.campaigns.reports.show', [$campaign, 'status' => $value]) }}"
                                           class="text-xs font-semibold text-blue-600 hover:underline">View</a>
                                    @else
                                        <span class="text-xs text-gray-300">None</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-admin.panel>
        </div>

        {{-- Recipient by recipient --}}
        <div class="mt-5">
            <x-admin.panel :title="$filterStatus ? 'Recipients: ' . ($statuses[$filterStatus] ?? $filterStatus) : 'Every Recipient'" icon="users" :flush="true">
                @if ($filterStatus)
                    <div class="px-5 py-2.5 border-b border-gray-200 bg-gray-50">
                        <a href="{{ route('admin.campaigns.reports.show', $campaign) }}"
                           class="text-xs font-semibold text-blue-600 hover:underline">Show everybody</a>
                    </div>
                @endif

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="{{ $head }}">Who</th>
                                <th scope="col" class="{{ $head }}">Status</th>
                                @if ($campaign->isEmail())
                                    <th scope="col" class="{{ $head }}">First Opened</th>
                                    <th scope="col" class="{{ $head }} text-right">Opens</th>
                                    <th scope="col" class="{{ $head }} text-right">Clicks</th>
                                @else
                                    <th scope="col" class="{{ $head }}">Reached the handset</th>
                                @endif
                                <th scope="col" class="{{ $head }}">Sent</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            @forelse ($recipients as $recipient)
                                <tr @class(['hover:bg-gray-50 align-top', 'bg-amber-50/40' => $recipient->unsubscribed_at !== null])>
                                    <td class="px-5 py-3">
                                        <span class="block text-gray-900">{{ $recipient->contact?->name ?: '—' }}</span>
                                        <span class="block text-xs text-gray-500 break-all">{{ $recipient->address }}</span>
                                        @if ($recipient->unsubscribed_at)
                                            <span class="block text-xs text-amber-700 mt-0.5">Unsubscribed from this message</span>
                                        @endif
                                        @if (filled($recipient->reason))
                                            <span class="block text-xs text-gray-500 mt-0.5">{{ $recipient->reason }}</span>
                                        @endif
                                    </td>

                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <x-admin.badge :tone="$rowTones[$recipient->status] ?? 'gray'">
                                            {{ $recipient->statusLabel() }}
                                        </x-admin.badge>
                                    </td>

                                    @if ($campaign->isEmail())
                                        <td class="px-5 py-3 whitespace-nowrap text-xs text-gray-500">
                                            {{ $recipient->opened_at?->format('d M, g:i a') ?? 'Not recorded' }}
                                        </td>
                                        <td class="px-5 py-3 text-right tabular-nums text-gray-700">{{ $recipient->open_count }}</td>
                                        <td class="px-5 py-3 text-right tabular-nums {{ $recipient->click_count > 0 ? 'text-blue-700 font-semibold' : 'text-gray-400' }}">
                                            {{ $recipient->click_count }}
                                        </td>
                                    @else
                                        {{-- Handed over and arrived are different facts, so both
                                             are shown rather than one standing in for the other. --}}
                                        <td class="px-5 py-3 whitespace-nowrap">
                                            @if ($recipient->wasDelivered())
                                                <x-admin.badge tone="green" dot>{{ $recipient->deliveryLabel() }}</x-admin.badge>
                                                <span class="block text-xs text-gray-400 mt-0.5">
                                                    {{ $recipient->delivered_at->format('d M, g:i a') }}
                                                </span>
                                            @elseif ($recipient->delivery_status !== null)
                                                <x-admin.badge tone="red">{{ $recipient->deliveryLabel() }}</x-admin.badge>
                                                @if (filled($recipient->delivery_detail))
                                                    <span class="block text-xs text-gray-500 mt-0.5">{{ $recipient->delivery_detail }}</span>
                                                @endif
                                            @else
                                                <span class="text-xs text-gray-400">{{ $recipient->deliveryLabel() }}</span>
                                            @endif
                                        </td>
                                    @endif

                                    <td class="px-5 py-3 whitespace-nowrap text-xs text-gray-500">
                                        {{ $recipient->sent_at?->format('d M, g:i a') ?? '—' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-10 text-center text-sm text-gray-500">
                                        Nothing matches.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="px-5 py-3.5 border-t border-gray-200">
                    @if ($recipients->hasPages())
                        {{ $recipients->links() }}
                    @else
                        <p class="text-xs text-gray-500">{{ $recipients->count() }} shown</p>
                    @endif
                </div>
            </x-admin.panel>
        </div>
    </x-admin.page-card>
@endsection
