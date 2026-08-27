{{--
    Named on a registration but with no arrival recorded.

    Derived rather than stored, so it can never disagree with the Present list.
    Cancelled registrations are left out: nobody is waiting for them, and
    counting them would overstate the gap.

    @param \Illuminate\Pagination\LengthAwarePaginator $absent  participants
--}}
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <x-admin.filter-bar
        :action="route('admin.event.attendance')"
        :reset="$isFiltered ? route('admin.event.attendance', ['tab' => 'absent']) : null">

        <input type="hidden" name="tab" value="absent">

        <div class="relative flex-1 min-w-56">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                <x-admin.icon name="search" class="w-4 h-4" />
            </span>
            <label for="q" class="sr-only">Search those not arrived</label>
            <input type="search" id="q" name="q" value="{{ $search }}"
                   placeholder="Search name, card, team or reference..."
                   class="w-full rounded-lg border border-gray-300 pl-9 pr-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
        </div>

        <label for="event" class="sr-only">Event</label>
        <select id="event" name="event" class="{{ $filterInput }}">
            <option value="">All Events</option>
            @foreach ($events as $id => $title)
                <option value="{{ $id }}" @selected((string) $eventId === (string) $id)>{{ $title }}</option>
            @endforeach
        </select>
    </x-admin.filter-bar>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left">
                <tr>
                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Event</th>
                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Participant</th>
                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Team</th>
                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Contact</th>
                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Payment</th>
                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 text-center">Open at Counter</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-gray-100">
                @forelse ($absent as $participant)
                    @php $registration = $participant->registration; @endphp

                    <tr class="hover:bg-amber-50/40 align-top">
                        <td class="px-5 py-3 text-gray-700">{{ $registration?->event?->title ?? '—' }}</td>

                        <td class="px-5 py-3">
                            <span class="block font-semibold text-gray-900">{{ $participant->full_name }}</span>
                            <span class="block font-mono text-xs text-gray-500 tabular-nums">{{ $participant->ic_number }}</span>
                            <span class="block text-xs text-gray-400">{{ $participant->roleLabel() }}</span>
                        </td>

                        <td class="px-5 py-3">
                            <span class="block text-gray-900">{{ $registration?->displayName() ?? '—' }}</span>
                            @if ($registration)
                                <a href="{{ route('admin.event.participants.show', $registration) }}"
                                   class="block text-xs text-blue-600 hover:underline">{{ $registration->reference }}</a>
                            @endif
                        </td>

                        <td class="px-5 py-3 text-xs text-gray-600">
                            @if (filled($participant->phone))
                                <a href="tel:{{ $participant->phone }}" class="block text-blue-600 hover:underline">{{ $participant->phone }}</a>
                            @endif
                            @if (filled($participant->email))
                                <span class="block text-gray-500">{{ $participant->email }}</span>
                            @endif
                            @if (blank($participant->phone) && blank($participant->email))
                                —
                            @endif
                        </td>

                        <td class="px-5 py-3 whitespace-nowrap">
                            @if ($registration)
                                <x-admin.badge :tone="$registration->isPaid() ? 'green' : 'amber'">
                                    {{ $registration->paymentStatusLabel() }}
                                </x-admin.badge>
                            @else
                                —
                            @endif
                        </td>

                        <td class="px-5 py-3 whitespace-nowrap text-center">
                            @if ($registration)
                                {{-- Straight to the desk with this entry loaded, so
                                     a phone call can turn into a check-in without
                                     searching again. --}}
                                <a href="{{ route('admin.event.attendance', ['tab' => 'attendance', 'registration' => $registration->id]) }}"
                                   class="inline-flex items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-100 transition">
                                    <x-admin.icon name="clipboard" class="w-3.5 h-3.5" />
                                    Counter
                                </a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-12 text-center text-sm text-gray-500">
                            @if ($isFiltered)
                                Everyone matching the current filters has checked in.
                            @else
                                Nobody is outstanding. Everyone named on a live registration has
                                checked in.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="px-5 py-3.5 border-t border-gray-200">
        @if ($absent->hasPages())
            {{ $absent->links() }}
        @else
            <p class="text-xs text-gray-500">
                {{ $absent->total() }} {{ Str::plural('person', $absent->total()) }} still to arrive
            </p>
        @endif
    </div>
</div>
