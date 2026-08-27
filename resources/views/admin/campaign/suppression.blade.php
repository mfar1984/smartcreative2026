@extends('layouts.admin')

@section('title', 'Suppression')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.campaigns.index') }}" class="hover:text-gray-700 transition">Campaign</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Suppression</span>
@endsection

@section('content')
    @php
        $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
    @endphp

    <x-admin.page-card
        title="Suppression"
        description="People who must never receive a campaign again, and why.">

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
            <x-admin.money-card label="Unsubscribed" :value="number_format($counts['unsubscribed'])" note="asked to stop" tone="red" />
            <x-admin.money-card label="Bounced" :value="number_format($counts['bounced'])" note="address does not exist" tone="amber" />
            <x-admin.money-card label="Complaints" :value="number_format($counts['complained'])" note="reported as spam" tone="red" />
        </div>

        @if ($canAdd)
            <x-admin.panel title="Add By Hand" icon="lock">
                <div class="px-5 py-4">
                    <p class="text-sm text-gray-600 mb-4">
                        For somebody who asks to be removed by telephone or in person. Recorded the
                        same way as a pressed link, so there is one list to check rather than two.
                    </p>

                    <form action="{{ route('admin.campaigns.suppression.add') }}" method="POST"
                          class="flex flex-wrap items-end gap-3">
                        @csrf
                        <div class="flex-1 min-w-56">
                            <label for="identifier" class="block text-xs font-semibold text-gray-700 mb-1">
                                Email address or telephone number
                            </label>
                            <input type="text" id="identifier" name="identifier" required maxlength="190"
                                   class="w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                            @error('identifier')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>

                        <div class="flex-1 min-w-48">
                            <label for="reason" class="block text-xs font-semibold text-gray-700 mb-1">Reason</label>
                            <input type="text" id="reason" name="reason" maxlength="190"
                                   placeholder="e.g. asked at the counter"
                                   class="w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                        </div>

                        <button type="submit"
                                class="rounded-lg bg-red-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-red-700 transition shadow-sm">
                            Suppress
                        </button>
                    </form>
                </div>
            </x-admin.panel>
        @endif

        <div class="mt-5">
            <x-admin.panel title="Suppressed" icon="database" :flush="true">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="{{ $head }}">Who</th>
                                <th scope="col" class="{{ $head }}">Reason</th>
                                <th scope="col" class="{{ $head }}">When</th>
                                @if ($canRestore)
                                    <th scope="col" class="{{ $head }} text-center">Put back</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($contacts as $contact)
                                <tr class="hover:bg-red-50/40 align-top">
                                    <td class="px-5 py-3">
                                        <span class="block text-gray-900">{{ $contact->name ?: '—' }}</span>
                                        <span class="block text-xs text-gray-500 break-all">
                                            {{ $contact->email ?: $contact->phone }}
                                        </span>
                                    </td>

                                    <td class="px-5 py-3 text-gray-600">
                                        {{ $contact->unsubscribe_reason ?: $contact->bounce_reason ?: '—' }}
                                    </td>

                                    <td class="px-5 py-3 whitespace-nowrap text-xs text-gray-500">
                                        {{ ($contact->unsubscribed_at ?? $contact->bounced_at ?? $contact->complained_at)?->format('d M Y, g:i a') }}
                                    </td>

                                    @if ($canRestore)
                                        <td class="px-5 py-3 text-center">
                                            {{-- Only correct when the person asked. Worded as a
                                                 warning rather than as a convenience. --}}
                                            <form action="{{ route('admin.campaigns.suppression.resubscribe', $contact) }}" method="POST"
                                                  onsubmit="return confirm('Put {{ addslashes($contact->label()) }} back on the list?\n\nOnly do this if they asked to be. Sending to somebody who unsubscribed is what gets a domain blocked.');">
                                                @csrf
                                                <button type="submit" class="text-xs font-semibold text-blue-600 hover:underline">
                                                    They asked to rejoin
                                                </button>
                                            </form>
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $canRestore ? 4 : 3 }}" class="px-5 py-12 text-center">
                                        <x-admin.icon name="shield" class="w-10 h-10 mx-auto text-green-300" />
                                        <p class="text-sm font-semibold text-gray-700 mt-3">Nobody is suppressed</p>
                                        <p class="text-sm text-gray-500 mt-1">Nobody has unsubscribed or bounced.</p>
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
