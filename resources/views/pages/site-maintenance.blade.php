{{--
    The public holding page. Rendered by App\Http\Middleware\PublicMaintenanceMode
    with a 503 Service Unavailable, so a crawler knows to come back rather than
    index this as the site.

    ====================================================================
    THE STYLING IS INLINE ON PURPOSE. DO NOT PUT @vite OR TAILWIND BACK.
    ====================================================================
    Maintenance mode is switched on during a deploy, which is exactly the moment
    public/build is mid-swap or the manifest is stale. @vite() throws when it cannot
    resolve an entry, so a Tailwind version of this page can fail at the only moment
    it is ever needed, and the one page whose whole job is to be shown when things
    are already wrong would show a stack trace instead.

    So this file carries its own CSS and asks nothing of the build: no stylesheet,
    no web font, no third party image. It renders with public/build empty and with
    no network. Every other view in the project should keep using Tailwind — this
    one is the exception, and the exception is the whole point of it.

    The heading and the message are operator copy from Settings, General Config,
    Maintenance. Both are escaped and both may be long, so nothing here is sized to
    assume otherwise.
--}}
@php
    // Through BrandingSettings so an uploaded logo actually shows here, and the
    // shipped images/logo.png is used when none has been uploaded. Checked rather
    // than printed blind, the way the sign in screen does it: an empty src draws a
    // broken image icon, and the footer already names the company.
    $logo = App\Support\BrandingSettings::loginLogo();

    // Centred copy stops reading well once it runs past a couple of lines, and the
    // form allows up to 1000 characters. Long messages are set left instead, which
    // keeps a short one looking deliberate and a long one looking like a notice.
    $longMessage = mb_strlen($message) > 220;

    /*
     | The optional expected return time, already filtered by
     | MaintenanceSettings::upcomingReturnAt(): null when none was saved AND null
     | once the time it names has gone by, because "back by 9am" at half past ten
     | is worse than saying nothing. A wall-clock value, so it is printed with
     | formatWallClock() — shifting it would move a 9am return to 5pm.
     |
     | Defaulted rather than assumed: this page's whole job is to render when
     | things are already wrong, so it does not throw over a missing variable.
     */
    $returnAt = $returnAt ?? null;
@endphp
<!DOCTYPE html>
{{-- Follows the app locale rather than a hardcoded "en": the audience is Malaysian
     and the operator's copy may be in Malay, so switching APP_LOCALE to ms makes
     this page honest without anybody having to remember to edit it. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#2563eb">
    <title>{{ $heading }} &middot; Smart Digital Creative</title>

    {{-- Safe to keep: it reads the settings table and the public disk, never the
         Vite manifest, and emits nothing until a favicon has been uploaded. --}}
    @include('partials.favicon')

    <style>
        *, *::before, *::after { box-sizing: border-box; }

        html { -webkit-text-size-adjust: 100%; }

        body {
            margin: 0;
            min-height: 100vh;
            min-height: 100svh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2.5rem 1.25rem;

            /* System stack: a web font would be a network fetch, and this page has
               to render with nothing but itself. */
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
            color: #0f172a;

            /* The project's blue through indigo, kept pale so the text above it
               stays comfortably readable. */
            background-color: #f1f5fd;
            background-image:
                radial-gradient(42rem 42rem at 10% -15%, rgba(37, 99, 235, 0.16), transparent 62%),
                radial-gradient(34rem 34rem at 100% 0%, rgba(109, 40, 217, 0.13), transparent 60%),
                linear-gradient(180deg, #f8fafc 0%, #eef2ff 100%);
            background-repeat: no-repeat;
            background-attachment: fixed;
        }

        .shell {
            width: 100%;
            max-width: 34rem;
            text-align: center;
        }

        /* Height-constrained rather than width-constrained, because an uploaded
           logo can be any shape; max-width keeps a wide banner inside the card. */
        .logo {
            display: block;
            height: 2.75rem;
            width: auto;
            max-width: 72%;
            margin: 0 auto 1.75rem;
            object-fit: contain;
        }

        .card {
            background-color: #ffffff;
            border: 1px solid rgba(37, 99, 235, 0.10);
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 20px 45px -26px rgba(30, 41, 89, 0.3);
        }

        /* The only ornament. A cog or a warning triangle reads as "broken
           machine"; a brand-coloured strip reads as "this was planned". */
        .accent {
            height: 0.25rem;
            background-image: linear-gradient(90deg, #2563eb 0%, #4338ca 55%, #6d28d9 100%);
        }

        .body {
            padding: 2rem 1.5rem;
        }

        .heading {
            margin: 0;
            font-size: clamp(1.4rem, 1.1rem + 1.9vw, 1.9rem);
            line-height: 1.3;
            font-weight: 700;
            letter-spacing: -0.015em;
            color: #0f172a;

            /* break-word first for older engines, then anywhere, so a 150
               character heading with no spaces in it still cannot push the card
               wider than the screen. */
            overflow-wrap: break-word;
            overflow-wrap: anywhere;
        }

        .message {
            margin: 1rem 0 0;
            font-size: 1rem;
            line-height: 1.75;
            color: #475569;

            /* The field is a textarea, so an operator's blank line between two
               sentences is kept instead of being collapsed away. */
            white-space: pre-line;
            overflow-wrap: break-word;
            overflow-wrap: anywhere;
        }

        .message.is-long {
            text-align: left;
        }

        /* The expected return time. One quiet line, centred even when the message
           above it is set left, so it reads as a fact about the outage rather than
           as the end of the operator's sentence. No countdown and no script: this
           page has to work with nothing but itself. */
        .return {
            margin: 1.5rem 0 0;
            padding-top: 1.125rem;
            border-top: 1px solid #e8eefb;
            font-size: 0.9375rem;
            line-height: 1.6;
            text-align: center;
            color: #334155;
        }

        .return strong {
            font-weight: 600;
            color: #1d4ed8;

            /* A date, a time and a zone name is a long string on a narrow phone, so
               it is allowed to wrap as a unit rather than pushing the card wider. */
            display: inline-block;
        }

        .return .zone {
            display: block;
            margin-top: 0.25rem;
            font-size: 0.75rem;
            color: #64748b;
        }

        .footnote {
            margin: 1.5rem 0 0;
            font-size: 0.75rem;
            line-height: 1.6;
            color: #5b6b85;
        }

        @media (min-width: 40em) {
            .body { padding: 2.75rem 2.5rem; }
            .logo { height: 3rem; margin-bottom: 2rem; }
            .message { margin-top: 1.125rem; font-size: 1.0625rem; }
        }
    </style>
</head>
<body>
    <main class="shell">
        @if ($logo)
            <img class="logo" src="{{ $logo }}" alt="{{ config('app.name') }}">
        @endif

        <div class="card">
            <div class="accent" aria-hidden="true"></div>

            <div class="body">
                <h1 class="heading">{{ $heading }}</h1>
                <p class="message{{ $longMessage ? ' is-long' : '' }}">{{ $message }}</p>

                @if ($returnAt)
                    <p class="return">
                        Expected back by
                        <strong>{{ App\Support\LocalTime::formatWallClock($returnAt) }}</strong>
                        {{-- Named, because a visitor abroad cannot read "9:00 am" without
                             it. The underscore is taken out of the zone identifier: this
                             line is read by the public, not by an operator. --}}
                        <span class="zone">{{ str_replace('_', ' ', App\Support\LocalTime::zone()) }} time</span>
                    </p>
                @endif
            </div>
        </div>

        <p class="footnote">
            Smart Digital Creative Management &amp; Resources &copy; {{ date('Y') }}
        </p>
    </main>
</body>
</html>
