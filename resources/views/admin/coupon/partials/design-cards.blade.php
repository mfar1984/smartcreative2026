{{--
    The designs of ONE group, as pickable cards.

    Rendered by the coupon form for the group the picker opens on, and by
    CouponDesignPickerController for every group the operator tabs to after that. One
    partial, so a fetched group is drawn exactly like the one that came with the page.

    EVERY CARD CARRIES A REAL RADIO, labelled by its name, so the keyboard reaches the
    group and arrows through it and the posted field is still `design` with the values
    it has always had. The chosen one says "Chosen" in words beside the border colour,
    because a colour is not an announcement.

    The preview sits OUTSIDE the label on purpose. A figure is flow content and a label
    may only hold phrasing content, so wrapping one in the other would be invalid
    markup; it is aria-hidden decoration with a click handler instead, which is how
    this form already did it. A screen reader gets the design's name, not a second
    reading of the sample figures.

    No `required` on the radios on purpose. One design is always checked, so the
    browser has nothing to enforce, and a required radio that a search has filtered out
    is one the browser cannot focus to complain about — it would refuse to submit with
    no visible reason. CouponRequest requires the field server side, and the message
    renders against it.

    @param array<string, string> $designs  key => label, this group only
    @param string $group                   group slug
    @param string $groupLabel              group label, which search also matches on
    @param string $chosen                  the design currently chosen, if it is here
    @param array<string, \App\Models\Coupon> $samples  one sample per design
    @param string $subject                 what the samples say they are for
--}}

@foreach ($designs as $value => $label)
    <div data-design-card="{{ $value }}"
         data-design-group="{{ $group }}"
         data-design-match="{{ \Illuminate\Support\Str::lower($label . ' ' . $groupLabel) }}"
         class="rounded-xl border-2 border-gray-200 p-3 transition hover:border-blue-300 has-checked:border-blue-600 has-checked:bg-blue-50/60">

        <label for="design-{{ $value }}" class="flex cursor-pointer items-start gap-2.5">
            <input type="radio" id="design-{{ $value }}" name="design" value="{{ $value }}"
                   @checked($chosen === $value)
                   data-design
                   class="peer mt-0.5 shrink-0 text-blue-600 focus:ring-2 focus:ring-blue-500/40">

            <span class="min-w-0 text-sm font-semibold text-gray-900">{{ $label }}</span>

            <span class="ml-auto hidden shrink-0 items-center rounded-full bg-blue-600 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white peer-checked:inline-flex">
                Chosen
            </span>
        </label>

        <div class="mt-3 cursor-pointer" data-design-preview="{{ $value }}" aria-hidden="true">
            <x-coupon.ticket
                :coupon="$samples[$value]"
                :subject="$subject"
                :compact="true" />
        </div>
    </div>
@endforeach
