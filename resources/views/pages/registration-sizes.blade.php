@extends('layouts.master')
@section('title', $pageTitle)

{{--
    Confirming a shirt size for everybody on one registration.

    Reached by a signed link in an email, with no login. There is no money anywhere on
    this page and it says so twice: these registrants have been receiving payment
    reminders for the same event, and the one thing they must not think is that this is
    another bill.

    Already recorded sizes come back selected, so the same page collects a missing
    answer and corrects a wrong one.
--}}

@php
    $rowsByPerson = collect($rows)->groupBy(fn (array $row) => $row['participant']->id);

    $outstanding = collect($rows)->whereNull('chosen')->count();
@endphp

@section('content')
    @include('components.page-header', [
        'title' => $pageTitle,
        'subtitle' => $pageSubtitle,
    ])

    <section class="py-16 bg-gray-50">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mx-auto">

                {{-- ---------------- What this is, and what it is not ---------------- --}}
                <div role="note" class="flex items-start gap-3 bg-blue-50 border border-blue-200 rounded-lg p-5 mb-8">
                    <svg class="w-6 h-6 shrink-0 text-blue-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <div>
                        <p class="text-base font-bold text-blue-900 mb-1">This is only to confirm a shirt size</p>
                        <p class="text-sm text-blue-800">
                            No payment is needed and nothing is charged here. We are asking because
                            the size was not collected when this registration was made, and the
                            organiser needs it to order and hand out the shirts.
                        </p>
                    </div>
                </div>

                @if (session('status'))
                    <div role="status" class="flex items-start gap-3 bg-green-50 border border-green-200 rounded-lg p-5 mb-8">
                        <svg class="w-6 h-6 shrink-0 text-green-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <p class="text-sm text-green-800">{{ session('status') }}</p>
                    </div>
                @endif

                @if ($errors->any())
                    <div role="alert" class="bg-red-50 border border-red-200 rounded-lg p-5 mb-8">
                        <p class="text-sm font-bold text-red-900 mb-1">Nothing was saved</p>
                        <ul class="text-sm text-red-800 space-y-0.5">
                            @foreach ($errors->all() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">

                    <div class="flex flex-wrap items-start justify-between gap-4 px-6 py-5 border-b border-gray-200 bg-gray-50">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Event</p>
                            <h2 class="text-xl font-bold text-gray-900 mt-0.5">{{ $event?->title }}</h2>
                            @if ($event?->starts_at)
                                <p class="text-sm text-gray-600 mt-1">
                                    {{ $event->starts_at->format('d M Y') }}
                                    @if ($event->ends_at && ! $event->starts_at->isSameDay($event->ends_at))
                                        &ndash; {{ $event->ends_at->format('d M Y') }}
                                    @endif
                                    @if (filled($event->location))
                                        &middot; {{ $event->location }}
                                    @endif
                                </p>
                            @endif
                        </div>

                        <div class="text-right shrink-0">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Reference</p>
                            <p class="text-base font-bold text-gray-900 mt-0.5">{{ $registration->reference }}</p>
                            <p class="text-xs text-gray-500 mt-1">
                                {{ $registration->participants->count() }}
                                {{ $registration->participants->count() === 1 ? 'person' : 'people' }}
                            </p>
                        </div>
                    </div>

                    @if ($rows === [])
                        <div class="px-6 py-10 text-center">
                            <p class="text-base font-semibold text-gray-900">There is nothing to confirm</p>
                            <p class="text-sm text-gray-600 mt-1">
                                This event is not collecting sizes. You can close this page.
                            </p>
                        </div>
                    @else
                        <form method="POST" action="{{ $action }}">
                            @csrf

                            <div class="px-6 py-5 space-y-5">
                                @if ($outstanding === 0)
                                    <p class="text-sm text-gray-600">
                                        Every size below is already on record. Change one if it is wrong,
                                        otherwise there is nothing further to do.
                                    </p>
                                @endif

                                @foreach ($rowsByPerson as $personRows)
                                    @php $person = $personRows->first()['participant']; @endphp

                                    <fieldset class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                                        <legend class="px-1 text-sm font-bold text-gray-900">
                                            {{ $person->full_name }}
                                            <span class="ml-1 font-normal text-xs text-gray-500">{{ $person->roleLabel() }}</span>
                                        </legend>

                                        @foreach ($personRows as $row)
                                            @php
                                                $addon = $row['addon'];
                                                $chosen = $row['chosen'];
                                                $group = sprintf('sizes[%d][%d]', $person->id, $addon->id);
                                                $errorKey = sprintf('sizes.%d.%d', $person->id, $addon->id);

                                                // Required only when something can actually be picked. A
                                                // card whose every option is sold out would otherwise make
                                                // the whole page impossible to submit.
                                                $selectable = $addon->variants->contains(fn ($variant) => ! $variant->isSoldOut());
                                            @endphp

                                            <div class="mt-3 first:mt-2">
                                                <p class="text-xs font-semibold text-gray-700 mb-2">
                                                    {{ $addon->name }}
                                                    @if ($chosen === null)
                                                        <span class="ml-1 font-normal text-amber-700">not confirmed yet</span>
                                                    @else
                                                        <span class="ml-1 font-normal text-gray-500">
                                                            currently {{ $row['line']?->variant_label }}
                                                        </span>
                                                    @endif
                                                </p>

                                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                                    @foreach ($addon->variants as $variant)
                                                        @php
                                                            $sold = $variant->isSoldOut() && (int) $chosen !== $variant->id;
                                                            $left = $variant->stockLeft();
                                                            $optionId = sprintf('size-%d-%d-%d', $person->id, $addon->id, $variant->id);
                                                        @endphp

                                                        <label for="{{ $optionId }}" @class([
                                                            'flex items-center gap-2 rounded-lg border px-3 py-2 transition',
                                                            'border-gray-200 bg-white cursor-pointer hover:border-blue-300 hover:bg-blue-50' => ! $sold,
                                                            'border-gray-100 bg-gray-50 text-gray-400 cursor-not-allowed' => $sold,
                                                        ])>
                                                            <input type="radio" id="{{ $optionId }}"
                                                                   name="{{ $group }}"
                                                                   value="{{ $variant->id }}"
                                                                   @checked((int) $chosen === $variant->id)
                                                                   @disabled($sold)
                                                                   @required($selectable)
                                                                   class="h-4 w-4 shrink-0 border-gray-300 text-blue-600 focus:ring-blue-500">
                                                            <span class="min-w-0 flex-1 text-sm">{{ $variant->label }}</span>
                                                            @if ($sold)
                                                                <span class="text-xs font-semibold text-red-600">Sold out</span>
                                                            @elseif ($left !== null && $left <= 10)
                                                                <span class="text-xs text-amber-700">{{ $left }} left</span>
                                                            @endif
                                                        </label>
                                                    @endforeach
                                                </div>

                                                @error($errorKey)
                                                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                                                @enderror
                                            </div>
                                        @endforeach
                                    </fieldset>
                                @endforeach
                            </div>

                            <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 flex flex-wrap items-center justify-between gap-3">
                                <p class="text-xs text-gray-500">
                                    Nothing is charged by this. Your entry and anything already paid are unaffected.
                                </p>

                                <button type="submit"
                                        class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                    </svg>
                                    Confirm {{ $outstanding === 1 || count($rows) === 1 ? 'size' : 'sizes' }}
                                </button>
                            </div>
                        </form>
                    @endif
                </div>

                <p class="text-xs text-gray-500 mt-4">
                    If any of the names above is wrong, or you did not expect this, reply to the
                    email that brought you here and we will sort it out.
                </p>
            </div>
        </div>
    </section>
@endsection
