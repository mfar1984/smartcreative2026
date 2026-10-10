{{--
    The Export CSV button for the attendance screen's filter bars.

    One partial rather than the same markup on three tabs, because the three list
    tabs are three views of one event's attendance and the file is the same file from
    all of them. The tab is deliberately NOT carried in the link: the export is one
    row per person expected, with their arrival state in a column, so it already
    answers Present and Absent in one file and narrowing it by tab would hand back
    half of it.

    Offered only once an event is chosen. One file holding every identity card number
    ever collected is a different risk from one event's, and the controller refuses it
    either way; a disabled button with a reason on hover says so before the press,
    which is the pattern the Participants and Collection exports already use.

    @param bool $canExport  holds attendance.view AND participants.export
    @param string $eventId  the chosen event, '' for none
--}}
@if ($canExport)
    <x-slot:actions>
        @if ($eventId !== '')
            <a href="{{ route('admin.event.attendance.export', request()->only('event', 'q')) }}"
               title="One row per person expected, with their arrival state. Carries identity card numbers."
               class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3.5 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                <x-admin.icon name="archive" class="w-4 h-4" />
                Export CSV
            </a>
        @else
            <span title="Choose an event first. A file covering every event would carry more personal data than any one job needs."
                  aria-disabled="true"
                  class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-3.5 py-2 text-sm font-semibold text-gray-400 cursor-not-allowed">
                <x-admin.icon name="archive" class="w-4 h-4" />
                Export CSV
            </span>
        @endif
    </x-slot:actions>
@endif
