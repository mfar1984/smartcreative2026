{{--
    Everyone checked in, newest arrival first.

    @param \Illuminate\Pagination\LengthAwarePaginator $present
--}}
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <x-admin.filter-bar
        :action="route('admin.event.attendance')"
        :reset="$isFiltered ? route('admin.event.attendance', ['tab' => 'present']) : null">

        <input type="hidden" name="tab" value="present">

        <div class="relative flex-1 min-w-56">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                <x-admin.icon name="search" class="w-4 h-4" />
            </span>
            <label for="q" class="sr-only">Search arrivals</label>
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
                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Checked In At</th>
                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Method</th>
                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Recorded By</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-gray-100">
                @forelse ($present as $row)
                    <tr class="hover:bg-green-50/40 align-top">
                        <td class="px-5 py-3 text-gray-700">{{ $row->event?->title ?? '—' }}</td>

                        <td class="px-5 py-3">
                            <span class="block font-semibold text-gray-900">{{ $row->participant?->full_name ?? '—' }}</span>
                            <span class="block font-mono text-xs text-gray-500 tabular-nums">
                                {{ $row->participant?->ic_number }}
                            </span>
                            @if ($row->participant)
                                <span class="block text-xs text-gray-400">{{ $row->participant->roleLabel() }}</span>
                            @endif
                        </td>

                        <td class="px-5 py-3">
                            <span class="block text-gray-900">{{ $row->registration?->displayName() ?? '—' }}</span>
                            @if ($row->registration)
                                <a href="{{ route('admin.event.participants.show', $row->registration) }}"
                                   class="block text-xs text-blue-600 hover:underline">
                                    {{ $row->registration->reference }}
                                </a>
                            @endif
                        </td>

                        <td class="px-5 py-3 whitespace-nowrap text-gray-700">
                            {{ $row->checked_in_at?->format('d M Y, g:i a') }}
                            <span class="block text-xs text-gray-400">{{ $row->checked_in_at?->diffForHumans() }}</span>
                        </td>

                        <td class="px-5 py-3">
                            <x-admin.badge :tone="$row->ic_verified ? 'green' : 'amber'">
                                {{ $row->methodLabel() }}
                            </x-admin.badge>
                            @if (filled($row->notes))
                                <span class="block text-xs text-gray-500 mt-1 italic">"{{ $row->notes }}"</span>
                            @endif
                        </td>

                        <td class="px-5 py-3 whitespace-nowrap text-gray-600">{{ $row->recordedByName() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-12 text-center text-sm text-gray-500">
                            @if ($isFiltered)
                                Nobody matching the current filters has checked in.
                            @else
                                Nobody has checked in yet. Use the Attendance tab to open the counter.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="px-5 py-3.5 border-t border-gray-200">
        @if ($present->hasPages())
            {{ $present->links() }}
        @else
            <p class="text-xs text-gray-500">
                {{ $present->total() }} {{ Str::plural('arrival', $present->total()) }}
            </p>
        @endif
    </div>
</div>
