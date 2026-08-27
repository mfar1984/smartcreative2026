<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign In &middot; Smart Digital Creative Admin</title>

    @include('partials.favicon')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100">
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="w-full max-w-md">

            {{-- Brand --}}
            <div class="text-center mb-8">
                {{-- Uploaded under Settings, General Config, Branding. --}}
                <img src="{{ App\Support\BrandingSettings::loginLogo() }}"
                     alt="{{ config('app.name') }}" class="h-10 w-auto mx-auto mb-4">
                <h1 class="text-2xl font-bold text-gray-900">Admin Sign In</h1>
                <p class="text-sm text-gray-600 mt-1">Enter your credentials to continue</p>
            </div>

            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-8">

                @if (session('status'))
                    <div role="alert" class="flex items-start gap-3 bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
                        <svg class="w-5 h-5 shrink-0 text-green-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <p class="text-sm text-green-800">{{ session('status') }}</p>
                    </div>
                @endif

                @if ($errors->any())
                    <div role="alert" class="flex items-start gap-3 bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
                        <svg class="w-5 h-5 shrink-0 text-red-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        <p class="text-sm text-red-800">{{ $errors->first() }}</p>
                    </div>
                @endif

                <form action="{{ route('admin.login.attempt') }}" method="POST" class="space-y-5">
                    @csrf

                    <div>
                        <label for="username" class="block text-sm font-semibold text-gray-700 mb-1.5">
                            Username
                        </label>
                        <input type="text"
                               id="username"
                               name="username"
                               value="{{ old('username') }}"
                               required
                               autofocus
                               autocomplete="username"
                               maxlength="120"
                               @error('username') aria-invalid="true" @enderror
                               class="w-full rounded-lg border @error('username') border-red-500 @else border-gray-300 @enderror px-4 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-semibold text-gray-700 mb-1.5">
                            Password
                        </label>
                        <input type="password"
                               id="password"
                               name="password"
                               required
                               autocomplete="current-password"
                               @error('password') aria-invalid="true" @enderror
                               class="w-full rounded-lg border @error('password') border-red-500 @else border-gray-300 @enderror px-4 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                    </div>

                    <label for="remember" class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox"
                               id="remember"
                               name="remember"
                               value="1"
                               @checked(old('remember'))
                               class="rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40">
                        <span class="text-sm text-gray-600">Keep me signed in</span>
                    </label>

                    <button type="submit"
                            class="w-full inline-flex items-center justify-center gap-2 bg-blue-600 text-white px-8 py-3 rounded-lg font-semibold hover:bg-blue-700 transition shadow-md">
                        Sign In
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                        </svg>
                    </button>
                </form>
            </div>

            <p class="text-center text-xs text-gray-500 mt-6">
                Smart Digital Creative Management &amp; Resources &copy; {{ date('Y') }}
            </p>
        </div>
    </div>
</body>
</html>
