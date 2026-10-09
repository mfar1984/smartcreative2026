{{--
    Choosing a coupon design, at six designs or at six hundred.

    WHY IT IS NOT A GRID ON THE FORM ANY MORE

    It was: every design drawn full width, inline, all of them. At six designs it was
    already the tallest thing on the form, and the owner is adding designs over time.
    A plain scroll box was considered and turned down for two reasons, both his: a
    fixed-height scroll area inside a form steals the swipe on a phone, where this form
    is used; and scrolling is not finding — at two hundred designs nobody scrolls to
    locate one.

    SO: the current choice sits on the form, drawn full size, with a button beside it.
    The section is the same height whatever the catalogue holds. The button opens a
    panel with group buttons and a search box, and THE PANEL RENDERS ONE GROUP. Every
    other group's previews are fetched from CouponDesignPickerController the moment the
    operator asks for one — not rendered into the page and hidden with CSS, which would
    cost the same bytes and the same layout work for designs nobody looked at.

    THE KEYBOARD PATH, END TO END

      Tab to "Change design", Enter          opens the panel, focus lands in search
      Tab                                    close button, search, group buttons, cards
      Arrows                                 move through the designs, previewing each
      Enter                                  takes the one in focus and closes
      Escape                                 closes, focus returns to "Change design"

    Arrow keys deliberately do NOT close. They move through a radio group, which is how
    somebody auditions designs without a mouse, and some browsers raise a click for
    them — closing on that would shut the panel on the first arrow press.

    @param \App\Models\Coupon $coupon
    @param string $design                             the chosen key
    @param array<string, string> $designGroups        group slug => label
    @param string $activeGroup                        the group rendered into the page
    @param array<string, string> $groupDesigns        that group's designs, key => label
    @param array<int, array<string, mixed>> $designIndex  key/label/group, for search
    @param array<string, \App\Models\Coupon> $designSamples
    @param string $designSubject
--}}

@php
    use App\Models\Coupon;

    $chosenLabel = Coupon::designLabelFor($design);
    $chosenGroup = Coupon::designGroupLabel(Coupon::designGroup($design));
    $customLabel = Coupon::designLabelFor(Coupon::DESIGN_CUSTOM);

    // The design the inline block draws. A stored key whose component has gone falls
    // back inside CouponTicket, so there is always something to draw.
    $chosenSample = $designSamples[$design] ?? $designSamples[array_key_first($designSamples)];
@endphp

<div data-design-chooser>

    {{-- ---------------- The current choice, on the form ---------------- --}}
    <div class="rounded-xl border border-gray-200 bg-gray-50/60 p-3">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-[11px] font-bold uppercase tracking-widest text-gray-500">Current design</p>
                <p class="mt-0.5 text-sm font-semibold text-gray-900" data-design-current-label>{{ $chosenLabel }}</p>
                <p class="mt-0.5 text-xs text-gray-500" data-design-current-group>
                    {{ $chosenGroup ?? 'Your own artwork' }}
                </p>
            </div>

            <button type="button" data-design-open
                    aria-haspopup="dialog"
                    aria-controls="coupon-design-panel"
                    class="shrink-0 inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-50">
                <x-admin.icon name="photo" class="w-4 h-4" />
                Change design
            </button>
        </div>

        {{-- aria-hidden: the chosen design is said in words above, and a screen reader
             reading the sample figures again would be told the same thing twice. --}}
        <div class="mt-3" data-design-current aria-hidden="true">
            @include('admin.coupon.partials.design-preview', [
                'sample' => $chosenSample,
                'subject' => $designSubject,
            ])
        </div>
    </div>

    {{-- What changed, for a screen reader. The choice is a border colour and a word in
         the panel; neither reaches somebody who cannot see the panel close. --}}
    <p class="sr-only" aria-live="polite" data-design-announce></p>

    {{--
        With scripting off, the chooser cannot open: fetching a group needs a request,
        and a panel that cannot fetch has nothing to show. Said rather than worked
        around, because the form itself is unharmed — the chosen design's radio is in
        the page and still posts, so everything else saves exactly as it reads. This is
        an admin form behind a login, and contorting the picker for a case nobody here
        is in would cost more than it is worth.
    --}}
    <noscript>
        <p class="mt-2 text-xs font-semibold text-amber-700">
            Changing the design needs JavaScript. {{ $chosenLabel }} stays as it is, and
            everything else on this form saves normally.
        </p>
    </noscript>

    {{-- ---------------- The picker ---------------- --}}
    {{--
        Inside the form on purpose: the radios in here ARE the form field. A radio
        hidden by `display: none` still posts, so the chosen one is submitted whether
        the panel is open, closed, or filtered out by a search.
    --}}
    <div id="coupon-design-panel" data-design-panel
         class="hidden fixed inset-0 z-50 overflow-y-auto"
         role="dialog" aria-modal="true" aria-labelledby="coupon-design-panel-title">

        <div class="fixed inset-0 bg-gray-900/50" data-design-close></div>

        {{-- Centred both ways. `min-h-full` with `items-center` centres a short panel
             in the viewport and still lets a tall one grow downwards, because the
             scroll lives on the wrapper above rather than on this box: a panel taller
             than the screen scrolls instead of having its top cut off. --}}
        <div class="relative flex min-h-full items-center justify-center p-4">
            <div class="w-full max-w-4xl overflow-hidden rounded-xl bg-white shadow-xl">

                {{-- Sticky rather than a scroll box around the cards: the panel itself
                     scrolls, so there is no nested scroll area to fight on a phone,
                     and the search box stays in reach while the grid moves. --}}
                <div class="sticky top-0 z-10 border-b border-gray-200 bg-white px-5 py-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h2 id="coupon-design-panel-title" class="text-base font-semibold text-gray-900">
                                Choose a design
                            </h2>
                            <p class="mt-0.5 text-xs text-gray-500">
                                Pick the shape you want, or search for it by name.
                            </p>
                        </div>

                        <button type="button" data-design-close
                                class="-mr-1 shrink-0 rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700"
                                aria-label="Close the design chooser">
                            <x-admin.icon name="close" class="w-5 h-5" />
                        </button>
                    </div>

                    <div class="mt-3">
                        <label for="coupon-design-search" class="sr-only">Search designs</label>

                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">
                                <x-admin.icon name="search" class="w-4 h-4" />
                            </span>

                            <input type="search" id="coupon-design-search" data-design-search
                                   placeholder="Search by name or group"
                                   autocomplete="off"
                                   class="w-full rounded-lg border border-gray-300 py-2.5 pl-9 pr-3.5 text-sm text-gray-900 transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/40">
                        </div>
                    </div>

                    {{--
                        Toggle buttons rather than ARIA tabs, and that is deliberate.
                        A search crosses every group, so the results are not "the
                        contents of the selected tab" and calling them that would be a
                        lie to a screen reader. Buttons are reachable and operable with
                        no key handling of our own.
                    --}}
                    <div class="mt-3 flex flex-wrap gap-1.5" role="group" aria-label="Design groups" data-design-tabs>
                        @foreach ($designGroups as $slug => $label)
                            <button type="button"
                                    data-design-tab="{{ $slug }}"
                                    aria-pressed="{{ $slug === $activeGroup ? 'true' : 'false' }}"
                                    @class([
                                        'rounded-full border px-3 py-1.5 text-xs font-semibold transition',
                                        'border-blue-600 bg-blue-600 text-white' => $slug === $activeGroup,
                                        'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => $slug !== $activeGroup,
                                    ])>
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="px-5 py-4">
                    <fieldset data-design-picker>
                        <legend class="sr-only">Coupon design</legend>

                        <div class="grid grid-cols-1 gap-3 xl:grid-cols-2" data-design-grid>
                            @include('admin.coupon.partials.design-cards', [
                                'designs' => $groupDesigns,
                                'group' => $activeGroup,
                                'groupLabel' => $designGroups[$activeGroup] ?? $activeGroup,
                                'chosen' => $design,
                                'samples' => $designSamples,
                                'subject' => $designSubject,
                            ])
                        </div>

                        <p class="hidden py-6 text-center text-sm text-gray-600" data-design-empty>
                            No design matches that. Try a shorter word, or pick a group above.
                        </p>

                        {{--
                            Custom sits outside the groups because it is not a design.
                            It is "bring your own artwork": there is no shape to find it
                            by, so it is not something to search among, and it is the
                            one option that asks for a file.
                        --}}
                        <div class="mt-4 border-t border-gray-200 pt-4">
                            <div data-design-custom
                                 class="rounded-xl border-2 border-gray-200 p-3 transition hover:border-blue-300 has-checked:border-blue-600 has-checked:bg-blue-50/60">

                                <label for="design-{{ Coupon::DESIGN_CUSTOM }}" class="flex cursor-pointer items-start gap-2.5">
                                    <input type="radio" id="design-{{ Coupon::DESIGN_CUSTOM }}"
                                           name="design" value="{{ Coupon::DESIGN_CUSTOM }}"
                                           @checked($design === Coupon::DESIGN_CUSTOM)
                                           data-design
                                           class="peer mt-0.5 shrink-0 text-blue-600 focus:ring-2 focus:ring-blue-500/40">

                                    <span class="min-w-0 text-sm text-gray-900">
                                        <span class="block font-semibold">{{ $customLabel }}</span>
                                        <span class="mt-0.5 block text-xs text-gray-600">
                                            Choose this and an upload field appears on the form below.
                                        </span>
                                    </span>

                                    <span class="ml-auto hidden shrink-0 items-center rounded-full bg-blue-600 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white peer-checked:inline-flex">
                                        Chosen
                                    </span>
                                </label>

                                <div class="mt-3 cursor-pointer" data-design-preview="{{ Coupon::DESIGN_CUSTOM }}" aria-hidden="true">
                                    <x-coupon.ticket
                                        :coupon="$designSamples[Coupon::DESIGN_CUSTOM]"
                                        :subject="$designSubject"
                                        :compact="true" />
                                </div>
                            </div>
                        </div>
                    </fieldset>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    (function () {
        const chooser = document.querySelector('[data-design-chooser]');

        if (!chooser) {
            return;
        }

        const panel = chooser.querySelector('[data-design-panel]');
        const opener = chooser.querySelector('[data-design-open]');
        const picker = chooser.querySelector('[data-design-picker]');
        const grid = chooser.querySelector('[data-design-grid]');
        const emptyNote = chooser.querySelector('[data-design-empty]');
        const search = chooser.querySelector('[data-design-search]');
        const tabs = Array.from(chooser.querySelectorAll('[data-design-tab]'));
        const preview = chooser.querySelector('[data-design-current]');
        const previewLabel = chooser.querySelector('[data-design-current-label]');
        const previewGroup = chooser.querySelector('[data-design-current-group]');
        const announce = chooser.querySelector('[data-design-announce]');

        // Lives in the Design panel on the form, not in here: the upload belongs to the
        // form, and the panel only decides which design is chosen.
        const customRow = document.querySelector('[data-custom-row]');

        /*
         | Every design as key, label and group. Text only, a few bytes each, and the
         | one thing the picker has to know about designs it has not drawn: searching
         | for a design in a group nobody has opened still has to find it.
         */
        const DESIGNS = @json($designIndex);
        const CUSTOM = @json(Coupon::DESIGN_CUSTOM);
        const CUSTOM_LABEL = @json($customLabel);
        const ENDPOINT = @json($coupon->exists
            ? route('admin.coupons.designs', $coupon)
            : route('admin.coupons.designs'));

        const loaded = new Set([@json($activeGroup)]);
        let activeGroup = @json($activeGroup);

        const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea';

        function entry(key) {
            return DESIGNS.find(function (design) {
                return design.key === key;
            });
        }

        /* -----------------------------------------------------------------
         | Loading a group
         * -------------------------------------------------------------- */

        /**
         * Fetch one group's cards and append them, once.
         *
         * The slug is claimed before the request, so two quick taps on the same group
         * cannot append it twice, and released again if the request fails so a retry
         * is possible.
         */
        async function loadGroup(slug) {
            if (loaded.has(slug)) {
                return;
            }

            loaded.add(slug);

            try {
                const response = await fetch(ENDPOINT + '?group=' + encodeURIComponent(slug), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });

                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                grid.insertAdjacentHTML('beforeend', await response.text());
            } catch (error) {
                loaded.delete(slug);
            }
        }

        /* -----------------------------------------------------------------
         | What is on show
         * -------------------------------------------------------------- */

        /**
         * Draw the group that is selected, or the matches for what is typed.
         *
         * A search crosses groups, so the groups it needs are loaded first. With no
         * search it is the selected group and nothing else.
         */
        async function render() {
            const query = (search?.value || '').trim().toLowerCase();

            const wanted = query === ''
                ? [activeGroup]
                : DESIGNS
                    .filter(function (design) {
                        return (design.label + ' ' + design.groupLabel).toLowerCase().includes(query);
                    })
                    .map(function (design) {
                        return design.group;
                    });

            await Promise.all([...new Set(wanted)].map(loadGroup));

            let shown = 0;

            grid.querySelectorAll('[data-design-card]').forEach(function (card) {
                const visible = query === ''
                    ? card.dataset.designGroup === activeGroup
                    : (card.dataset.designMatch || '').includes(query);

                card.classList.toggle('hidden', !visible);

                if (visible) {
                    shown += 1;
                }
            });

            emptyNote?.classList.toggle('hidden', shown > 0);

            tabs.forEach(function (tab) {
                // Pressed only while browsing. A search is not "this group", and a
                // button that claims to be pressed while showing something else is a
                // lie to whoever is reading it rather than looking at it.
                const pressed = query === '' && tab.dataset.designTab === activeGroup;

                tab.setAttribute('aria-pressed', pressed ? 'true' : 'false');
                tab.classList.toggle('border-blue-600', pressed);
                tab.classList.toggle('bg-blue-600', pressed);
                tab.classList.toggle('text-white', pressed);
                tab.classList.toggle('border-gray-300', !pressed);
                tab.classList.toggle('bg-white', !pressed);
                tab.classList.toggle('text-gray-700', !pressed);
                tab.classList.toggle('hover:bg-gray-50', !pressed);
            });
        }

        /* -----------------------------------------------------------------
         | Opening and closing
         * -------------------------------------------------------------- */

        function open() {
            panel.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');

            // Into the search box, because at any size of catalogue that is where the
            // work starts.
            search?.focus();

            render();
        }

        function close() {
            panel.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');

            // Back where it came from, or focus is left on a node nobody can see.
            opener?.focus();
        }

        function isOpen() {
            return !panel.classList.contains('hidden');
        }

        opener?.addEventListener('click', open);

        chooser.querySelectorAll('[data-design-close]').forEach(function (trigger) {
            trigger.addEventListener('click', close);
        });

        document.addEventListener('keydown', function (event) {
            if (!isOpen()) {
                return;
            }

            if (event.key === 'Escape') {
                close();

                return;
            }

            if (event.key !== 'Tab') {
                return;
            }

            // Hold Tab inside the panel. offsetParent weeds out the cards a group
            // switch or a search has hidden, which are not reachable and must not be
            // stopped on.
            const stops = Array.from(panel.querySelectorAll(FOCUSABLE)).filter(function (el) {
                return el.offsetParent !== null;
            });

            if (stops.length === 0) {
                return;
            }

            const first = stops[0];
            const last = stops[stops.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });

        /* -----------------------------------------------------------------
         | Searching and grouping
         * -------------------------------------------------------------- */

        search?.addEventListener('input', render);

        // Enter in a text field submits the form it sits in, and this one sits in the
        // coupon form. Saving a coupon because somebody finished typing a search is
        // not what was asked for.
        search?.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
            }
        });

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                activeGroup = tab.dataset.designTab;

                // Asking for a group is asking to browse it, so the search that was
                // crossing every group is cleared rather than quietly narrowing it.
                if (search) {
                    search.value = '';
                }

                render();
            });
        });

        /* -----------------------------------------------------------------
         | Choosing
         * -------------------------------------------------------------- */

        /** The inline block, the two lines above it and the upload row follow the radio. */
        async function applyChoice(value) {
            const chosen = entry(value);
            const label = chosen ? chosen.label : (value === CUSTOM ? CUSTOM_LABEL : value);

            if (previewLabel) {
                previewLabel.textContent = label;
            }

            if (previewGroup) {
                previewGroup.textContent = chosen ? chosen.groupLabel : 'Your own artwork';
            }

            if (announce) {
                announce.textContent = label + ' chosen.';
            }

            customRow?.classList.toggle('hidden', value !== CUSTOM);

            if (!preview) {
                return;
            }

            // Fetched rather than copied out of the card: the card holds the compact
            // rendering and this one is full size, so it has to come from the component
            // instead of from a scaled-up copy of its output.
            try {
                const response = await fetch(ENDPOINT + '?design=' + encodeURIComponent(value), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });

                if (response.ok) {
                    preview.innerHTML = await response.text();
                }
            } catch (error) {
                // The words above it are already right, which is the part that has to
                // be true. A stale drawing is better than an empty box.
            }
        }

        // Delegated, so the cards fetched for a group behave like the ones that came
        // with the page.
        picker.addEventListener('change', function (event) {
            const radio = event.target.closest('[data-design]');

            if (radio) {
                applyChoice(radio.value);
            }
        });

        picker.addEventListener('click', function (event) {
            /*
             | A pointer click on a card is a decision, so the panel closes on it.
             |
             | detail is 0 for a click the browser synthesised from a key, which is what
             | keeps the arrow keys working: they move through the radio group and raise
             | a click of their own in some browsers, and closing on that would shut the
             | panel on the first press and leave no way to audition a design.
             */
            if (event.detail === 0) {
                return;
            }

            const card = event.target.closest('[data-design-card], [data-design-custom]');

            if (!card) {
                return;
            }

            /*
             | Clicking the preview picks that design.
             |
             | A convenience on top of the radio, never instead of it: the preview sits
             | outside the label because a figure may not live inside one, so the label
             | does not forward the click and this sets the radio by hand. change has to
             | be dispatched because setting checked in script does not raise it.
             */
            if (event.target.closest('[data-design-preview]')) {
                const radio = card.querySelector('[data-design]');

                if (radio && !radio.checked) {
                    radio.checked = true;
                    radio.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }

            close();
        });

        picker.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') {
                return;
            }

            const radio = event.target.closest('[data-design]');

            if (!radio) {
                return;
            }

            // Enter inside a form submits it. In here it means "this one", so it takes
            // the design in focus and closes instead.
            event.preventDefault();

            if (!radio.checked) {
                radio.checked = true;
                radio.dispatchEvent(new Event('change', { bubbles: true }));
            }

            close();
        });

        /*
         | Reopen when the save came back with a complaint about the design.
         |
         | The message renders against the field on the form, and leaving the panel shut
         | would mean the one control that could answer it is behind a button.
         */
        @error('design')
            open();
        @enderror
    })();
</script>
@endpush
