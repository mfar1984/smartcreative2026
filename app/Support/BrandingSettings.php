<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * The three uploaded brand images, and what to show when none has been uploaded.
 *
 * Every render point asks this class rather than writing `asset('images/logo.png')`
 * itself. That is the whole value of it: the fallback lives in one place, so
 * uploading a logo cannot leave one screen still showing the old file, and clearing
 * one cannot leave a screen with a broken image.
 *
 * Follows the shape of MailSettings and PaymentSettings: a group constant, static
 * readers, and a per-request cache, because these are read on every page.
 */
final class BrandingSettings
{
    /** Same group as the rest of the General Config screen. */
    private const GROUP = 'general';

    /** Where the uploads live on the public disk. */
    public const DIRECTORY = 'branding';

    /**
     * Setting key => the file shipped with the project to fall back on.
     *
     * The favicon has no Blade fallback because browsers fetch /favicon.ico by
     * themselves; the tag is only emitted when something has been uploaded.
     *
     * @var array<string, string|null>
     */
    public const IMAGES = [
        'sidebar_logo_path' => 'images/logo.png',
        'login_logo_path' => 'images/logo.png',
        'favicon_path' => null,
    ];

    /** @var array<string, string|null> */
    private static array $cache = [];

    /**
     * The stored path for one key, or null when nothing has been uploaded.
     */
    public static function path(string $key): ?string
    {
        if (! array_key_exists($key, self::$cache)) {
            self::$cache[$key] = Setting::read(self::GROUP . '.' . $key);
        }

        $path = self::$cache[$key];

        /*
         | A row can hold a path to a file somebody has since deleted from disk.
         | Checking here means a missing file falls back to the shipped image
         | instead of rendering a broken picture on every page.
         */
        if ($path === null || $path === '' || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return $path;
    }

    /**
     * A usable URL for one key, falling back to the file shipped with the project.
     */
    public static function url(string $key): ?string
    {
        $path = self::path($key);

        if ($path !== null) {
            return Storage::disk('public')->url($path);
        }

        $fallback = self::IMAGES[$key] ?? null;

        return $fallback === null ? null : asset($fallback);
    }

    public static function sidebarLogo(): ?string
    {
        return self::url('sidebar_logo_path');
    }

    public static function loginLogo(): ?string
    {
        return self::url('login_logo_path');
    }

    /**
     * Null when no favicon has been uploaded, so no tag is emitted and the browser
     * carries on using /favicon.ico as it already did.
     */
    public static function favicon(): ?string
    {
        return self::url('favicon_path');
    }

    /**
     * Whether a key is showing an upload rather than the shipped fallback.
     *
     * Drives the Remove control: there is nothing to remove when the default is
     * being shown.
     */
    public static function isCustom(string $key): bool
    {
        return self::path($key) !== null;
    }

    /**
     * Forget the per-request cache after a save, so the redirect draws the new file.
     */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
