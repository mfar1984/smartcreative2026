@extends('layouts.admin')

@php
    use App\Models\Event;
    use App\Models\EventRegistration;

    $statusTones = [
        Event::STATUS_DRAFT => 'gray',
        Event::STATUS_OPEN => 'green',
        Event::STATUS_CLOSING_SOON => 'amber',
        Event::STATUS_FULL => 'red',
        Event::STATUS_CLOSED => 'gray',
        Event::STATUS_CANCELLED => 'red',
    ];

    $regTones = [
        EventRegistration::STATUS_PENDING => 'amber',
        EventRegistration::STATUS_CONFIRMED => 'green',
        EventRegistration::STATUS_WAITLISTED => 'blue',
        EventRegistration::STATUS_CANCELLED => 'red',
    ];

    $payTones = [
        EventRegistration::PAYMENT_UNPAID => 'gray',
        EventRegistration::PAYMENT_PENDING => 'amber',
        // Blue: some of the money is in, so it reads as neither settled nor untouched.
        EventRegistration::PAYMENT_PARTIAL => 'blue',
        EventRegistration::PAYMENT_PAID => 'green',
        EventRegistration::PAYMENT_FAILED => 'red',
        EventRegistration::PAYMENT_REFUNDED => 'purple',
    ];

    $blocked = $event->registrationBlockedReason();
@endphp

@section('title', $event->title)

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Event</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.event.registration') }}" class="hover:text-gray-700 transition">Registration</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ Str::limit($event->title, 40) }}</span>
@endsection

@section('content')
    <x-admin.page-card
        :title="$event->title"
        :description="$event->category . ' · ' . $event->slug"
        :back="route('admin.event.registration')">

        <x-slot:actions>
            <a href="{{ route('registration', ['register' => $event->slug]) }}"
               target="_blank"
               rel="noopener noreferrer"
               class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                </svg>
                View on site
            </a>

            {{-- Everyone on this event, with no filter to think about. The other
                 door to the same export is on the Participants screen, where it
                 carries whatever is filtered there instead. --}}
            @if ($canExportParticipants && $event->registrations()->exists())
                {{-- No tab, which the export reads as everybody. Naming one here
                     would hand back an empty file on an event of the other kind. --}}
                <a href="{{ route('admin.event.participants.export', ['event' => $event->id]) }}"
                   class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                    <x-admin.icon name="archive" class="w-4 h-4" />
                    Export Participants
                </a>
            @endif

            @if ($canUpdate)
                <a href="{{ route('admin.event.registration.edit', $event) }}"
                   class="inline-flex items-center gap-2 bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                    Edit Event
                </a>
            @endif

            @if ($canDelete)
                <form action="{{ route('admin.event.registration.destroy', $event) }}" method="POST"
                      onsubmit="return confirm('Delete {{ addslashes($event->title) }}? This cannot be undone.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="inline-flex items-center gap-2 rounded-lg border border-red-300 px-4 py-2.5 text-sm font-semibold text-red-700 hover:bg-red-50 transition">
                        Delete
                    </button>
                </form>
            @endif
        </x-slot:actions>

        {{-- Registration gate: the single reason the public button is or is not live --}}
        <div @class([
            'flex items-start gap-3 rounded-lg border p-4 mb-5',
            'bg-green-50 border-green-200' => $blocked === null,
            'bg-amber-50 border-amber-200' => $blocked !== null,
        ])>
            <x-admin.icon :name="$blocked === null ? 'shield' : 'lock'"
                          @class(['w-5 h-5 mt-0.5 shrink-0', 'text-green-600' => $blocked === null, 'text-amber-600' => $blocked !== null]) />
            <p @class(['text-sm', 'text-green-800' => $blocked === null, 'text-amber-800' => $blocked !== null])>
                @if ($blocked === null)
                    <span class="font-semibold">Registration is open.</span>
                    Visitors can submit the form from the public page.
                @else
                    <span class="font-semibold">Registration is closed:</span> {{ $blocked }}
                @endif
            </p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-5">
            {{-- Posters --}}
            <x-admin.panel title="Posters" icon="grid" :flush="true">
                @if ($event->posterUrl())
                    <img src="{{ $event->posterUrl() }}" alt="Poster for {{ $event->title }}" class="w-full h-auto">
                @else
                    <p class="px-5 py-16 text-sm text-gray-500 text-center">No poster uploaded.</p>
                @endif
            </x-admin.panel>

            {{-- Details --}}
            <div class="lg:col-span-2">
                <x-admin.panel title="Event Details" icon="clipboard">

                    {{--
                        Venue Wi-Fi, and only when the event offers it.

                        Above Status because these two are the things somebody opens this
                        page to do on the days around an event, and neither is a property of
                        the event so much as a job waiting to be done.

                        The two rows are deliberately in the order they have to happen. The
                        router fetches, then people are told. Reversed, competitors get a
                        correct password that fails, and from then on they will not believe
                        the password whatever anyone says.
                    --}}
                    @if ($wifi)
                        <x-admin.field-row
                            label="Command Fetch"
                            help="Paste into the router's terminal, or put it on a scheduler. Safe to run again.">

                            <div class="flex flex-wrap items-stretch gap-2">
                                {{-- Read only and selected on click. It is here to be
                                     copied, not edited, and a stray keystroke in it would
                                     produce a command that silently fetches nothing. --}}
                                <input type="text"
                                       id="wifi-command"
                                       value="{{ $wifi['command'] }}"
                                       readonly
                                       onclick="this.select()"
                                       class="flex-1 min-w-0 rounded-lg border border-gray-300 bg-gray-50 px-3.5 py-2.5 text-xs font-mono text-gray-800 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40">

                                <button type="button"
                                        data-copy-target="wifi-command"
                                        class="shrink-0 inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                                    <x-admin.icon name="clipboard" class="w-4 h-4" />
                                    <span data-copy-label>Copy</span>
                                </button>
                            </div>

                            <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs">
                                <span class="text-gray-500">
                                    <strong class="font-semibold text-gray-900 tabular-nums">{{ $wifi['provisioned'] }}</strong>
                                    of {{ $wifi['issued'] }} on the router
                                </span>

                                @if ($wifi['missing'] > 0)
                                    <span class="inline-flex items-center gap-1.5 text-amber-700">
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                        {{ $wifi['missing'] }} competitor{{ $wifi['missing'] === 1 ? '' : 's' }} with no login yet
                                    </span>
                                @endif

                                @if ($wifi['expires_on'])
                                    <span class="text-gray-500">Logins die after {{ \App\Support\LocalTime::dateWallClock($wifi['expires_on']) }}</span>
                                @endif
                            </div>

                            @if ($canUpdate)
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @if ($wifi['missing'] > 0)
                                        <form action="{{ route('admin.event.registration.wifi.issue', $event) }}" method="POST">
                                            @csrf
                                            <button type="submit"
                                                    class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3.5 py-2 text-xs font-semibold text-white hover:bg-blue-700 transition">
                                                Issue the {{ $wifi['missing'] }} missing
                                            </button>
                                        </form>
                                    @endif

                                    {{-- Rotating breaks the scheduled fetch until the new
                                         command is pasted in, so it asks first. --}}
                                    <form action="{{ route('admin.event.registration.wifi.rotate', $event) }}" method="POST"
                                          onsubmit="return confirm('This replaces the address above. The router will fetch nothing until you paste the new command in. Continue?');">
                                        @csrf
                                        <button type="submit"
                                                class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3.5 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 transition">
                                            New address
                                        </button>
                                    </form>
                                </div>
                            @endif

                            <p class="text-xs text-gray-500 mt-3">
                                Anyone holding this address can read every login on this event, so keep
                                it off group chats. The real protection is that the logins stop working
                                after the event.
                            </p>
                        </x-admin.field-row>

                        <x-admin.field-row
                            label="Sending User & Password"
                            help="Only sends to people whose login is already on the router. Pressing it again reaches whoever was missed, not everybody twice.">

                            <div class="md:pt-1.5 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                                <span class="text-gray-900">
                                    <strong class="font-semibold tabular-nums">{{ $wifi['delivered'] }}</strong>
                                    <span class="text-gray-500">of {{ $wifi['issued'] }} told</span>
                                </span>

                                @if ($wifi['provisioned'] < $wifi['issued'])
                                    <span class="inline-flex items-center gap-1.5 text-xs text-amber-700">
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                        {{ $wifi['issued'] - $wifi['provisioned'] }} still waiting on the router
                                    </span>
                                @endif
                            </div>

                            <div class="mt-3 flex flex-wrap gap-2">
                                @if ($canUpdate)
                                    <form action="{{ route('admin.event.registration.wifi.send', $event) }}" method="POST"
                                          onsubmit="return confirm('Email their Wi-Fi login to everybody who has not been told yet?');">
                                        @csrf
                                        <button type="submit"
                                                @disabled($wifi['provisioned'] === 0)
                                                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3.5 py-2 text-xs font-semibold text-white hover:bg-blue-700 transition disabled:opacity-40 disabled:cursor-not-allowed">
                                            <x-admin.icon name="send" class="w-4 h-4" />
                                            Send by email
                                        </button>
                                    </form>
                                @endif

                                @if ($canExportParticipants)
                                    <a href="{{ route('admin.event.registration.wifi.slips', $event) }}"
                                       target="_blank"
                                       class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3.5 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 transition">
                                        <x-admin.icon name="clipboard" class="w-4 h-4" />
                                        Print slips for the counter
                                    </a>
                                @endif
                            </div>

                            @if ($wifi['provisioned'] === 0)
                                <p class="text-xs text-amber-700 mt-3">
                                    Nothing to send yet. Run the fetch command above first, otherwise an
                                    email would carry a login that fails when somebody tries it.
                                </p>
                            @else
                                <p class="text-xs text-gray-500 mt-3">
                                    Competitors with no email address on file are skipped and counted back
                                    to you. The printed slips are for them, and for anyone who arrives
                                    having lost the email.
                                </p>
                            @endif
                        </x-admin.field-row>
                    @endif

                    <x-admin.field-row label="Status">
                        <div class="md:pt-1.5 flex flex-wrap items-center gap-2">
                            <x-admin.badge :tone="$statusTones[$event->status] ?? 'gray'">{{ $event->statusLabel() }}</x-admin.badge>
                            <x-admin.badge tone="blue" :dot="true">{{ ucfirst($event->lifecycle()) }}</x-admin.badge>
                        </div>
                    </x-admin.field-row>

                    <x-admin.field-row label="Dates">
                        <p class="md:pt-2.5 text-sm text-gray-900">
                            {{ \App\Support\LocalTime::dateWallClock($event->starts_at) }}
                            @unless ($event->starts_at->isSameDay($event->ends_at))
                                &ndash; {{ \App\Support\LocalTime::dateWallClock($event->ends_at) }}
                            @endunless
                            @if ($event->time)
                                <span class="block text-xs text-gray-500">{{ $event->time }}</span>
                            @endif
                        </p>
                    </x-admin.field-row>

                    <x-admin.field-row label="Location">
                        <p class="md:pt-2.5 text-sm text-gray-900">
                            {{ $event->location }}
                            @if ($event->address)
                                <span class="block text-xs text-gray-500 whitespace-pre-line">{{ $event->address }}</span>
                            @endif
                        </p>
                    </x-admin.field-row>

                    <x-admin.field-row label="Price">
                        <div class="md:pt-1.5">
                            <p class="text-sm font-semibold text-gray-900">
                                {{ $event->feeLabel() }}
                                @unless ($event->isFree())
                                    <span class="text-xs font-normal text-gray-500">{{ $event->feeBasisLabel() }}</span>
                                @endunless
                            </p>
                            @if ($event->isManagerMode() && ! $event->isFree())
                                <p class="text-xs text-gray-500 mt-0.5">
                                    One charge for the whole squad, whatever its size.
                                </p>
                            @endif
                            <p class="text-xs text-gray-500 mt-0.5">
                                Gateway: {{ $payment['summary'] }}
                                @if (! $event->isFree() && ! $payment['ready'])
                                    <span class="text-amber-700 font-semibold">Fees cannot be collected yet.</span>
                                @endif
                            </p>
                        </div>
                    </x-admin.field-row>

                    <x-admin.field-row label="Seats">
                        <p class="md:pt-2.5 text-sm text-gray-900">
                            @if ($event->seats_total > 0)
                                {{ $event->seats_taken }} / {{ $event->seats_total }} {{ $event->seatBasisLabel() }}
                                <span class="text-xs text-gray-500">({{ $event->seatsLeft() }} left, {{ $event->filledPercent() }}% filled)</span>
                            @else
                                Unlimited
                            @endif
                        </p>
                    </x-admin.field-row>

                    <x-admin.field-row label="Registration Mode">
                        <p class="md:pt-2.5 text-sm text-gray-900">
                            {{ $event->modeLabel() }}
                            {{-- Both multi-person modes carry member bounds, so both
                                 print them. The noun follows the mode: a grouping
                                 event has no manager to count players for. --}}
                            @if ($event->allowsMultipleParticipants())
                                <span class="block text-xs text-gray-500">
                                    {{ $event->min_players ?? 1 }} to {{ $event->max_players ?? 'unlimited' }}
                                    {{ $event->isManagerMode() ? 'players per manager' : 'participants per group' }}
                                </span>
                            @endif
                        </p>
                    </x-admin.field-row>

                    <x-admin.field-row label="Registration Window">
                        <p class="md:pt-2.5 text-sm text-gray-900">
                            {{ \App\Support\LocalTime::dateWallClock($event->registration_opens_at, 'Open now') }}
                            &ndash;
                            {{ \App\Support\LocalTime::dateWallClock($event->registration_closes_at, 'until the event ends') }}
                        </p>
                    </x-admin.field-row>

                    @if ($event->description)
                        <x-admin.field-row label="Description">
                            <p class="md:pt-2 text-sm text-gray-600 leading-relaxed">{{ $event->description }}</p>
                        </x-admin.field-row>
                    @endif

                    @if ($event->hasRules())
                        <x-admin.field-row label="Rules">
                            {{-- One line per rule, matching how they are entered
                                 and how the public form lists them. --}}
                            <ul class="md:pt-2 space-y-1.5">
                                @foreach ($event->ruleLines() as $rule)
                                    <li class="flex items-start gap-2 text-sm text-gray-600 leading-relaxed">
                                        <span class="mt-1.5 w-1.5 h-1.5 rounded-full bg-blue-500 shrink-0" aria-hidden="true"></span>
                                        <span>{{ $rule }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </x-admin.field-row>
                    @endif

                    @if ($event->hasRulesFile())
                        <x-admin.field-row label="Rules Attachment">
                            <div class="md:pt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                                <svg class="w-4 h-4 shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>

                                <a href="{{ $event->rulesFileUrl() }}" target="_blank" rel="noopener"
                                   class="text-sm font-semibold text-blue-600 hover:text-blue-700 hover:underline break-all">
                                    {{ $event->rulesFileName() }}
                                </a>

                                @if ($size = $event->rulesFileSizeLabel())
                                    <span class="text-xs text-gray-500">{{ $size }}</span>
                                @endif
                            </div>
                        </x-admin.field-row>
                    @endif
                </x-admin.panel>
            </div>
        </div>

        {{-- Registrations --}}
        <x-admin.panel title="Registrations" icon="users" :flush="true">
            @if ($event->registrations->isEmpty())
                <p class="px-5 py-12 text-sm text-gray-500 text-center">
                    No registrations received yet.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Reference</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Name</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Mode</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">People</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Amount</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Status</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Payment</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Submitted</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($event->registrations as $registration)
                                <tr class="hover:bg-blue-50/40 align-top">
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <code class="text-xs text-gray-700">{{ $registration->reference }}</code>
                                    </td>
                                    <td class="px-5 py-3">
                                        <span class="block text-gray-900">{{ $registration->displayName() }}</span>
                                        @foreach ($registration->participants as $participant)
                                            <span class="block text-xs text-gray-500">
                                                {{ $participant->roleLabel() }}: {{ $participant->full_name }}
                                                <span class="text-gray-400">({{ $participant->ic_number }})</span>
                                            </span>
                                        @endforeach
                                    </td>
                                    <td class="px-5 py-3 whitespace-nowrap text-gray-600">{{ ucfirst($registration->mode) }}</td>
                                    <td class="px-5 py-3 whitespace-nowrap text-gray-600">{{ $registration->participants->count() }}</td>
                                    <td class="px-5 py-3 text-gray-900">
                                        RM {{ number_format((float) $registration->amount, 2) }}

                                        {{-- Broken down when extras were bought, so the
                                             organiser can see what to order. --}}
                                        @if ($registration->addonLines->isNotEmpty())
                                            <span class="block text-xs text-gray-500 mt-0.5 whitespace-nowrap">
                                                Fee {{ $registration->registrationFeeLabel() }}
                                            </span>
                                            @foreach ($registration->addonLines as $line)
                                                <span class="block text-xs text-gray-500 whitespace-nowrap">
                                                    {{ $line->describe() }} &times; {{ $line->quantity }}
                                                    <span class="text-gray-400">{{ $line->lineTotalLabel() }}</span>
                                                </span>
                                            @endforeach
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <x-admin.badge :tone="$regTones[$registration->status] ?? 'gray'">{{ $registration->statusLabel() }}</x-admin.badge>
                                    </td>
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <x-admin.badge :tone="$payTones[$registration->payment_status] ?? 'gray'">{{ $registration->paymentStatusLabel() }}</x-admin.badge>
                                    </td>
                                    <td class="px-5 py-3 whitespace-nowrap text-xs text-gray-500">
                                        {{ \App\Support\LocalTime::format($registration->created_at) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-admin.panel>
    </x-admin.page-card>
@endsection

@push('scripts')
<script>
    (function () {
        /*
         | Copy buttons.
         |
         | The fetch command is long, monospaced, and gets pasted into a router terminal
         | where one missing character produces a command that silently fetches nothing.
         | Selecting it by hand in a narrow input is exactly where that goes wrong.
         |
         | Written against any element carrying data-copy-target rather than one id, so a
         | second copyable field later needs no new script.
         */
        document.querySelectorAll('[data-copy-target]').forEach(function (button) {
            button.addEventListener('click', function () {
                const field = document.getElementById(button.dataset.copyTarget);

                if (!field) {
                    return;
                }

                const label = button.querySelector('[data-copy-label]');
                const done = function () {
                    if (!label) {
                        return;
                    }

                    // Confirmation has to be visible, because a copy that worked and one
                    // that silently failed look identical otherwise.
                    const was = label.textContent;
                    label.textContent = 'Copied';
                    setTimeout(function () { label.textContent = was; }, 1500);
                };

                // The clipboard API needs a secure context, which a venue laptop on plain
                // HTTP does not have. Selecting the text is the fallback: it leaves the
                // operator one keystroke away rather than with nothing.
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(field.value).then(done).catch(function () {
                        field.select();
                    });

                    return;
                }

                field.select();

                try {
                    document.execCommand('copy');
                    done();
                } catch (error) {
                    // Left selected. Ctrl+C is the operator's move from here.
                }
            });
        });
    })();
</script>
@endpush
