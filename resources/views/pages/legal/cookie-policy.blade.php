@extends('layouts.legal')

@section('document')
    <p class="mb-4">
        This site sets two cookies. Both are ours, both are necessary for it to work, and
        neither is used for advertising or to build a profile of you.
    </p>

    <p class="mb-4">
        There is no Google Analytics here, no advertising pixel, and no social media tracker.
        That is not a promise about the future written vaguely; it is what the site does
        today, and this page will change if that ever changes.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">The two cookies</h2>

    <div class="overflow-x-auto mb-4">
        <table class="w-full text-base border border-gray-200">
            <thead>
                <tr class="bg-gray-50">
                    <th scope="col" class="text-left font-semibold text-gray-900 px-4 py-3 border-b border-gray-200">Name</th>
                    <th scope="col" class="text-left font-semibold text-gray-900 px-4 py-3 border-b border-gray-200">What it does</th>
                    <th scope="col" class="text-left font-semibold text-gray-900 px-4 py-3 border-b border-gray-200">How long it lasts</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="px-4 py-3 border-b border-gray-200 align-top">
                        <code class="text-sm text-gray-900">smart-digital-creative-session</code>
                    </td>
                    <td class="px-4 py-3 border-b border-gray-200 align-top">
                        Remembers your visit from one page to the next, so a half‑filled
                        registration form and any message we need to show you survive moving
                        between pages.
                    </td>
                    <td class="px-4 py-3 border-b border-gray-200 align-top">2 hours</td>
                </tr>
                <tr>
                    <td class="px-4 py-3 align-top">
                        <code class="text-sm text-gray-900">XSRF-TOKEN</code>
                    </td>
                    <td class="px-4 py-3 align-top">
                        Proves a form was submitted from this site by you, and not by another
                        site quietly submitting it on your behalf. Without it we could not
                        safely accept a registration or a payment instruction.
                    </td>
                    <td class="px-4 py-3 align-top">2 hours</td>
                </tr>
            </tbody>
        </table>
    </div>

    <p class="mb-4">
        Both are set only for this domain, so no other website can read them. Both are marked
        <code class="text-sm">SameSite=Lax</code>, which stops them being sent when another
        site links into ours in the background. The session cookie is additionally marked
        <code class="text-sm">HttpOnly</code>, so scripts running in the page cannot read it.
    </p>

    <p class="mb-4">
        Neither contains your name, your email address or anything else about you. The session
        cookie holds a random identifier; the data it points at stays on our server.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">Why there is no cookie banner</h2>

    <p class="mb-4">
        A banner exists to ask permission for cookies that are not necessary, typically
        analytics and advertising. We set none of those, so there is nothing to ask you about,
        and a banner would only be theatre.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">If you block them</h2>

    <p class="mb-4">
        You can block or delete cookies in your browser settings. Blocking these two will
        break registration: form submissions will be rejected as unverified, and you will not
        be able to pay. Reading the site will still work.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">Tracking in our email is not a cookie</h2>

    <p class="mb-4">
        Our marketing email does contain an invisible image and rewritten links, which tell us
        whether a message was opened and which links were pressed. That happens inside your
        mail program, not in your browser, and it sets no cookie on this site.
    </p>

    <p class="mb-4">
        It applies to marketing email only, never to a confirmation or a receipt. Blocking
        images in your mail program stops the open tracking, and the
        <a href="{{ route('legal.privacy') }}" class="text-blue-600 hover:text-blue-800 font-semibold">Privacy Policy</a>
        explains it in full.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mt-10 mb-4">Our payment gateway</h2>

    <p class="mb-4">
        Paying takes you to CHIP, our payment gateway, on their own address. What they set
        while you are there is governed by their policies, not ours. You return to this site
        once the payment finishes.
    </p>
@endsection
