@extends('layouts.legal')

@section('document')
    <p class="mb-4">
        Most of what we sell is not posted anywhere. A registration is delivered
        electronically and confirmed by email. This page explains that, and then covers the
        physical items some events offer alongside an entry.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">Registrations are delivered electronically</h2>

    <p class="mb-4">
        When your payment succeeds, your entry is confirmed on screen straight away and a
        confirmation email is sent to the address on the registration. Nothing is posted to
        you and there is no delivery charge.
    </p>

    <p class="mb-4">
        Your confirmation email carries your registration reference, which begins with
        REG‑. Keep it. It is what we look you up by at the venue and what you quote in any
        message to us.
    </p>

    <h3 class="text-base font-semibold text-gray-900 mt-6 mb-2">If the confirmation does not arrive</h3>

    <p class="mb-4">
        Check the spam or junk folder first, since a message from an address you have never
        written to often lands there. If it is not there within an hour of paying, email us
        with the name and event you registered under and we will resend it. A missing email
        does not mean a missing entry; your place is held from the moment the payment
        clears.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">Collecting things on the day</h2>

    <p class="mb-4">
        Where an event includes something physical as part of the entry, such as a bib, a
        wristband, a lanyard or a goody bag, it is collected in person at the venue and is
        not posted. Collection times and the place to go are sent to registered participants
        before the event.
    </p>

    <p class="mb-4">
        Bring the identity document you registered with. We hand items to the named
        participant, or to someone you have told us in writing will collect for you.
    </p>

    <p class="mb-4">
        Anything not collected during the event is not held indefinitely. Contact us within
        14 days of the event if you missed collection and we will arrange something if we
        still have it.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">Items we do post</h2>

    <p class="mb-4">
        Some events offer merchandise as an add‑on, and a prize sometimes has to be sent
        rather than handed over. Where posting applies, the following holds.
    </p>

    <h3 class="text-base font-semibold text-gray-900 mt-6 mb-2">Where we post to</h3>

    <p class="mb-4">
        Within Malaysia, using a courier or Pos Malaysia. We do not currently post outside
        Malaysia. If your address is outside Malaysia, contact us before ordering and we
        will tell you whether it can be arranged and what it would cost.
    </p>

    <h3 class="text-base font-semibold text-gray-900 mt-6 mb-2">How long it takes</h3>

    <ul class="list-disc pl-6 mb-4 space-y-1.5">
        <li>Items held in stock are dispatched within 3 to 5 working days of payment clearing</li>
        <li>Items made to order, such as a printed jersey, are dispatched within 14 working days</li>
        <li>Prizes and event merchandise are dispatched after the event has finished and results are final</li>
        <li>Once dispatched, allow 3 to 5 working days for Peninsular Malaysia and 5 to 10 working days for Sabah and Sarawak</li>
    </ul>

    <p class="mb-4">
        These are working days and exclude weekends and public holidays. Festive periods and
        courier backlogs stretch them, and we will say so if we know a delay is coming.
    </p>

    <h3 class="text-base font-semibold text-gray-900 mt-6 mb-2">Delivery charges</h3>

    <p class="mb-4">
        Any delivery charge is shown before you pay, as a separate line from the item. If
        nothing is shown, there is nothing to pay.
    </p>

    <h3 class="text-base font-semibold text-gray-900 mt-6 mb-2">Tracking</h3>

    <p class="mb-4">
        Where the courier provides a tracking number we email it to you on dispatch. Not
        every service gives one.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">Getting the address right</h2>

    <p class="mb-4">
        We post to the address on your registration exactly as you entered it. Check it
        before you pay. Tell us immediately if it is wrong, and we will correct it if the
        parcel has not left us.
    </p>

    <p class="mb-4">
        If a parcel is returned to us because the address was incomplete or nobody was there
        to receive it, we will contact you to arrange another attempt. A second delivery
        charge is payable in that case, because we have to pay the courier twice.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">If something arrives damaged or does not arrive</h2>

    <p class="mb-4">
        Tell us within 7 days of receiving a damaged or wrong item and include a photograph.
        If tracking shows delivered but you have nothing, tell us within 14 days of that
        date so we can raise it with the courier while they still hold records.
    </p>

    <p class="mb-4">
        In either case we replace the item or refund it in full. See the
        <a href="{{ route('legal.refund') }}" class="text-blue-600 hover:text-blue-800 font-semibold">Refund Policy</a>
        for how the money is returned.
    </p>
@endsection
