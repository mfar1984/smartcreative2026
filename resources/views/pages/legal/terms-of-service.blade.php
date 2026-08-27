@extends('layouts.legal')

@section('document')
    <p class="mb-4">
        These terms apply when you use this site and when you register for an event through
        it. Registering means you accept them, on your own behalf and on behalf of anyone you
        enter as part of a team.
    </p>

    <p class="mb-4">
        In these terms, "we" and "us" mean Smart Digital Creative Management &amp; Resources,
        whose details are at the bottom of this page.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">No account is needed</h2>

    <p class="mb-4">
        You register by filling in a form. There is no public account to create and no
        password to keep, so nothing on this site can be accessed by someone who knows a
        password of yours.
    </p>

    <p class="mb-4">
        The links we email you, such as a payment page, are signed and specific to your
        registration. Treat them as private. Anyone you forward one to can see what was
        entered and what is owed.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">The details you give must be true</h2>

    <p class="mb-4">
        Names, identity card numbers, dates of birth and categories must be accurate. We
        check identity documents at the venue against what was registered. If they do not
        match we may refuse entry, and no refund is due in that case.
    </p>

    <p class="mb-4">
        If you enter other people as part of a team, you confirm you have their permission to
        give us their details, and that you have shown them our
        <a href="{{ route('legal.privacy') }}" class="text-blue-600 hover:text-blue-800 font-semibold">Privacy Policy</a>.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">Age and eligibility</h2>

    <p class="mb-4">
        Each event states who may enter. Where a competitor is under 18, the entry must be
        made or approved by a parent or guardian, and the emergency contact given must be an
        adult reachable during the event.
    </p>

    <p class="mb-4">
        Choosing a category you are not eligible for is your responsibility. Eligibility is
        published with the event.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">Fees and payment</h2>

    <p class="mb-4">
        Fees are shown in Malaysian Ringgit on the registration page, including any add‑ons
        you choose. Payment is taken through CHIP, our payment gateway. Card details are
        entered on the gateway's own page; we never receive them.
    </p>

    <p class="mb-4">
        Your place is held when the payment clears, not when the form is submitted. Until
        then the entry sits unpaid and the place is not reserved. Where an event limits
        places, an unpaid entry can be overtaken by a paid one.
    </p>

    <p class="mb-4">
        A free event has nothing to pay and is confirmed on submission.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">When the agreement is formed</h2>

    <p class="mb-4">
        Submitting a form is an offer to enter. The agreement forms when we confirm your
        entry, which normally happens automatically once payment clears. We may decline an
        entry, and we will return anything paid if we do.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">Refunds, cancellation and postponement</h2>

    <p class="mb-4">
        These are set out in the
        <a href="{{ route('legal.refund') }}" class="text-blue-600 hover:text-blue-800 font-semibold">Refund Policy</a>,
        which forms part of these terms. Delivery of anything physical is covered by the
        <a href="{{ route('legal.shipping') }}" class="text-blue-600 hover:text-blue-800 font-semibold">Shipping Policy</a>.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">How competitions are run</h2>

    <p class="mb-4">
        Each event publishes its own format, rules and scoring. Draws, fixtures and standings
        are produced from those rules.
    </p>

    <p class="mb-4">
        Scores can be corrected after they are first published, because a mistake found later
        is still a mistake. When a correction changes standings, the standings are rebuilt
        from the corrected scores. A result is final once we publish it as final, and only
        then is it recorded in the Hall of Fame.
    </p>

    <p class="mb-4">
        Decisions of officials and referees during play are final. Protests must be made in
        the manner and within the time the event's rules set out.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">Conduct, and being removed</h2>

    <p class="mb-4">
        We may remove you from an event, without a refund, for cheating, for using an account
        or identity that is not yours, for abusive or threatening behaviour towards anyone
        present, for damaging property, for competing while unfit or intoxicated, or for
        ignoring safety instructions.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">Photography and filming</h2>

    <p class="mb-4">
        Our events are photographed and filmed, and the results may be used to report on the
        event and to promote future ones. Competing means accepting you may appear in that
        material.
    </p>

    <p class="mb-4">
        If you would rather not appear, tell us in writing before the event and we will
        respect it where we reasonably can. We cannot guarantee it in wide shots of a crowd,
        and we cannot control what other attendees or the press record.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">Results are published</h2>

    <p class="mb-4">
        Competitor names, team names and standings are published on this site, including in
        rankings and the Hall of Fame, and they stay published. If you do not want your name
        shown against a result, do not enter.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">Content and intellectual property</h2>

    <p class="mb-4">
        The design, text, images and code of this site belong to us or to our licensors. You
        may read and print pages for your own use. You may not copy, republish or reuse them
        commercially without our written permission.
    </p>

    <p class="mb-4">
        A team logo you upload stays yours. By uploading it you confirm you have the right to
        use it, and you allow us to display it wherever your team appears, including in
        fixtures, results and promotional material for the event. You are responsible if it
        infringes someone else's rights.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">Using this site properly</h2>

    <p class="mb-4">Do not:</p>

    <ul class="list-disc pl-6 mb-4 space-y-1.5">
        <li>Submit entries you do not intend to pay for, or submit them in bulk to occupy places</li>
        <li>Try to reach parts of the site you are not authorised to reach</li>
        <li>Interfere with the site's operation, or place unreasonable load on it</li>
        <li>Scrape or harvest participant details</li>
        <li>Impersonate anyone, or register using someone else's identity</li>
    </ul>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">Availability</h2>

    <p class="mb-4">
        We aim to keep the site available but do not promise it will be uninterrupted. We may
        take it down for maintenance. We are not liable for a registration you could not
        complete because the site, or the payment gateway, was unavailable, though we will
        extend a deadline where it is fair to do so.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">Liability</h2>

    <p class="mb-4">
        Taking part in an event carries risk, and you accept the ordinary risks of the
        activity you entered. You are responsible for being fit to take part and for telling
        us of a condition that affects your safety.
    </p>

    <p class="mb-4">
        Nothing here limits our liability for death or personal injury caused by our
        negligence, for fraud, or for anything else the law does not allow us to limit.
    </p>

    <p class="mb-4">
        Subject to that, our total liability arising from an event or from your use of this
        site is limited to the amount you paid for the registration concerned. We are not
        liable for indirect losses such as travel or accommodation you booked, lost earnings,
        or lost sponsorship.
    </p>

    <p class="mb-4">
        We are not responsible for the belongings you bring to a venue.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">Events run for someone else</h2>

    <p class="mb-4">
        We sometimes run registration and scoring on behalf of another organiser. Where that
        is the case the event page says so, and that organiser's rules govern the competition
        itself. These terms still govern your use of this site and the payment you make
        through it.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">Governing law</h2>

    <p class="mb-4">
        These terms are governed by the laws of Malaysia, and the courts of Malaysia have
        jurisdiction. Nothing here removes rights you have under Malaysian consumer law.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">Changes to these terms</h2>

    <p class="mb-4">
        We may revise these terms, and the date at the top of the page will move when we do.
        The version that applies to your registration is the one published when you
        registered.
    </p>

    <p class="mb-4">
        If any part of these terms turns out to be unenforceable, the rest continues to
        apply.
    </p>
@endsection
