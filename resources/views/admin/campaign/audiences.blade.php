@extends('layouts.admin')

@section('title', 'Audiences')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.campaigns.index') }}" class="hover:text-gray-700 transition">Campaign</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Audiences</span>
@endsection

@section('content')
    @php
        use App\Support\EventTemplates;

        $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
        $isSms = $channel === EventTemplates::CHANNEL_SMS;
        $filterInput = 'rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
    @endphp

    <x-admin.page-card
        title="Audiences"
        description="Everyone the system knows about, and how many of them may actually be contacted.">

        <x-slot:actions>
            @if ($canExport)
                <a href="{{ route('admin.campaigns.audiences.export') }}"
                   class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                    <x-admin.icon name="archive" class="w-4 h-4" />
                    Export CSV
                </a>
            @endif

            @if ($canUpdate)
                <form action="{{ route('admin.campaigns.audiences.rebuild') }}" method="POST">
                    @csrf
                    <button type="submit"
                            class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                        Rebuild List
                    </button>
                </form>
            @endif
        </x-slot:actions>

        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-5">
            <x-admin.money-card label="On the list" :value="number_format($totals['contacts'])" note="people, deduplicated" tone="gray" icon="users" />
            <x-admin.money-card label="Email reachable" :value="number_format($totals['reachable_email'])" note="agreed and addressable" tone="green" icon="mail" />
            <x-admin.money-card label="SMS reachable" :value="number_format($totals['reachable_sms'])" note="agreed with a usable number" tone="green" icon="mobile" />
            <x-admin.money-card label="Suppressed" :value="number_format($totals['suppressed'])" note="never send again" tone="red" :href="route('admin.campaigns.suppression')" />
        </div>

        <div class="flex flex-wrap gap-1 mb-5">
            @foreach ([EventTemplates::CHANNEL_EMAIL => 'Email', EventTemplates::CHANNEL_SMS => 'SMS'] as $value => $label)
                <a href="{{ route('admin.campaigns.audiences', ['channel' => $value] + request()->only(['q', 'state'])) }}"
                   @class([
                       'rounded-lg px-4 py-2 text-sm font-semibold transition',
                       'bg-blue-100 text-blue-800' => $channel === $value,
                       'bg-gray-100 text-gray-600 hover:bg-gray-200' => $channel !== $value,
                   ])>Counting for {{ $label }}</a>
            @endforeach
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            {{-- Segments --}}
            <x-admin.panel title="Segments" icon="users" :flush="true">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left">
                        <tr>
                            <th scope="col" class="{{ $head }}">Segment</th>
                            <th scope="col" class="{{ $head }} text-right">Reachable</th>
                            <th scope="col" class="{{ $head }} text-right">Held back</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($segments as $key => $segment)
                            <tr class="hover:bg-gray-50 align-top">
                                <td class="px-5 py-3">
                                    <span class="block text-gray-900 font-semibold">{{ $segment['label'] }}</span>
                                    <span class="block text-xs text-gray-500 mt-0.5">{{ $segment['description'] }}</span>
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums font-bold {{ $segment['reachable'] > 0 ? 'text-green-700' : 'text-gray-400' }}">
                                    {{ $segment['reachable'] }}
                                </td>
                                <td class="px-5 py-3 text-right text-xs text-gray-500 whitespace-nowrap">
                                    of {{ $segment['total'] }}
                                    <span class="block">{{ $segment['no_consent'] }} not agreed</span>
                                    @if ($segment['suppressed'] > 0)
                                        <span class="block text-red-600">{{ $segment['suppressed'] }} suppressed</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-admin.panel>

            {{-- Per event --}}
            <x-admin.panel title="By Event" icon="clipboard" :flush="true">
                @if ($events === [])
                    <p class="px-5 py-8 text-sm text-gray-500 text-center">No event has anybody registered on it.</p>
                @else
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="{{ $head }}">Event</th>
                                <th scope="col" class="{{ $head }} text-right">Reachable</th>
                                <th scope="col" class="{{ $head }} text-right">On the entry</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($events as $id => $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3 text-gray-900">{{ $row['title'] }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums font-bold {{ $row['reachable'] > 0 ? 'text-green-700' : 'text-gray-400' }}">
                                        {{ $row['reachable'] }}
                                    </td>
                                    <td class="px-5 py-3 text-right tabular-nums text-gray-500">{{ $row['total'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-admin.panel>
        </div>

        {{-- The list itself --}}
        <div class="mt-5">
            <x-admin.panel title="Everybody On The List" icon="database" :flush="true">
                <form action="{{ route('admin.campaigns.audiences') }}" method="GET"
                      class="flex flex-wrap items-center gap-2 px-5 py-3.5 border-b border-gray-200 bg-white">
                    <input type="hidden" name="channel" value="{{ $channel }}">

                    <div class="relative flex-1 min-w-56">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                            <x-admin.icon name="search" class="w-4 h-4" />
                        </span>
                        <label for="q" class="sr-only">Search contacts</label>
                        <input type="search" id="q" name="q" value="{{ $filters['search'] }}"
                               placeholder="Name, email or number..."
                               class="w-full rounded-lg border border-gray-300 pl-9 pr-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                    </div>

                    <label for="state" class="sr-only">State</label>
                    <select id="state" name="state" class="{{ $filterInput }}">
                        <option value="">Everyone</option>
                        <option value="reachable" @selected($filters['state'] === 'reachable')>Reachable now</option>
                        <option value="no_consent" @selected($filters['state'] === 'no_consent')>Has not agreed</option>
                        <option value="suppressed" @selected($filters['state'] === 'suppressed')>Suppressed</option>
                    </select>

                    <button type="submit" class="rounded-lg bg-gray-100 px-3.5 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200 transition">
                        Apply
                    </button>

                    @if ($filters['search'] || $filters['state'])
                        <a href="{{ route('admin.campaigns.audiences', ['channel' => $channel]) }}"
                           class="px-3 py-2 text-sm font-semibold text-gray-500 hover:text-gray-800 transition">Reset</a>
                    @endif
                </form>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="{{ $head }}">Name</th>
                                <th scope="col" class="{{ $head }}">Email</th>
                                <th scope="col" class="{{ $head }}">Telephone</th>
                                <th scope="col" class="{{ $head }} text-center">Agreed</th>
                                <th scope="col" class="{{ $head }}">First seen on</th>
                                <th scope="col" class="{{ $head }}">State</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($contacts as $contact)
                                <tr class="hover:bg-gray-50 align-top">
                                    <td class="px-5 py-3 text-gray-900">{{ $contact->name ?: '—' }}</td>
                                    <td class="px-5 py-3 text-gray-600 break-all">{{ $contact->email ?: '—' }}</td>
                                    <td class="px-5 py-3 text-gray-600 font-mono text-xs">{{ $contact->phone ?: '—' }}</td>
                                    <td class="px-5 py-3 text-center whitespace-nowrap">
                                        @if ($contact->consent_email)
                                            <x-admin.badge tone="green">Email</x-admin.badge>
                                        @endif
                                        @if ($contact->consent_sms)
                                            <x-admin.badge tone="green">SMS</x-admin.badge>
                                        @endif
                                        @if (! $contact->consent_email && ! $contact->consent_sms)
                                            <span class="text-xs text-gray-400">No</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-xs text-gray-500">
                                        {{ $contact->firstEvent?->title ?? ($contact->consent_source === 'enquiry' ? 'A contact enquiry' : '—') }}
                                    </td>
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        @if ($contact->isSuppressed())
                                            <x-admin.badge tone="red">{{ $contact->suppressionReason() }}</x-admin.badge>
                                        @elseif ($isSms ? $contact->canReceiveSms() : $contact->canReceiveEmail())
                                            <x-admin.badge tone="green" dot>Reachable</x-admin.badge>
                                        @else
                                            <span class="text-xs text-gray-400">Cannot be sent to</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-12 text-center text-sm text-gray-500">
                                        Nothing matches. Press Rebuild List if this looks empty by mistake.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="px-5 py-3.5 border-t border-gray-200">
                    @if ($contacts->hasPages())
                        {{ $contacts->links() }}
                    @else
                        <p class="text-xs text-gray-500">{{ $contacts->count() }} shown</p>
                    @endif
                </div>
            </x-admin.panel>
        </div>
    </x-admin.page-card>
@endsection
