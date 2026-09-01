<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0f172a">
    <title>Sign In &middot; Smart Digital Creative Admin</title>

    @include('partials.favicon')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-50 antialiased">

@php
    // Null until somebody uploads one under Settings, General Config, Branding.
    // Checked rather than printed blind, because an empty src renders a broken
    // image icon, which is worse than the wordmark it falls back to.
    $logo = App\Support\BrandingSettings::loginLogo();

    /*
     | What this panel claims the system does. Kept to modules that actually
     | exist, so the sign in screen cannot promise something the sidebar does
     | not deliver once you are through it.
     */
    $capabilities = [
        ['icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z', 'label' => 'Events & Registration'],
        ['icon' => 'M8 21h8m-4-4v4m-5.2-4h10.4a1.8 1.8 0 001.8-1.8V4.8A1.8 1.8 0 0017.2 3H6.8A1.8 1.8 0 005 4.8v10.4A1.8 1.8 0 006.8 17z', 'label' => 'Tournaments & Scoring'],
        ['icon' => 'M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z', 'label' => 'Shop & Orders'],
        ['icon' => 'M3 8l7.9 5.3a2 2 0 002.2 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z', 'label' => 'Campaigns & Messaging'],
    ];
@endphp

<div class="min-h-full lg:grid lg:grid-cols-[1.1fr_1fr]">

    {{-- ==================== Brand panel ====================
         Hidden below lg. On a phone it would push the form off the fold, and the
         form is the only thing anybody came here to use. --}}
    <aside class="relative hidden overflow-hidden bg-slate-950 lg:flex lg:flex-col lg:justify-between lg:p-14">

        {{-- Decoration. aria-hidden and pointer-events-none so none of it can be
             tabbed into or read out. --}}
        <div class="pointer-events-none absolute inset-0" aria-hidden="true">
            <div class="absolute -left-32 -top-32 h-[28rem] w-[28rem] rounded-full bg-blue-600/30 blur-3xl"></div>
            <div class="absolute -bottom-40 -right-24 h-[26rem] w-[26rem] rounded-full bg-indigo-500/25 blur-3xl"></div>
            <div class="absolute left-1/3 top-1/2 h-72 w-72 rounded-full bg-sky-400/10 blur-3xl"></div>

            {{-- Faint grid. Written as an inline gradient rather than a utility so
                 the line weight stays exact at any zoom. --}}
            <div class="absolute inset-0 opacity-[0.06]"
                 style="background-image:linear-gradient(to right,#fff 1px,transparent 1px),linear-gradient(to bottom,#fff 1px,transparent 1px);background-size:56px 56px"></div>

            {{-- Fades the grid out toward the bottom so it does not fight the text. --}}
            <div class="absolute inset-0 bg-gradient-to-t from-slate-950 via-transparent to-transparent"></div>
        </div>

        {{-- Logo --}}
        <div class="relative">
            @if ($logo)
                {{-- On a white plate rather than inverted. The mark is black with a
                     red accent, and forcing it white to sit on the dark panel would
                     flatten the red out of the brand. --}}
                <span class="inline-flex rounded-xl bg-white px-4 py-2.5 shadow-lg shadow-black/20">
                    <img src="{{ $logo }}" alt="{{ config('app.name') }}" class="h-8 w-auto">
                </span>
            @else
                <div class="flex items-center gap-3">
                    <span class="grid h-10 w-10 place-items-center rounded-xl bg-blue-600 text-sm font-bold text-white shadow-lg shadow-blue-600/30">
                        SC
                    </span>
                    <span class="text-base font-semibold tracking-tight text-white">
                        Smart Digital Creative
                    </span>
                </div>
            @endif
        </div>

        {{-- Headline --}}
        <div class="relative max-w-lg">
            <p class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-medium text-blue-200 backdrop-blur">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                Admin Control Panel
            </p>

            <h2 class="mt-6 text-4xl font-bold leading-tight tracking-tight text-white">
                Everything you run,
                <span class="bg-gradient-to-r from-blue-300 to-sky-200 bg-clip-text text-transparent">in one place.</span>
            </h2>

            <p class="mt-4 text-sm leading-relaxed text-slate-400">
                Registrations, brackets, payments, parcels and mailouts. Managed from a
                single panel, with every change written to the activity log.
            </p>

            <ul class="mt-9 grid gap-3 sm:grid-cols-2">
                @foreach ($capabilities as $capability)
                    <li class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-3.5 py-3 backdrop-blur">
                        <svg class="h-4 w-4 shrink-0 text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $capability['icon'] }}"/>
                        </svg>
                        <span class="text-xs font-medium text-slate-200">{{ $capability['label'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>

        {{-- Foot --}}
        <p class="relative text-xs text-slate-500">
            Registration: 202303326459 / 003562257-U
        </p>
    </aside>

    {{-- ==================== Form ==================== --}}
    <main class="flex min-h-full items-center justify-center px-6 py-12 sm:px-10">
        <div class="w-full max-w-sm">

            {{-- Logo again for small screens, where the panel above is hidden. --}}
            <div class="mb-10 lg:hidden">
                @if ($logo)
                    <img src="{{ $logo }}" alt="{{ config('app.name') }}" class="h-9 w-auto">
                @else
                    <div class="flex items-center gap-3">
                        <span class="grid h-10 w-10 place-items-center rounded-xl bg-blue-600 text-sm font-bold text-white">SC</span>
                        <span class="text-base font-semibold tracking-tight text-slate-900">Smart Digital Creative</span>
                    </div>
                @endif
            </div>

            <h1 class="text-2xl font-bold tracking-tight text-slate-900">Sign in</h1>
            <p class="mt-1.5 text-sm text-slate-500">
                Use the account issued to you by an administrator.
            </p>

            @if (session('status'))
                <div role="status" class="mt-6 flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3.5">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="text-sm text-emerald-800">{{ session('status') }}</p>
                </div>
            @endif

            {{-- Every failure lands on the username key: wrong password, unknown
                 account, lockout, and no admin access. One banner covers them. --}}
            @if ($errors->any())
                <div role="alert" class="mt-6 flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-3.5">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <p class="text-sm text-red-800">{{ $errors->first() }}</p>
                </div>
            @endif

            <form action="{{ route('admin.login.attempt') }}" method="POST" class="mt-8 space-y-5" data-login-form>
                @csrf

                <div>
                    <label for="username" class="mb-1.5 block text-sm font-semibold text-slate-700">
                        Username
                    </label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-0 grid w-11 place-items-center text-slate-400">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                            </svg>
                        </span>
                        {{-- Not type=email on purpose: usernames here look like
                             "administrator@root", which no email validator accepts. --}}
                        <input type="text"
                               id="username"
                               name="username"
                               value="{{ old('username') }}"
                               required
                               autofocus
                               autocomplete="username"
                               spellcheck="false"
                               maxlength="120"
                               placeholder="your.username"
                               @error('username') aria-invalid="true" @enderror
                               class="w-full rounded-xl border bg-white py-2.5 pl-11 pr-4 text-sm text-slate-900 placeholder:text-slate-400 transition focus:outline-none focus:ring-4 @error('username') border-red-400 focus:border-red-500 focus:ring-red-500/15 @else border-slate-300 focus:border-blue-500 focus:ring-blue-500/15 @enderror">
                    </div>
                </div>

                <div>
                    <label for="password" class="mb-1.5 block text-sm font-semibold text-slate-700">
                        Password
                    </label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-0 grid w-11 place-items-center text-slate-400">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                            </svg>
                        </span>
                        <input type="password"
                               id="password"
                               name="password"
                               required
                               autocomplete="current-password"
                               placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;"
                               @error('password') aria-invalid="true" @enderror
                               class="w-full rounded-xl border bg-white py-2.5 pl-11 pr-12 text-sm text-slate-900 placeholder:text-slate-400 transition focus:outline-none focus:ring-4 @error('password') border-red-400 focus:border-red-500 focus:ring-red-500/15 @else border-slate-300 focus:border-blue-500 focus:ring-blue-500/15 @enderror">

                        <button type="button"
                                data-toggle-password
                                aria-controls="password"
                                aria-pressed="false"
                                aria-label="Show password"
                                class="absolute inset-y-0 right-0 grid w-11 place-items-center rounded-r-xl text-slate-400 transition hover:text-slate-600 focus:outline-none focus-visible:ring-4 focus-visible:ring-blue-500/15">
                            <svg data-icon-show class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/>
                            </svg>
                            <svg data-icon-hide class="hidden h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 3l18 18M10.6 10.6a3 3 0 004.2 4.2M9.4 5.8A9.6 9.6 0 0112 5.5c6 0 9.5 6.5 9.5 6.5a17 17 0 01-2.4 3.3M6.2 7.9A17 17 0 002.5 12S6 18.5 12 18.5c.9 0 1.7-.1 2.5-.4"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <label for="remember" class="flex cursor-pointer items-center gap-2.5 select-none">
                    <input type="checkbox"
                           id="remember"
                           name="remember"
                           value="1"
                           @checked(old('remember'))
                           class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-4 focus:ring-blue-500/15">
                    <span class="text-sm text-slate-600">Keep me signed in</span>
                </label>

                <button type="submit"
                        data-submit
                        class="group inline-flex w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-blue-600/25 transition hover:bg-blue-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-blue-500/30 disabled:cursor-not-allowed disabled:opacity-70">
                    <svg data-spinner class="hidden h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                    </svg>
                    <span data-label>Sign in</span>
                    <svg data-arrow class="h-4 w-4 transition-transform group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7-7 7M21 12H3"/>
                    </svg>
                </button>
            </form>

            <div class="mt-8 flex items-start gap-2.5 rounded-xl bg-slate-100 p-3.5">
                <svg class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v4m0 4h.01M12 3l9 16H3l9-16z"/>
                </svg>
                {{-- States the real limit from LoginRequest rather than a vague
                     warning, so somebody locked out knows how long to wait. --}}
                <p class="text-xs leading-relaxed text-slate-500">
                    Five failed attempts locks this username for a minute. Every sign in,
                    and every refusal, is recorded.
                </p>
            </div>

            <p class="mt-8 text-center text-xs text-slate-400">
                Smart Digital Creative Management &amp; Resources &copy; {{ date('Y') }}
            </p>
        </div>
    </main>
</div>

<script>
    (() => {
        'use strict';

        /*
         | Show and hide the password.
         |
         | aria-pressed carries the state and aria-label is rewritten, because the
         | button's only content is an icon: without both, a screen reader
         | announces "button" and nothing else.
         */
        const toggle = document.querySelector('[data-toggle-password]');
        const field = document.getElementById('password');

        if (toggle && field) {
            toggle.addEventListener('click', () => {
                const revealed = field.type === 'text';

                field.type = revealed ? 'password' : 'text';
                toggle.setAttribute('aria-pressed', String(! revealed));
                toggle.setAttribute('aria-label', revealed ? 'Show password' : 'Hide password');

                toggle.querySelector('[data-icon-show]').classList.toggle('hidden', ! revealed);
                toggle.querySelector('[data-icon-hide]').classList.toggle('hidden', revealed);

                // Typing should carry on where it left off, not jump to the end.
                const caret = field.selectionStart;
                field.focus();
                if (caret !== null) {
                    field.setSelectionRange(caret, caret);
                }
            });
        }

        /*
         | Guard against a double submit.
         |
         | Bound to submit rather than click so the browser's own required-field
         | check runs first: on click the button would lock up while the form is
         | still refusing to go anywhere.
         */
        const form = document.querySelector('[data-login-form]');
        const button = form?.querySelector('[data-submit]');

        form?.addEventListener('submit', () => {
            if (! button) {
                return;
            }

            button.disabled = true;
            button.querySelector('[data-spinner]')?.classList.remove('hidden');
            button.querySelector('[data-arrow]')?.classList.add('hidden');

            const label = button.querySelector('[data-label]');
            if (label) {
                label.textContent = 'Signing in';
            }
        });
    })();
</script>
</body>
</html>
