@extends('layouts.admin')

@section('title', $campaign->name)

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.campaigns.index') }}" class="hover:text-gray-700 transition">Campaign</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ Str::limit($campaign->name, 40) }}</span>
@endsection

@section('content')
    @php
        use App\Models\Campaign;
        use App\Models\CampaignRecipient;

        $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';

        $statusTones = [
            Campaign::STATUS_DRAFT => 'gray',
            Campaign::STATUS_SENDING => 'amber',
            Campaign::STATUS_SENT => 'green',
            Campaign::STATUS_CANCELLED => 'red',
        ];

        $rowTones = [
            CampaignRecipient::STATUS_QUEUED => 'gray',
            CampaignRecipient::STATUS_SENT => 'green',
            CampaignRecipient::STATUS_FAILED => 'red',
            CampaignRecipient::STATUS_SKIPPED => 'amber',
        ];
    @endphp

    <x-admin.page-card
        :title="$campaign->name"
        :description="$campaign->channelLabel() . ' · ' . $campaign->audienceLabel()"
        :back="route('admin.campaigns.index')">

        <x-slot:actions>
            <x-admin.badge :tone="$statusTones[$campaign->status] ?? 'gray'" dot>
                {{ $campaign->statusLabel() }}
            </x-admin.badge>

            @if ($campaign->isEditable() && $canUpdate)
                <a href="{{ route('admin.campaigns.edit', $campaign) }}"
                   class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                    Edit
                </a>
            @endif

            @unless ($campaign->isDraft())
                <a href="{{ route('admin.campaigns.reports.show', $campaign) }}"
                   class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                    Full Report
                </a>
            @endunless
        </x-slot:actions>

        @if ($errors->any())
            <div role="alert" class="bg-red-50 border border-red-200 rounded-lg p-4 mb-5">
                <ul class="text-sm text-red-800 space-y-0.5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- ================= Draft: who it will go to ================= --}}
        @if ($campaign->isDraft())
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
                <x-admin.money-card
                    label="Will receive"
                    :value="(string) $pending"
                    :note="Str::plural($campaign->isEmail() ? 'email' : 'text message', $pending)"
                    tone="green"
                    icon="users" />

                @unless ($campaign->isEmail())
                    {{-- The bill, before the button rather than after it. SMS is charged
                         per segment, so a body three characters over 160 doubles it. --}}
                    <x-admin.money-card
                        label="Billable messages"
                        :value="(string) ($pending * $smsSegments)"
                        :note="$pending . ' × ' . $smsSegments . ' ' . Str::plural('segment', $smsSegments)"
                        :tone="$smsSegments > 1 ? 'amber' : 'gray'"
                        icon="credit-card" />
                @endunless
            </div>

            {{-- Named, so the operator can check the list before it becomes
                 irreversible. Read back from the contacts rather than from what was
                 typed, so anybody who unsubscribed since is already gone. --}}
            @if ($pendingContacts->isNotEmpty())
                <div class="mb-5">
                    <x-admin.panel title="Chosen Recipients" icon="users" :flush="true">
                        <div class="max-h-80 overflow-y-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 text-left sticky top-0">
                                    <tr>
                                        <th scope="col" class="{{ $head }}">Name</th>
                                        <th scope="col" class="{{ $head }}">{{ $campaign->isEmail() ? 'Email' : 'Number' }}</th>
                                        <th scope="col" class="{{ $head }}">Consent</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($pendingContacts as $contact)
                                        <tr>
                                            <td class="px-5 py-2.5 text-gray-900">{{ $contact->name ?: '—' }}</td>
                                            <td class="px-5 py-2.5 text-gray-600 break-all">
                                                {{ $campaign->isEmail() ? $contact->email : $contact->phone }}
                                            </td>
                                            <td class="px-5 py-2.5">
                                                @if ($campaign->isEmail() ? $contact->consent_email : $contact->consent_sms)
                                                    <span class="rounded bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800">Agreed</span>
                                                @else
                                                    <span class="rounded bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600">Not on record</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-admin.panel>
                </div>
            @endif
        @else
            {{-- ================= Sent: what happened ================= --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-5">
                <x-admin.money-card label="Sent" :value="(string) $campaign->sent_count" tone="green" icon="send" />
                <x-admin.money-card label="Failed" :value="(string) $campaign->failed_count" :tone="$campaign->failed_count > 0 ? 'red' : 'gray'" />

                @if ($campaign->isEmail())
                    <x-admin.money-card label="Opened" :value="$campaign->opened_count . ' (' . ($campaign->openRate() ?? 0) . '%)'" tone="gray" />
                    <x-admin.money-card label="Clicked" :value="$campaign->clicked_count . ' (' . ($campaign->clickRate() ?? 0) . '%)'" tone="gray" />
                @else
                    <x-admin.money-card label="Opened" value="—" note="no tracking in SMS" tone="gray" />
                    <x-admin.money-card label="Clicked" value="—" note="no tracking in SMS" tone="gray" />
                @endif

                <x-admin.money-card label="Unsubscribed" :value="(string) $campaign->unsubscribed_count" :tone="$campaign->unsubscribed_count > 0 ? 'amber' : 'gray'" />
            </div>

            @if ($campaign->isEmail() && $campaign->sent_count > 0)
                <div role="note" class="flex items-start gap-3 bg-gray-50 border border-gray-200 rounded-lg p-3.5 mb-5">
                    <x-admin.icon name="lock" class="w-4 h-4 mt-0.5 shrink-0 text-gray-500" />
                    <p class="text-xs text-gray-600">
                        Opens are counted by loading a hidden image. Most mail clients block
                        images by default, and Apple Mail loads them whether or not anybody
                        looked, so treat this as a rough floor rather than a headcount. A click
                        is solid: somebody pressed it.
                    </p>
                </div>
            @endif
        @endif

        {{-- ================= The message ================= --}}
        <x-admin.panel title="The Message As It Will Read" icon="mail">
            <div class="px-5 py-4">
                @if ($campaign->isEmail() && filled($previewSubject))
                    <p class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">Subject</p>
                    <p class="text-sm font-semibold text-gray-900 mb-4">{{ $previewSubject }}</p>
                @endif

                <p class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">Body</p>
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700 whitespace-pre-wrap">{{ $preview }}</div>

                <p class="text-xs text-gray-500 mt-2">
                    Shown with sample values in place of the placeholders.
                    @if ($campaign->isEmail())
                        The unsubscribe line is added underneath automatically.
                    @endif
                </p>
            </div>
        </x-admin.panel>

        {{-- ================= Send ================= --}}
        @if ($campaign->isDraft() && $canSend)
            <div class="mt-5">
                <x-admin.panel title="Send" icon="send">
                    <div class="px-5 py-4">
                        <form action="{{ route('admin.campaigns.test', $campaign) }}" method="POST"
                              class="flex flex-wrap items-end gap-3 mb-5 pb-5 border-b border-gray-100">
                            @csrf
                            <div class="flex-1 min-w-56">
                                <label for="test_to" class="block text-xs font-semibold text-gray-700 mb-1">
                                    Test {{ $campaign->isEmail() ? 'address' : 'number' }}
                                </label>
                                <input type="text" id="test_to" name="test_to" required
                                       placeholder="{{ $campaign->isEmail() ? 'you@example.com' : '017-859 1411' }}"
                                       class="w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                                @error('test_to')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                            </div>
                            <button type="submit"
                                    class="rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                                Send Test
                            </button>
                        </form>

                        {{-- The button is always here, live or not. Hiding it when the
                             campaign cannot go left nothing on the screen to press and no
                             statement of why, which reads as a fault rather than a rule. --}}
                        @if ($blocker === null)
                            <form action="{{ route('admin.campaigns.send', $campaign) }}" method="POST"
                                  onsubmit="return confirm('Send to {{ $pending }} {{ $campaign->isEmail() ? 'people' : 'numbers' }}?\n\n{{ $campaign->isEmail() ? 'This cannot be recalled once it leaves.' : 'This costs ' . ($pending * $smsSegments) . ' billable messages and cannot be recalled.' }}');">
                                @csrf
                                <button type="submit"
                                        class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-6 py-3 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm">
                                    <x-admin.icon name="send" class="w-4 h-4" />
                                    Send To {{ $pending }} {{ Str::plural('Recipient', $pending) }}
                                </button>
                            </form>

                            <p class="text-xs text-gray-500 mt-2">Nothing can be recalled once it has left.</p>
                        @else
                            <button type="button" disabled aria-describedby="send-blocked"
                                    class="inline-flex items-center gap-2 rounded-lg bg-gray-200 px-6 py-3 text-sm font-semibold text-gray-500 cursor-not-allowed">
                                <x-admin.icon name="send" class="w-4 h-4" />
                                Send
                            </button>

                            <p id="send-blocked" class="text-sm text-amber-800 mt-2.5">
                                {{ $blocker }}
                                @if ($canUpdate)
                                    <a href="{{ route('admin.campaigns.edit', $campaign) }}" class="underline font-semibold">Edit this campaign</a>
                                    to choose recipients.
                                @endif
                            </p>

                            @unless ($delivery['ready'])
                                <p class="text-sm mt-1.5">
                                    <a href="{{ $delivery['settingsRoute'] }}" class="underline font-semibold text-amber-800">Open the settings</a>
                                    to configure it.
                                </p>
                            @endunless
                        @endif
                    </div>
                </x-admin.panel>
            </div>
        @endif

        {{-- ================= Recipients ================= --}}
        @if ($recipients !== null)
            <div class="mt-5">
                <x-admin.panel title="Recipients" icon="users" :flush="true">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="{{ $head }}">Who</th>
                                <th scope="col" class="{{ $head }}">Status</th>
                                @if ($campaign->isEmail())
                                    <th scope="col" class="{{ $head }} text-right">Opens</th>
                                    <th scope="col" class="{{ $head }} text-right">Clicks</th>
                                @endif
                                <th scope="col" class="{{ $head }}">Sent</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($recipients as $recipient)
                                <tr class="hover:bg-gray-50 align-top">
                                    <td class="px-5 py-3">
                                        <span class="block text-gray-900">
                                            {{ $recipient->contact?->name ?: '—' }}
                                            {{-- Marked from the column rather than the reason text, which the
                                                 send job clears as soon as a test succeeds. --}}
                                            @if ($recipient->is_test)
                                                <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-xs font-semibold text-gray-600">Test</span>
                                            @endif
                                        </span>
                                        <span class="block text-xs text-gray-500 break-all">{{ $recipient->address }}</span>
                                        @if (filled($recipient->reason))
                                            <span class="block text-xs text-amber-700 mt-0.5">{{ $recipient->reason }}</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <x-admin.badge :tone="$rowTones[$recipient->status] ?? 'gray'">
                                            {{ $recipient->statusLabel() }}
                                        </x-admin.badge>
                                    </td>
                                    @if ($campaign->isEmail())
                                        <td class="px-5 py-3 text-right tabular-nums text-gray-700">{{ $recipient->open_count }}</td>
                                        <td class="px-5 py-3 text-right tabular-nums text-gray-700">{{ $recipient->click_count }}</td>
                                    @endif
                                    <td class="px-5 py-3 whitespace-nowrap text-xs text-gray-500">
                                        {{ $recipient->sent_at?->format('d M, g:i a') ?? '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="px-5 py-3.5 border-t border-gray-200">
                        {{ $recipients->links() }}
                    </div>
                </x-admin.panel>
            </div>
        @endif

        {{-- ================= Delete ================= --}}
        @if ($campaign->isDraft() && $canDelete)
            <form action="{{ route('admin.campaigns.destroy', $campaign) }}" method="POST" class="mt-5"
                  onsubmit="return confirm('Delete the draft {{ addslashes($campaign->name) }}?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="text-sm font-semibold text-red-600 hover:underline">
                    Delete this draft
                </button>
            </form>
        @endif
    </x-admin.page-card>
@endsection
