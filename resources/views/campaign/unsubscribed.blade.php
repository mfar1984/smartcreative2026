<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Unsubscribed &middot; {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-6">
    <div class="w-full max-w-md bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">

        <div class="px-6 py-5 bg-blue-700">
            <p class="text-base font-bold text-white">{{ config('app.name') }}</p>
        </div>

        <div class="p-6 text-center">
            <span class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-green-100 mb-4">
                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            </span>

            <h1 class="text-lg font-bold text-gray-900 mb-2">Done</h1>

            <p class="text-sm text-gray-600">
                We will not send marketing messages to
                <strong class="break-all">{{ $contact?->email ?: $contact?->phone }}</strong>
                again.
            </p>

            <p class="text-xs text-gray-500 mt-4">
                If you change your mind, the box appears again the next time you register
                for an event.
            </p>

            <a href="{{ url('/') }}"
               class="mt-5 inline-block rounded-lg border border-gray-300 px-5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                Back to the site
            </a>
        </div>
    </div>
</body>
</html>
