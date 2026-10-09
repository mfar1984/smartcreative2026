{{--
    One design, drawn full size, for the inline "current design" block on the form.

    Its own file because it is rendered from two places: the form renders the current
    choice with it, and CouponDesignPickerController hands back the same markup when
    the operator picks a different design, so the block updates without a page reload
    and without a second renderer in JavaScript that could draw it differently.

    aria-hidden on the caller's side, not here: a screen reader is told which design is
    chosen in words, and a second reading of the sample figures would say nothing.

    @param \App\Models\Coupon $sample   an unsaved batch. See CouponDesignSample.
    @param string $subject              what the sample says it is for
--}}

<x-coupon.ticket :coupon="$sample" :subject="$subject" />
