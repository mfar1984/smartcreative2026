{{--
    Audit of every place handed to a different person at the counter.

    Read only on purpose: a substitution is made at the desk, and this is the
    record of it. Editing the record would defeat the point of keeping one.

    @param \Illuminate\Pagination\LengthAwarePaginator $changes
--}}
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <x-admin.filter-bar
        :action="route('admin.event.attendance')"
        :reset="$isFiltered ? route('admin.event.attendance', ['tab' => 'player-change']) : null">

        <input type="hidden" name="tab" value="player-change">

        <div class="relative flex-1 min-w-56">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                <x-admin.icon name="search" class="w-4 h-4" />
            </span>
            <label for="q" class="sr-only">Search substitutions</label>
            <input type="search" id="q" name="q" value="{{ $search }}"
                   placeholder="Search either name, either card, team or reference..."
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
                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Change</th>
                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Team</th>
                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Player Out</th>
                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Player In</th>
                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Reason</th>
                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Recorded By</th>
                    <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Recorded At</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-gray-100">
                @forelse ($changes as $change)
                    <tr class="hover:bg-purple-50/40 align-top">
                        <td class="px-5 py-3 text-gray-700">{{ $change->event?->title ?? '—' }}</td>

                        {{-- Three different things land in this table and they read
                             alike without saying which. A substitution keeps the
                             squad whole, a transfer leaves another team short, and
                             a removal leaves this one short. --}}
                        <td class="px-5 py-3 whitespace-nowrap">
                            <x-admin.badge :tone="match ($change->type) {
                                \App\Models\EventParticipantChange::TYPE_TRANSFER => 'amber',
                                \App\Models\EventParticipantChange::TYPE_REMOVED => 'red',
                                default => 'purple',
                            }">
                                {{ $change->typeLabel() }}
                            </x-admin.badge>
                        </td>

                        <td class="px-5 py-3">
                            <span class="block text-gray-900">{{ $change->subject() }}</span>
                            @if ($change->registration)
                                <a href="{{ route('admin.event.participants.show', $change->registration) }}"
                                   class="block text-xs text-blue-600 hover:underline">
                                    {{ $change->registration->reference }}
                                </a>
                            @endif

                            @if ($change->isTransfer())
                                <span class="block text-xs text-amber-700 mt-0.5">
                                    came from {{ $change->fromLabel() }}
                                </span>
                            @endif
                        </td>

                        <td class="px-5 py-3">
                            <span class="block text-gray-900">{{ $change->previous_name }}</span>
                            <span class="block font-mono text-xs text-gray-500 tabular-nums">{{ $change->previous_ic }}</span>

                            {{-- Read out of the snapshot rather than kept in its
                                 own column: only game events have one, and the
                                 snapshot already holds it. --}}
                            @if (filled(data_get($change->details_before, 'ign_player_id')))
                                <span class="block font-mono text-xs text-gray-400">
                                    IGN {{ data_get($change->details_before, 'ign_player_id') }}
                                    @if (filled(data_get($change->details_before, 'ign_server_id')))
                                        / {{ data_get($change->details_before, 'ign_server_id') }}
                                    @endif
                                </span>
                            @endif
                        </td>

                        <td class="px-5 py-3">
                            @if ($change->isRemoval())
                                <span class="block text-sm italic text-red-700">Nobody. The place was given up.</span>
                            @else
                                <span class="block font-semibold text-gray-900">{{ $change->new_name }}</span>
                                <span class="block font-mono text-xs text-gray-500 tabular-nums">{{ $change->new_ic }}</span>
                            @endif

                            @if (! $change->isRemoval() && filled(data_get($change->details_after, 'ign_player_id')))
                                <span class="block font-mono text-xs text-gray-400">
                                    IGN {{ data_get($change->details_after, 'ign_player_id') }}
                                    @if (filled(data_get($change->details_after, 'ign_server_id')))
                                        / {{ data_get($change->details_after, 'ign_server_id') }}
                                    @endif
                                </span>
                            @endif
                        </td>

                        <td class="px-5 py-3 text-gray-600">{{ $change->reason ?: '—' }}</td>

                        <td class="px-5 py-3 whitespace-nowrap text-gray-600">{{ $change->changedByName() }}</td>

                        <td class="px-5 py-3 whitespace-nowrap text-xs text-gray-500">
                            {{ $change->created_at?->format('d M Y, g:i a') }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-5 py-12 text-center text-sm text-gray-500">
                            @if ($isFiltered)
                                No changes match the current filters.
                            @else
                                Nothing has changed at the counter yet. Substitutions, transfers
                                between teams, and players taken off an entry all appear here.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="px-5 py-3.5 border-t border-gray-200">
        @if ($changes->hasPages())
            {{ $changes->links() }}
        @else
            <p class="text-xs text-gray-500">
                {{ $changes->total() }} {{ Str::plural('change', $changes->total()) }} recorded
            </p>
        @endif
    </div>
</div>
