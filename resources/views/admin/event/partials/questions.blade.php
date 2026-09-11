{{--
    Builder for the questions this event asks on its own registration form.

    Rows are named questions[i][...] and new ones are cloned from the <template> at
    the bottom with __QINDEX__ swapped for a counter. The index only has to be
    unique within one submission, so gaps left by removing a row are harmless.

    Shaped like the add-ons and product-options builders on purpose: same clone
    pattern, same counter, so there is one behaviour to learn rather than three.

    @param \App\Models\Event $event
    @param string            $input
--}}
@php
    // old() wins so a failed submission comes back as it was typed, otherwise
    // fall back to what is stored.
    $questionRows = old('questions');

    if ($questionRows === null) {
        $questionRows = $event->exists
            ? $event->questions
                ->map(fn ($question) => [
                    'id' => $question->id,
                    'title' => $question->title,
                    'body' => $question->body,
                    'is_required' => $question->is_required ? '1' : '0',
                    'answers' => $question->answers()->count(),
                ])
                ->values()
                ->all()
            : [];
    }

    $questionRows = is_array($questionRows) ? $questionRows : [];
@endphp

<x-admin.panel title="Registration Questions" icon="check">
    <div class="px-5 py-4">
        <p class="text-sm text-gray-600">
            Boxes each person ticks at the end of the registration form. Use one for a
            terms agreement they cannot submit without, and others for anything you
            want to ask, such as whether they are an existing customer.
        </p>

        <p class="text-sm text-gray-500 mt-2">
            Every person on an entry is asked, so a squad of seven gives seven answers.
            Answers are recorded against each person, and the wording is stored with
            them, so editing a question later never changes what somebody already
            agreed to.
        </p>

        @error('questions')
            <p class="text-xs text-red-600 mt-2">{{ $message }}</p>
        @enderror
    </div>

    <div class="px-5 pb-5">
        <div id="question-list" class="space-y-3">
            @foreach ($questionRows as $i => $row)
                @include('admin.event.partials.question-row', [
                    'qindex' => $i,
                    'row' => $row,
                    'input' => $input,
                ])
            @endforeach
        </div>

        <div id="question-empty" @class([
            'rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-8 text-center',
            'hidden' => count($questionRows) > 0,
        ])>
            <x-admin.icon name="check" class="w-6 h-6 mx-auto text-gray-400" />
            <p class="text-sm font-semibold text-gray-600 mt-2">No questions yet</p>
            <p class="text-xs text-gray-500 mt-0.5">
                The form asks nothing extra. Add a question if you need a terms
                agreement or want to survey the people entering.
            </p>
        </div>

        <button type="button" id="question-add"
                class="mt-4 inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm font-semibold text-blue-700 hover:bg-blue-100 transition">
            <x-admin.icon name="plus" class="w-4 h-4" />
            Add a Question
        </button>
    </div>
</x-admin.panel>

{{-- ------------------------------------------------------------------
     Template for cloning. Inert until the script copies it, so the
     __QINDEX__ placeholder is never submitted.
     ----------------------------------------------------------------- --}}
<template id="question-template">
    @include('admin.event.partials.question-row', [
        'qindex' => '__QINDEX__',
        'row' => [],
        'input' => $input,
    ])
</template>
