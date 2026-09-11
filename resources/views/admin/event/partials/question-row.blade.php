{{--
    One question on the event form.

    @param int|string $qindex  row index, or __QINDEX__ inside the clone template
    @param array      $row     existing values, empty for a new row
    @param string     $input   shared input classes
--}}
@php
    $name = "questions[{$qindex}]";
    $answered = (int) ($row['answers'] ?? 0);
@endphp

<div data-question-row class="rounded-lg border border-gray-200 p-4">

    {{-- Only present for a saved question, so an edit updates the row in place
         rather than deleting it and orphaning the answers already given. --}}
    @if (! empty($row['id']))
        <input type="hidden" name="{{ $name }}[id]" value="{{ $row['id'] }}">
    @endif

    <div class="flex items-start justify-between gap-3 mb-3">
        <div class="flex-1 min-w-0">
            <label class="block text-xs font-semibold text-gray-600 mb-1">Title</label>
            <input type="text" name="{{ $name }}[title]" maxlength="190"
                   value="{{ $row['title'] ?? '' }}"
                   placeholder="e.g. Rules &amp; Terms"
                   class="{{ $input }}">
            @error("questions.{$qindex}.title")
                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <button type="button" data-question-remove
                @if ($answered > 0) data-question-answered="{{ $answered }}" @endif
                class="mt-6 p-2 rounded-lg text-red-600 hover:bg-red-50 transition shrink-0"
                aria-label="Remove this question">
            <x-admin.icon name="trash" class="w-4 h-4" />
        </button>
    </div>

    <div class="mb-3">
        <label class="block text-xs font-semibold text-gray-600 mb-1">
            Wording <span class="font-normal text-gray-400">(optional)</span>
        </label>
        <textarea name="{{ $name }}[body]" rows="4"
                  placeholder="The terms themselves, or an explanation. Leave blank if the title says it all."
                  class="{{ $input }} resize-y">{{ $row['body'] ?? '' }}</textarea>
        @error("questions.{$qindex}.body")
            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
        @enderror
    </div>

    {{-- A hidden 0 first, because an unticked box sends nothing and the absence
         would otherwise read as "leave it as it was". --}}
    <input type="hidden" name="{{ $name }}[is_required]" value="0">

    <label class="flex items-start gap-2.5 cursor-pointer">
        <input type="checkbox" name="{{ $name }}[is_required]" value="1"
               @checked(($row['is_required'] ?? '0') === '1' || ($row['is_required'] ?? false) === true)
               class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-400 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
        <span class="text-xs text-gray-700">
            Compulsory
            <span class="block text-gray-500 mt-0.5">
                The form is refused until this is ticked. Leave off for a question they
                may answer either way.
            </span>
        </span>
    </label>

    @if ($answered > 0)
        <p class="text-xs text-gray-500 mt-3 pt-3 border-t border-gray-100">
            {{ $answered }} {{ $answered === 1 ? 'person has' : 'people have' }} answered this.
            Editing the wording does not change what they were shown; their answers keep
            a copy of it.
        </p>
    @endif
</div>
