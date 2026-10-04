@extends('layouts.admin')

@section('title', 'Gateway receipts · ' . $event->title)

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Event</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.event.participants', ['tab' => 'group', 'event' => $event->id]) }}" class="hover:text-gray-700 transition">Participants</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Gateway Receipts</span>
@endsection

@section('content')
    @php
        use App\Models\EventRegistration;
        use App\Services\Payment\GatewayReceiptAudit;

        $payTones = [
            EventRegistration::PAYMENT_UNPAID => 'gray',
            EventRegistration::PAYMENT_PENDING => 'amber',
            EventRegistration::PAYMENT_PARTIAL => 'blue',
            EventRegistration::PAYMENT_PAID => 'green',
            EventRegistration::PAYMENT_FAILED => 'red',
            EventRegistration::PAYMENT_REFUNDED => 'purple',
        ];

        // What the takings would lose, so the operator sees the size of the press
        // before making it rather than afterwards.
        $phantom = GatewayReceiptAudit::total($findings);
    @endphp

    <x-admin.page-card
        title="Gateway receipts against the gateway's own record"
        :description="$event->title"
        :back="route('admin.event.participants', ['tab' => 'group', 'event' => $event->id])">

        @include('admin.partials.flash')

        {{-- ---------------- What this is for ---------------- --}}
        <x-admin.panel title="What this does" icon="clipboard">
            <div class="px-5 py-4 space-y-3">
                <p class="text-sm text-gray-700 leading-relaxed">
                    A gateway payment used to be recorded as "whatever is left of the charge",
                    which is right for as long as a charge never moves. Rechecking add-on
                    totals moves one. After that, a repeated paid event for the same purchase
                    &mdash; a replayed webhook, a late message, or somebody reloading the
                    return page &mdash; worked the shortfall out against the new figure and
                    wrote itself a receipt for a transaction the gateway never had.
                </p>

                <p class="text-sm text-gray-700 leading-relaxed">
                    This lists every receipt the gateway's own record contradicts. A row is
                    listed only when it shares a purchase reference with another row on the
                    same entry <span class="font-semibold">and</span> the rows carrying that
                    reference add up to more than the stored gateway payload says the purchase
                    took. Both figures are shown beside each row, so nothing here asks to be
                    taken on trust. Where there is no stored payload there is no evidence, and
                    the entry is left out.
                </p>

                <p class="text-sm text-gray-700 leading-relaxed">
                    Receipts recorded by hand are never listed and never removed, whatever the
                    gateway says about its own purchases: a row carrying a member of staff, or
                    a transfer slip, is somebody's record of money they saw arrive. Nothing is
                    written until you tick the confirmation at the foot of this page, every
                    removed row is kept in full in the audit trail, and running it again finds
                    nothing.
                </p>
            </div>
        </x-admin.panel>

        {{-- ---------------- The rows ---------------- --}}
        <x-admin.panel :title="'Receipts the gateway contradicts · ' . count($findings) . ' ' . Str::plural('entry', count($findings))" icon="warning" :flush="true">
            @if ($findings === [])
                <p class="px-5 py-6 text-sm text-gray-500">
                    Nothing to correct. Every gateway receipt on this event matches what the
                    gateway reports for its purchase.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Gateway receipts the stored payload contradicts on {{ $event->title }}</caption>
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Reference</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Row</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 text-right">Row amount</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Received</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 text-right">Gateway reports</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 text-right">Ledger claims</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 text-right">Phantom</th>
                                <th scope="col" class="px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">Payment becomes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($findings as $finding)
                                @foreach ($finding->phantoms as $index => $receipt)
                                    <tr class="align-top">
                                        <td class="px-5 py-3 whitespace-nowrap">
                                            <a href="{{ route('admin.event.participants.show', $finding->registration) }}"
                                               class="font-semibold text-blue-600 hover:underline">
                                                {{ $finding->registration->reference }}
                                            </a>
                                            <span class="block text-xs text-gray-500">{{ $finding->registration->displayName() }}</span>
                                            <code class="block text-xs text-gray-400 break-all mt-0.5">{{ $finding->purchaseId }}</code>
                                        </td>

                                        <td class="px-5 py-3 whitespace-nowrap text-gray-600 tabular-nums">
                                            #{{ $receipt->payment->id }}
                                            <span class="block text-xs text-gray-500">{{ $receipt->payment->sourceLabel() }}</span>
                                        </td>

                                        <td class="px-5 py-3 text-right font-semibold text-red-700 tabular-nums whitespace-nowrap">
                                            {{ $receipt->amountLabel() }}
                                        </td>

                                        <td class="px-5 py-3 whitespace-nowrap text-gray-700">
                                            {{ $receipt->payment->received_at?->format('d M Y') }}
                                            <span class="block text-xs text-gray-400">{{ $receipt->payment->received_at?->format('g:i a') }}</span>
                                        </td>

                                        <td class="px-5 py-3 text-right text-gray-900 tabular-nums whitespace-nowrap">{{ $receipt->reportedLabel() }}</td>
                                        <td class="px-5 py-3 text-right text-gray-600 tabular-nums whitespace-nowrap">{{ $receipt->recordedLabel() }}</td>
                                        <td class="px-5 py-3 text-right font-semibold text-amber-700 tabular-nums whitespace-nowrap">{{ $receipt->phantomLabel() }}</td>

                                        <td class="px-5 py-3 whitespace-nowrap">
                                            {{-- Said once per entry rather than once per row: the
                                                 badge belongs to the registration, not to the
                                                 receipt being removed. --}}
                                            @if ($index === 0)
                                                <x-admin.badge :tone="$payTones[$finding->correctedPaymentStatus()] ?? 'gray'">
                                                    {{ $finding->correctedPaymentStatusLabel() }}
                                                </x-admin.badge>
                                                <span class="block text-xs text-gray-500 mt-0.5 tabular-nums">
                                                    {{ $finding->correctedAmountPaidLabel() }} received,
                                                    {{ $finding->correctedOutstandingLabel() }} outstanding
                                                </span>
                                            @else
                                                <span class="text-xs text-gray-300" aria-hidden="true">&mdash;</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td colspan="6" class="px-5 py-3 text-right text-xs font-bold uppercase tracking-wide text-gray-500">
                                    Taken back out of the takings
                                </td>
                                <td class="px-5 py-3 text-right text-base font-bold text-amber-800 tabular-nums whitespace-nowrap">
                                    {{ \App\Support\PaymentFigures::money($phantom) }}
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </x-admin.panel>

        {{-- ---------------- Apply ---------------- --}}
        @if ($findings !== [])
            <x-admin.panel title="Remove these rows" icon="cash">
                <form action="{{ route('admin.event.participants.receipts.apply', $event) }}" method="POST"
                      class="px-5 py-4 space-y-4"
                      onsubmit="return confirm('Remove the gateway receipts listed on {{ addslashes($event->title) }}?\n\nThis takes {{ \App\Support\PaymentFigures::money($phantom) }} out of the takings because the gateway never took it. Deleting a payment record cannot be undone; every removed row is kept in the audit trail.');">
                    @csrf

                    @error('confirm')
                        <p class="rounded-lg border border-red-200 bg-red-50 px-3.5 py-2.5 text-sm text-red-800">{{ $message }}</p>
                    @enderror

                    <label class="flex items-start gap-3 rounded-lg border border-gray-300 px-3.5 py-3 cursor-pointer transition hover:border-blue-300 has-checked:border-blue-600 has-checked:bg-blue-50">
                        <input type="checkbox" name="confirm" value="1"
                               class="mt-0.5 shrink-0 rounded text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                        <span class="text-sm text-gray-900">
                            <span class="block font-semibold">
                                I have read the figures above and want these rows removed
                            </span>
                            <span class="block text-xs text-gray-600 mt-0.5">
                                Each entry's received figure is worked out again from the receipts that
                                remain, and its payment badge follows that figure: an entry still owing
                                money stops reading Paid and stops reading Confirmed, so it can be chased
                                and the balance link asks for what is actually missing.
                            </span>
                            <span class="block text-xs text-gray-600 mt-1.5">
                                Nothing recorded by hand is touched. Only rows the gateway wrote, that
                                carry no member of staff and no proof, and that the stored gateway
                                payload contradicts.
                            </span>
                        </span>
                    </label>

                    <div class="flex items-center justify-end gap-3">
                        <a href="{{ route('admin.event.participants', ['tab' => 'group', 'event' => $event->id]) }}"
                           class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                            Cancel
                        </a>

                        <button type="submit"
                                class="inline-flex items-center gap-2 bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                            <x-admin.icon name="cash" class="w-4 h-4" />
                            Remove these receipts
                        </button>
                    </div>
                </form>
            </x-admin.panel>
        @endif
    </x-admin.page-card>
@endsection
