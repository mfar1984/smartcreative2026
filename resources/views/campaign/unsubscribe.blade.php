{{--
    Shown when somebody presses the unsubscribe link.

    A page rather than an immediate action, because mail clients fetch links to
    build previews and to scan for threats. Acting on that fetch would remove
    people who never pressed anything.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Stop receiving messages &middot; {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-6">
    <div class="w-full max-w-md bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">

        <div class="px-6 py-5 bg-blue-700">
            <p class="text-base font-bold text-white">{{ config('app.name') }}</p>
        </div>

        <div class="p-6">
            @if ($alreadyDone)
                <h1 class="text-lg font-bold text-gray-900 mb-2">You are already unsubscribed</h1>
                <p class="text-sm text-gray-600">
                    We stopped sending marketing messages to
                    <strong class="break-all">{{ $contact?->email ?: $contact?->phone }}</strong>
                    on {{ $contact?->unsubscribed_at?->format('d M Y') }}. There is nothing more to do.
                </p>
            @else
                <h1 class="text-lg font-bold text-gray-900 mb-2">Stop receiving these messages?</h1>

                <p class="text-sm text-gray-600 mb-4">
                    We will stop sending news, event invitations and offers to
                    <strong class="break-all">{{ $recipient->address }}</strong>.
                </p>

                {{-- Said plainly, because somebody unsubscribing usually wants the
                     marketing to stop and not the confirmation of something they
                     have paid for. --}}
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3.5 mb-5">
                    <p class="text-xs text-gray-600">
                        You will still receive messages about entries you make: confirmations,
                        payment receipts and reminders about an event you have signed up for.
                        Those are part of the registration, not marketing.
                    </p>
                </div>

                <form action="{{ route('campaign.unsubscribe.confirm', $recipient->token) }}" method="POST">
                    @csrf
                    <button type="submit"
                            class="w-full rounded-lg bg-red-600 px-5 py-3 text-sm font-semibold text-white hover:bg-red-700 transition">
                        Yes, unsubscribe me
                    </button>
                </form>

                <a href="{{ url('/') }}"
                   class="mt-3 block w-full text-center rounded-lg border border-gray-300 px-5 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                    No, keep me on the list
                </a>
            @endif
        </div>
    </div>
</body>
</html>
