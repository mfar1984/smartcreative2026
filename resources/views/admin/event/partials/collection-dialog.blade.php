{{--
    Handing one entry's items over.

    One dialog per entry rather than per row, because a handover is a batch: a
    grouping of six takes six shirts with one person standing at the desk, one
    identity card checked and one code read out. The rows are tick boxes inside it, so
    the operator can hand over one, several or all of them in a single press.

    The markup contract is deliberately the shop's — the same data-collector-*
    attributes its Confirm Collection dialog uses, and the same copy voice — because
    this is the same act at the same counter. The behaviour behind it is this screen's
    own script, because the rules differ: there is an entry-level payment gate here
    that a shop order cannot have, a shop order being unpayable at collection time.

    @param \App\Models\EventRegistration $entry
--}}
@php
    use App\Models\CollectionHandover;
    use App\Support\HandoverSheet;

    $rows = HandoverSheet::outstandingFor($entry);

    $field = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';

    $owes = $entry->owesBalance();
@endphp

@if ($rows !== [])
    <div id="collect-entry-{{ $entry->id }}" class="hidden fixed inset-0 z-50 overflow-y-auto"
         role="dialog" aria-modal="true" aria-labelledby="collect-entry-title-{{ $entry->id }}">
        <div class="fixed inset-0 bg-gray-900/50" data-close-dialog></div>

        <div class="relative min-h-full flex items-start justify-center p-4">
            <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl my-8">
                <div class="flex items-center justify-between gap-4 px-6 py-4 border-b border-gray-200">
                    <h2 id="collect-entry-title-{{ $entry->id }}" class="text-base font-bold text-gray-900">
                        Hand Over
                    </h2>
                    <button type="button" data-close-dialog
                            class="p-1 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition"
                            aria-label="Close">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <form action="{{ route('admin.event.collection.hand-over', $entry) }}" method="POST"
                      class="p-6 space-y-4"
                      data-collection-form="{{ $entry->id }}"
                      data-owes="{{ $owes ? '1' : '0' }}">
                    @csrf

                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm space-y-1">
                        <p class="font-semibold text-gray-900 tabular-nums">{{ $entry->reference }}</p>
                        <p class="text-gray-700">{{ $entry->displayName() }}</p>
                        <p class="text-gray-600">{{ $entry->event?->title }}</p>
                    </div>

                    {{-- Money owed, said plainly and before anything else. Not a hard
                         block: a part-paid grouping will turn up on the day with their
                         shirts already ordered, and a flat refusal strands six people
                         at a counter that cannot take the balance either. Not silent
                         permission either, which is why the reason box below is
                         required to get past it. --}}
                    @if ($owes)
                        <div class="rounded-lg border border-red-300 bg-red-50 px-4 py-3">
                            <p class="text-sm font-bold text-red-900">
                                {{ $entry->outstandingAmountLabel() }} still owed on this entry
                            </p>
                            <p class="text-xs text-red-700 mt-1">
                                {{ $entry->paymentStatusLabel() }}. Take the payment first if you can.
                                Handing the goods over anyway needs a reason, and the record will say so.
                            </p>

                            <div class="mt-3">
                                <label for="payment_override_reason-{{ $entry->id }}" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                    Why It Is Going Out Unpaid
                                </label>
                                <input type="text" id="payment_override_reason-{{ $entry->id }}"
                                       name="payment_override_reason" maxlength="255"
                                       value="{{ old('payment_override_reason') }}"
                                       placeholder="e.g. paying the balance by transfer tonight, agreed with the organiser"
                                       data-collection-payment-reason
                                       class="{{ $field }}">
                                @error('payment_override_reason')
                                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    @endif

                    {{-- The rows. Ticked by whichever button opened this: the row
                         button ticks one, the entry button ticks all. --}}
                    <fieldset>
                        <legend class="block text-sm font-semibold text-gray-700 mb-2">
                            What is being handed over
                        </legend>

                        <div class="space-y-2">
                            @foreach ($rows as $row)
                                @php
                                    $needsChoice = $row['collects_choice'] && $row['chosen'] === null;
                                    $variants = $row['addon']->variants;
                                @endphp

                                <div class="rounded-lg border border-gray-200 px-3.5 py-2.5">
                                    <label class="flex items-start gap-2.5 cursor-pointer">
                                        <input type="checkbox" name="rows[]" value="{{ $row['key'] }}"
                                               data-collection-row="{{ $row['key'] }}"
                                               class="mt-0.5 w-4 h-4 rounded text-green-600 border-gray-300 focus:ring-green-500">
                                        <span class="min-w-0">
                                            <span class="block text-sm font-semibold text-gray-900">
                                                {{ $row['participant']->full_name }}
                                            </span>
                                            <span class="block text-xs text-gray-500 tabular-nums mt-0.5">
                                                IC {{ $row['participant']->ic_number ?: 'not recorded' }}
                                            </span>
                                            <span class="block text-xs text-gray-600 mt-0.5">
                                                {{ $row['addon']->name }}@if ($row['option'] !== null) &middot; {{ $row['option'] }}@endif
                                            </span>
                                        </span>
                                    </label>

                                    {{-- The choice, taken at the desk. Most people
                                         never answered the size link, and a missing
                                         option must not hold the queue up. Recorded
                                         through the same writer the admin modal uses,
                                         so the stock moves exactly once and an option
                                         priced differently from the one on record is
                                         refused rather than quietly re-pricing what
                                         this entry owes. --}}
                                    @if ($needsChoice && $variants->isNotEmpty())
                                        <div class="mt-2.5 pl-6">
                                            <label for="size-{{ $entry->id }}-{{ $row['key'] }}"
                                                   class="block text-xs font-semibold text-red-700 mb-1">
                                                No option recorded — take it now
                                            </label>
                                            <select id="size-{{ $entry->id }}-{{ $row['key'] }}"
                                                    name="sizes[{{ $row['participant']->id }}][{{ $row['addon']->id }}]"
                                                    class="{{ $field }}">
                                                <option value="">Choose {{ strtolower($row['addon']->name) }}...</option>
                                                @foreach ($variants as $variant)
                                                    @php $left = $variant->stockLeft(); @endphp
                                                    <option value="{{ $variant->id }}" @disabled($variant->isSoldOut())>
                                                        {{ $variant->label }}@if ($left !== null) ({{ $left }} left)@endif
                                                    </option>
                                                @endforeach
                                            </select>
                                            @error('sizes.' . $row['participant']->id . '.' . $row['addon']->id)
                                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    @elseif ($needsChoice)
                                        <p class="mt-2 pl-6 text-xs text-red-600">
                                            This item has no options set up, so nothing can be recorded here.
                                        </p>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        @error('rows')
                            <p class="text-xs text-red-600 mt-1.5">{{ $message }}</p>
                        @enderror
                    </fieldset>

                    {{-- Who is collecting. Same two cases the shop separates, and for
                         the same reasons. --}}
                    <div data-collector="{{ $entry->id }}"
                         data-code-url="{{ route('admin.event.collection.code', $entry) }}">

                        <fieldset>
                            <legend class="block text-sm font-semibold text-gray-700 mb-2">
                                Who is collecting?
                            </legend>

                            <div class="space-y-2">
                                {{-- Default. The person named on the row taking their
                                     own item is already verified by the identity card
                                     check this counter has always run: an SMS code
                                     there costs counter time and proves nothing the
                                     card does not. --}}
                                <label class="flex items-start gap-2.5 rounded-lg border border-gray-200 px-3.5 py-2.5 cursor-pointer hover:bg-gray-50 transition">
                                    <input type="radio" name="collector" value="{{ CollectionHandover::KIND_BUYER }}" checked
                                           id="collector-self-{{ $entry->id }}"
                                           data-collector-choice="{{ CollectionHandover::KIND_BUYER }}"
                                           class="mt-0.5 w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500">
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold text-gray-900">
                                            Each person above, taking their own
                                        </span>
                                        <span class="block text-xs text-gray-500 mt-0.5">
                                            Check every identity card you have ticked against the document you are shown. No code needed.
                                        </span>
                                    </span>
                                </label>

                                <label class="flex items-start gap-2.5 rounded-lg border border-gray-200 px-3.5 py-2.5 cursor-pointer hover:bg-gray-50 transition">
                                    <input type="radio" name="collector" value="{{ CollectionHandover::KIND_OTHER }}"
                                           id="collector-other-{{ $entry->id }}"
                                           data-collector-choice="{{ CollectionHandover::KIND_OTHER }}"
                                           class="mt-0.5 w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500">
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold text-gray-900">
                                            One person, collecting for the others
                                        </span>
                                        <span class="block text-xs text-gray-500 mt-0.5">
                                            Their own identity card, and a code texted to their phone before anything is handed over.
                                            Every row you tick is recorded against their name.
                                        </span>
                                    </span>
                                </label>
                            </div>
                        </fieldset>

                        {{-- Only on screen once somebody says a representative is
                             taking them, so the common case stays two presses. --}}
                        <div data-collector-details
                             class="hidden mt-3 space-y-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3.5">

                            <p class="text-xs text-amber-800">
                                Record who is actually taking the goods. If a shirt goes missing and the person
                                it belonged to says they never had it, this is the only thing that answers for it.
                            </p>

                            <div>
                                <label for="collector_name-{{ $entry->id }}" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                    Their Full Name
                                </label>
                                <input type="text" id="collector_name-{{ $entry->id }}" name="collector_name" maxlength="190"
                                       value="{{ old('collector_name') }}" autocomplete="off"
                                       data-collector-field="name"
                                       class="{{ $field }}">
                                @error('collector_name')
                                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label for="collector_ic-{{ $entry->id }}" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                        Their IC Number
                                    </label>
                                    <input type="text" id="collector_ic-{{ $entry->id }}" name="collector_ic" maxlength="30"
                                           value="{{ old('collector_ic') }}" autocomplete="off"
                                           data-collector-field="ic"
                                           class="{{ $field }} tabular-nums">
                                    @error('collector_ic')
                                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="collector_phone-{{ $entry->id }}" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                        Their Phone Number
                                    </label>
                                    <input type="tel" id="collector_phone-{{ $entry->id }}" name="collector_phone" maxlength="30"
                                           value="{{ old('collector_phone') }}" autocomplete="off"
                                           placeholder="017-859 1411"
                                           data-collector-field="phone"
                                           class="{{ $field }} tabular-nums">
                                    @error('collector_phone')
                                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            {{-- Sent straight out rather than queued, so a failure
                                 shows up here and now instead of being discovered
                                 after the person has walked off. One code covers every
                                 row ticked above. --}}
                            <div class="rounded-lg border border-amber-300 bg-white px-3.5 py-3">
                                <div class="flex flex-wrap items-end gap-3">
                                    <div class="flex-1 min-w-40">
                                        <label for="code-{{ $entry->id }}" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                            Code They Read Out
                                        </label>
                                        <input type="text" id="code-{{ $entry->id }}" name="code"
                                               inputmode="numeric" maxlength="6" autocomplete="one-time-code"
                                               placeholder="000000"
                                               data-collector-code
                                               class="{{ $field }} tabular-nums tracking-widest">
                                    </div>

                                    <button type="button" data-collector-send
                                            class="shrink-0 inline-flex items-center gap-2 rounded-lg border border-amber-400 bg-amber-100 px-4 py-2.5 text-sm font-semibold text-amber-900 hover:bg-amber-200 transition">
                                        <x-admin.icon name="send" class="w-4 h-4" />
                                        Send Code
                                    </button>
                                </div>

                                @error('code')
                                    <p class="text-xs text-red-600 mt-2">{{ $message }}</p>
                                @enderror

                                {{-- aria-live, so the gateway's answer is announced
                                     rather than only drawn. Staff at a counter are not
                                     watching this corner of the screen. --}}
                                <p data-collector-status role="status" aria-live="polite"
                                   class="hidden text-xs mt-2"></p>

                                <p class="text-xs text-gray-500 mt-2">
                                    Six digits, texted to the number above. It expires, and it stops working
                                    after a few wrong tries.
                                </p>
                            </div>

                            {{-- The way out, and it has to exist. Stadium signal is
                                 poor, numbers get mistyped and gateways go down; a
                                 counter with no way through will record the collector
                                 as the participant instead, and then the record is a
                                 lie. An audited override is worth far more than that. --}}
                            <details class="rounded-lg border border-red-200 bg-white px-3.5 py-3">
                                <summary class="text-sm font-semibold text-red-700 cursor-pointer">
                                    The code will not go through
                                </summary>

                                <div class="mt-3">
                                    <label for="override_reason-{{ $entry->id }}" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                        Why You Are Skipping The Code
                                    </label>
                                    <input type="text" id="override_reason-{{ $entry->id }}" name="override_reason" maxlength="255"
                                           value="{{ old('override_reason') }}"
                                           placeholder="e.g. no signal in the hall, number no longer in use"
                                           data-collector-reason
                                           class="{{ $field }}">
                                    @error('override_reason')
                                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                                    @enderror
                                    <p class="text-xs text-gray-500 mt-1">
                                        Fill this in and the handover goes through without a code, marked on the
                                        record as handed over without SMS verification, against your name.
                                    </p>
                                </div>
                            </details>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                        <button type="button" data-close-dialog
                                class="px-4 py-2.5 rounded-lg border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                            Cancel
                        </button>
                        {{-- Disabled by the script while a required box is empty. A
                             courtesy only: the route refuses every one of the same
                             things, because a disabled button is not a control. --}}
                        <button type="submit" data-collection-submit
                                class="inline-flex items-center gap-2 bg-green-600 text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:bg-green-700 transition shadow-sm">
                            <x-admin.icon name="check" class="w-4 h-4" />
                            Handed Over
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif
