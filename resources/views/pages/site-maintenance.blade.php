<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $heading }} &middot; Smart Digital Creative</title>

    @include('partials.favicon')

    @vite(['resources/css/app.css'])
</head>
<body class="bg-white">
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="w-full max-w-lg text-center">
            <img src="{{ asset('images/logo.png') }}" alt="Smart Digital Creative" class="h-12 w-auto mx-auto mb-8">

            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-8">
                <svg class="w-16 h-16 mx-auto text-blue-600 mb-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>

                <h1 class="text-2xl md:text-3xl font-bold text-gray-900 mb-3">{{ $heading }}</h1>
                <p class="text-base text-gray-600 leading-relaxed">{{ $message }}</p>
            </div>

            <p class="text-xs text-gray-500 mt-6">
                Smart Digital Creative Management &amp; Resources &copy; {{ date('Y') }}
            </p>
        </div>
    </div>
</body>
</html>
