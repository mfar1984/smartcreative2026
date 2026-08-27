<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateGeneralConfigRequest;
use App\Http\Requests\Admin\UpdateMaintenanceRequest;
use App\Models\Setting;
use App\Services\AdminLogger;
use App\Support\BrandingSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class GeneralConfigController extends Controller
{
    /**
     * Tab slug => label and icon name understood by the admin icon component.
     */
    public const TABS = [
        'general' => ['label' => 'General', 'icon' => 'sliders'],
        'backup' => ['label' => 'Backup & Restore', 'icon' => 'database'],
        'maintenance' => ['label' => 'Maintenance', 'icon' => 'wrench'],
    ];

    /**
     * The permission each tab needs before it is drawn at all.
     *
     * The page as a whole is behind settings.general.view. These narrow it further,
     * so a role can be given the general settings without the maintenance switch
     * that takes the public site offline. A tab the role cannot see is not rendered
     * and cannot be reached by editing the query string either.
     */
    private const TAB_PERMISSIONS = [
        'general' => 'settings.general.view',
        'backup' => 'settings.backup.view',
        'maintenance' => 'settings.maintenance.view',
    ];

    /**
     * Upload field name => the setting key its path is stored under.
     *
     * Kept as a map so the validation field, the remove_* flag and the settings row
     * are derived from one list rather than repeated in three places.
     */
    private const BRANDING_FIELDS = [
        'sidebar_logo' => 'sidebar_logo_path',
        'login_logo' => 'login_logo_path',
        'favicon' => 'favicon_path',
    ];

    /**
     * Values used when a setting has never been saved.
     */
    private const GENERAL_DEFAULTS = [
        'site_name' => 'Smart Digital Creative Management & Resources',
        'tagline' => 'Innovate, Create & Manage',
        'contact_email' => 'event@smartcreative.my',
        'contact_phone' => '019-866 6898',
        'whatsapp' => '019-866 6898',
        'registration_no' => '202303326459 / 003562257-U',
        'address' => "Suite: 33-01, 33rd Floor\nMenara Keck Seng\n203 Jalan Bukit Bintang\n55100 Kuala Lumpur, Malaysia",
        'timezone' => 'Asia/Kuala_Lumpur',
    ];

    private const MAINTENANCE_DEFAULTS = [
        'enabled' => '0',
        'heading' => 'We are carrying out maintenance',
        'message' => 'The website is temporarily unavailable while we carry out scheduled maintenance. Please check back shortly.',
    ];

    public function index(Request $request)
    {
        $tabs = array_filter(
            self::TABS,
            fn (array $tab, string $slug) => $request->user()->hasPermission(self::TAB_PERMISSIONS[$slug]),
            ARRAY_FILTER_USE_BOTH,
        );

        $tab = $this->resolveTab($request->query('tab'), $tabs);

        return view('admin.settings.general', [
            'tabs' => $tabs,
            'activeTab' => $tab,
            'general' => $this->generalValues(),
            'maintenance' => $this->maintenanceValues(),
            'backup' => $this->backupOverview(),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'branding' => $this->brandingCards(),
            'canUpdateGeneral' => $request->user()->hasPermission('settings.general.update'),
            'canUpdateMaintenance' => $request->user()->hasPermission('settings.maintenance.update'),
            'canViewBackup' => $request->user()->hasPermission('settings.backup.view'),
        ]);
    }

    public function updateGeneral(UpdateGeneralConfigRequest $request)
    {
        $before = $this->generalValues();
        $validated = $request->validated();

        /*
         | The uploads are handled first and taken out of $validated, because the
         | loop below writes whatever it is given straight into a settings row. An
         | UploadedFile passed through there would be cast to a string and stored as
         | nonsense, and the real file would never be saved.
         */
        $paths = $this->storeBrandingImages($request);

        $validated = collect($validated)
            ->except(array_merge(
                array_keys(self::BRANDING_FIELDS),
                array_map(fn (string $field) => 'remove_' . $field, array_keys(self::BRANDING_FIELDS)),
            ))
            ->all();

        foreach ($validated as $key => $value) {
            Setting::write('general.' . $key, $value, 'general');
        }

        foreach ($paths as $key => $path) {
            Setting::write('general.' . $key, $path, 'general');
        }

        // The support class caches per request, and the redirect renders the sidebar
        // again, so a stale path here would show the old logo until the next click.
        BrandingSettings::flush();

        AdminLogger::activity('settings.general.update', 'Updated general configuration.');
        AdminLogger::audit(
            new Setting(['key' => 'general.*', 'group' => 'general']),
            'settings.updated',
            $before,
            $validated + $paths,
        );

        return redirect()
            ->route('admin.settings.general', ['tab' => 'general'])
            ->with('status', 'General configuration saved.');
    }

    /**
     * Save whichever brand images were picked, and return the paths to store.
     *
     * Only keys that actually changed come back, so a save that touched no image
     * leaves those settings rows exactly as they were. Replacing or removing an
     * image deletes the file it replaces, so the disk does not fill with orphans on
     * a hosting account with a quota.
     *
     * @return array<string, string|null>
     */
    private function storeBrandingImages(UpdateGeneralConfigRequest $request): array
    {
        $paths = [];

        foreach (self::BRANDING_FIELDS as $field => $settingKey) {
            $current = Setting::read('general.' . $settingKey);

            if ($request->boolean('remove_' . $field)) {
                if (filled($current)) {
                    Storage::disk('public')->delete($current);
                }

                $paths[$settingKey] = null;

                continue;
            }

            if (! $request->hasFile($field)) {
                continue;
            }

            if (filled($current)) {
                Storage::disk('public')->delete($current);
            }

            $paths[$settingKey] = $request->file($field)->store(BrandingSettings::DIRECTORY, 'public');
        }

        return $paths;
    }

    public function updateMaintenance(UpdateMaintenanceRequest $request)
    {
        $before = $this->maintenanceValues();
        $validated = $request->validated();

        Setting::write('maintenance.enabled', $validated['enabled'] ? '1' : '0', 'maintenance');
        Setting::write('maintenance.heading', $validated['heading'], 'maintenance');
        Setting::write('maintenance.message', $validated['message'], 'maintenance');

        AdminLogger::activity(
            'settings.maintenance.update',
            $validated['enabled']
                ? 'Turned public maintenance mode ON.'
                : 'Turned public maintenance mode OFF.',
        );
        AdminLogger::audit(
            new Setting(['key' => 'maintenance.*', 'group' => 'maintenance']),
            'settings.updated',
            $before,
            ['enabled' => $validated['enabled'] ? '1' : '0'] + $validated,
        );

        return redirect()
            ->route('admin.settings.general', ['tab' => 'maintenance'])
            ->with('status', 'Maintenance settings saved.');
    }

    /**
     * @param  array<string, array<string, string>>  $allowed  Tabs this role may see.
     */
    private function resolveTab(?string $tab, array $allowed): string
    {
        if (array_key_exists((string) $tab, $allowed)) {
            return (string) $tab;
        }

        // Falls back to the first tab the role may see rather than always to
        // general, because general itself can be the one that is not allowed.
        return (string) (array_key_first($allowed) ?? 'general');
    }

    /**
     * The three brand image cards: what each one is for, and what it shows now.
     *
     * Assembled here rather than in the view so the Blade stays a loop over three
     * identical cards instead of three near-copies that can drift apart.
     *
     * @return array<int, array<string, mixed>>
     */
    private function brandingCards(): array
    {
        return [
            [
                'field' => 'sidebar_logo',
                'title' => 'Sidebar Logo',
                'description' => 'Top left of the admin, beside the menu.',
                'accept' => 'image/jpeg,image/png,image/webp,image/svg+xml',
                'help' => 'JPG, PNG, WebP or SVG up to 2 MB. Drawn 28px tall, so a wide image works best.',
                'url' => BrandingSettings::url('sidebar_logo_path'),
                'custom' => BrandingSettings::isCustom('sidebar_logo_path'),
                'preview' => 'wide',
            ],
            [
                'field' => 'login_logo',
                'title' => 'Login Logo',
                'description' => 'Above the sign in form.',
                'accept' => 'image/jpeg,image/png,image/webp,image/svg+xml',
                'help' => 'JPG, PNG, WebP or SVG up to 2 MB. Drawn 40px tall and centred.',
                'url' => BrandingSettings::url('login_logo_path'),
                'custom' => BrandingSettings::isCustom('login_logo_path'),
                'preview' => 'wide',
            ],
            [
                'field' => 'favicon',
                'title' => 'Favicon',
                'description' => 'The small icon on the browser tab.',
                'accept' => 'image/png,image/x-icon,image/svg+xml,image/webp',
                'help' => 'ICO, PNG, WebP or SVG up to 512 KB. A square image around 32×32 or larger.',
                'url' => BrandingSettings::url('favicon_path'),
                'custom' => BrandingSettings::isCustom('favicon_path'),
                'preview' => 'square',
            ],
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function generalValues(): array
    {
        $stored = Setting::readGroup('general');
        $values = [];

        foreach (self::GENERAL_DEFAULTS as $key => $default) {
            $values[$key] = $stored['general.' . $key] ?? $default;
        }

        return $values;
    }

    /**
     * @return array<string, string|null>
     */
    private function maintenanceValues(): array
    {
        $stored = Setting::readGroup('maintenance');
        $values = [];

        foreach (self::MAINTENANCE_DEFAULTS as $key => $default) {
            $values[$key] = $stored['maintenance.' . $key] ?? $default;
        }

        return $values;
    }

    /**
     * Read-only picture of the database and any backup files on disk.
     *
     * Creating and restoring backups is not wired up: a restore overwrites live
     * data and needs an explicit decision on strategy first.
     *
     * @return array<string, mixed>
     */
    private function backupOverview(): array
    {
        $connection = config('database.default');

        $files = [];
        $disk = Storage::disk('local');

        if ($disk->exists('backups')) {
            foreach ($disk->files('backups') as $path) {
                $files[] = [
                    'name' => basename($path),
                    'size' => $disk->size($path),
                    'modified' => $disk->lastModified($path),
                ];
            }

            usort($files, fn (array $a, array $b) => $b['modified'] <=> $a['modified']);
        }

        return [
            'connection' => $connection,
            'driver' => config("database.connections.{$connection}.driver"),
            'database' => config("database.connections.{$connection}.database"),
            'host' => config("database.connections.{$connection}.host"),
            'table_count' => $this->countTables(),
            'files' => $files,
            'path' => 'storage/app/private/backups',
        ];
    }

    private function countTables(): ?int
    {
        try {
            return count(DB::select('SHOW TABLES'));
        } catch (\Throwable) {
            // Non MySQL driver or no permission to list tables.
            return null;
        }
    }
}
