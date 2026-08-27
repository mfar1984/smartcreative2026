{{--
    The browser tab icon.

    Nothing is emitted until a favicon has been uploaded under Settings, General
    Config, Branding. Without a tag the browser fetches /favicon.ico by itself,
    which is exactly what happened before this file existed, so an empty setting
    changes nothing rather than leaving a blank icon.

    ?v= carries the file's own hashed name, so replacing the favicon busts the
    cache. Browsers hold on to favicons unusually hard, and without this an
    operator would upload a new one and keep seeing the old one.
--}}
@php
    $faviconPath = App\Support\BrandingSettings::path('favicon_path');
    $faviconUrl = App\Support\BrandingSettings::favicon();
@endphp

@if ($faviconUrl)
    <link rel="icon" href="{{ $faviconUrl }}?v={{ substr(md5((string) $faviconPath), 0, 8) }}">
    <link rel="apple-touch-icon" href="{{ $faviconUrl }}">
@endif
