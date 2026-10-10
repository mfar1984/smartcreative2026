@extends('layouts.admin')

@php
    use App\Support\PaymentFigures;

    $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
@endphp

@section('title', 'Sponsorship')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Sponsorship</span>
@endsection

@section('content')
    {{-- What the office sees here, and why it is not their own figures.

         This screen is one sponsorship at a time, and a staff account funds nothing —
         so landing them on "their own" would be four zeroes and an empty table, which
         reads as a broken screen. They get the list instead, and open the one they
         came to read. --}}
    <x-admin.page-card
        title="Sponsorship"
        description="Every sponsorship account, and what each one has really given away. Open one to read its blocks and who used its coupons."
        :flush="true">

        <div class="px-5 pt-5">
            @include('admin.partials.flash')
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <caption class="sr-only">
                    Sponsorship accounts, what each one pledged and what has actually been used.
                </caption>

                <thead class="bg-gray-50 text-left">
                    <tr>
                        <th scope="col" class="{{ $head }}">Sponsorship</th>
                        <th scope="col" class="{{ $head }}">Funded</th>
                        <th scope="col" class="{{ $head }} text-right">Committed</th>
                        <th scope="col" class="{{ $head }} text-right">Actually used</th>
                        <th scope="col" class="{{ $head }} text-center">Open</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @forelse ($sponsors as $row)
                        @php
                            // CouponSponsorship's own figures, the same class the
                            // sponsor's own screen and the staff Report read, so no
                            // two screens can disagree about somebody's money.
                            $row_figures = $figures[$row->id] ?? null;

                            /*
                             | Blocks and shared codes counted APART, the way the
                             | Sponsorship tab counts them, because they are not the
                             | same kind of thing: a shared batch has no block for
                             | anybody to hold.
                             */
                            $fundedParts = [];

                            if (($row_figures['blocks'] ?? 0) > 0) {
                                $fundedParts[] = number_format($row_figures['blocks'])
                                    . ' ' . Str::plural('block', $row_figures['blocks']);
                            }

                            if (($row_figures['shared_batches'] ?? 0) > 0) {
                                $fundedParts[] = number_format($row_figures['shared_batches'])
                                    . ' shared ' . Str::plural('code', $row_figures['shared_batches']);
                            }

                            if ($fundedParts === []) {
                                $fundedParts[] = 'nothing tagged yet';
                            }
                        @endphp

                        <tr class="hover:bg-blue-50/40 align-top">
                            <td class="px-5 py-3">
                                <span class="block font-semibold text-gray-900">{{ $row->name }}</span>
                                <code class="block text-xs text-gray-500">{{ $row->username }}</code>
                            </td>

                            <td class="px-5 py-3 text-gray-600">
                                {{ implode(' · ', $fundedParts) }}
                                <span class="block text-xs text-gray-400">
                                    {{ sprintf(
                                        '%s of %s %s used',
                                        number_format($row_figures['used'] ?? 0),
                                        number_format($row_figures['codes'] ?? 0),
                                        Str::plural('code', $row_figures['codes'] ?? 0),
                                    ) }}
                                </span>
                            </td>

                            <td class="px-5 py-3 text-right text-gray-600 tabular-nums whitespace-nowrap">
                                {{ ($row_figures['committed'] ?? null) === null ? '—' : PaymentFigures::money($row_figures['committed']) }}
                            </td>

                            <td class="px-5 py-3 text-right font-semibold text-gray-900 tabular-nums whitespace-nowrap">
                                {{ PaymentFigures::money($row_figures['actual'] ?? 0) }}
                            </td>

                            <td class="px-5 py-3 text-center whitespace-nowrap">
                                <a href="{{ route('admin.sponsorship.index', ['sponsor' => $row->id]) }}"
                                   class="text-xs font-semibold text-blue-600 hover:underline">
                                    Open
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-12 text-center">
                                <x-admin.icon name="cash" class="w-10 h-10 mx-auto text-gray-300" />
                                <p class="text-sm font-semibold text-gray-700 mt-3">There are no sponsorship accounts yet</p>
                                <p class="text-sm text-gray-500 mt-1">
                                    They are created under
                                    <a href="{{ route('admin.settings.users', ['tab' => 'sponsorship']) }}"
                                       class="font-semibold text-blue-600 hover:underline">User Management &rarr; Sponsorship</a>.
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-5 py-3.5 border-t border-gray-200">
            @if ($sponsors->hasPages())
                {{ $sponsors->links() }}
            @else
                <p class="text-xs text-gray-500">
                    Showing {{ $sponsors->count() }} {{ Str::plural('sponsorship', $sponsors->count()) }}.
                </p>
            @endif
        </div>
    </x-admin.page-card>
@endsection
