{{--
    The screenshot reader's picker and its review panel, for one kind of screen.

    THREE READERS SHARE THIS. The results table, the Star of the Match screen and
    the per-player result screen ask different questions of different images, but
    the promise is one promise and so is the furniture: pick the files outside the
    form so nothing chosen here is posted with the result, then show every figure
    against what is on the form now, then Apply types it in and saves nothing.

    The picker and the panel travel together because each reader has exactly one of
    each, and where the pair SITS matters. Both panels are fixed at z-50, and the
    later of two positioned elements at the same level paints over the earlier one,
    so a reader that fills in a dialog has to be placed after that dialog in the
    document or its review panel hides behind the thing it is filling in.

    Every hook on it is derived from $prefix, which is what lets one piece of markup
    serve three scripts: data-sheet-apply, data-award-sheet-apply and so on.

    @param string $prefix  the data-attribute family, e.g. "award-sheet"
    @param string $id      the panel's element id, e.g. "award-sheet-dialog"
    @param string $url     the endpoint the files are posted to
    @param string $picker  the file input's element id
    @param string $pick    the label on that input, for a screen reader
    @param string $about   what this panel is reviewing, under the heading
    @param string $note    the line in the footer, which says what Apply does not do
    @param slot   $slot    the boxes the reader's own script fills in
--}}
@props([
    'prefix',
    'id',
    'url',
    'picker',
    'pick',
    'about',
    'note',
])

{{-- Hidden because the control the operator presses is the Detect Image AI button
     beside the figures it fills in; this is only what that button opens. --}}
<div data-{{ $prefix }} class="hidden" data-{{ $prefix }}-url="{{ $url }}">
    <label for="{{ $picker }}" class="sr-only">{{ $pick }}</label>

    {{-- The same four types the upload rules accept, so the file chooser offers
         what the server will take rather than letting somebody pick a PDF and be
         refused after the upload. --}}
    <input type="file" id="{{ $picker }}" accept="{{ \App\Services\Vision\ScoreSheetImage::MIME_TYPES }}" multiple>
</div>

{{-- The review step, and the reason this feature is allowed to exist. Every row is
     shown with what is on the form now beside what was read, and a row the matcher
     was not sure of arrives unticked. --}}
<div id="{{ $id }}"
     class="fixed inset-0 z-50 hidden items-center justify-center bg-gray-900/60 p-4"
     role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title">

    <div class="w-full max-w-3xl rounded-xl bg-white shadow-xl overflow-hidden max-h-full flex flex-col">
        <div class="flex items-start justify-between gap-4 px-5 py-4 border-b border-gray-200">
            <div>
                <h2 id="{{ $id }}-title" class="text-base font-bold text-gray-900">
                    What the screenshot says
                </h2>
                <p class="text-sm text-gray-500 mt-0.5">{{ $about }}</p>
            </div>

            <button type="button" data-{{ $prefix }}-close
                    class="shrink-0 rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition"
                    aria-label="Close">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="overflow-y-auto px-5 py-4">
            {{ $slot }}
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-t border-gray-200 bg-gray-50">
            <p class="text-xs text-gray-500 max-w-sm">{{ $note }}</p>

            <div class="flex flex-wrap gap-2.5">
                <button type="button" data-{{ $prefix }}-close
                        class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                    Cancel
                </button>
                <button type="button" data-{{ $prefix }}-apply
                        class="rounded-lg border border-blue-600 bg-blue-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm">
                    Apply
                </button>
            </div>
        </div>
    </div>
</div>
