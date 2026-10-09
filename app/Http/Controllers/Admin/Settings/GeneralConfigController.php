<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateBackupRetentionRequest;
use App\Http\Requests\Admin\UpdateGeneralConfigRequest;
use App\Http\Requests\Admin\UpdateMaintenanceRequest;
use App\Http\Requests\Admin\UpdateSecurityConfigRequest;
use App\Jobs\RunBackup;
use App\Models\BannedIp;
use App\Models\Setting;
use App\Services\AdminLogger;
use App\Services\Backup\BackupStore;
use App\Services\Security\LoginBanService;
use App\Support\BackupSettings;
use App\Support\BrandingSettings;
use App\Support\GeneralSettings;
use App\Support\MaintenanceSettings;
use App\Support\SecuritySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class GeneralConfigController extends Controller
{
    /**
     * Tab slug => label and icon name understood by the admin icon component.
     */
    public const TABS = [
        'general' => ['label' => 'General', 'icon' => 'sliders'],
        'security' => ['label' => 'Security', 'icon' => 'shield'],
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
        'security' => 'settings.security.view',
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

    public function index(Request $request, LoginBanService $bans)
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
            'security' => SecuritySettings::formValues(),

            // Read only for the Security tab, which only a role holding
            // settings.security.view is ever shown.
            'bans' => $tab === 'security' ? $bans->active() : collect(),
            'bansEnforced' => SecuritySettings::banEnabled(),
            'currentIp' => $request->ip(),

            'maintenance' => MaintenanceSettings::formValues(),
            'maintenanceState' => $this->maintenanceState(),
            'backup' => $this->backupOverview(),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'dateFormats' => array_keys(GeneralSettings::DATE_FORMATS),
            'timeFormats' => array_keys(GeneralSettings::TIME_FORMATS),
            'branding' => $this->brandingCards(),
            'canUpdateGeneral' => $request->user()->hasPermission('settings.general.update'),
            'canUpdateSecurity' => $request->user()->hasPermission('settings.security.update'),
            'canUpdateMaintenance' => $request->user()->hasPermission('settings.maintenance.update'),
            'canViewBackup' => $request->user()->hasPermission('settings.backup.view'),
            'canUpdateBackup' => $request->user()->hasPermission('settings.backup.update'),
            'canCreateBackup' => $request->user()->hasPermission('settings.backup.create'),
            'canDownloadBackup' => $request->user()->hasPermission('settings.backup.download'),
            'canDeleteBackup' => $request->user()->hasPermission('settings.backup.delete'),
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

        // Both support classes cache per request, and the redirect renders the
        // sidebar and the form again, so a stale value here would show the old logo
        // or the old address until the next click.
        BrandingSettings::flush();
        GeneralSettings::flush();

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
        $before = MaintenanceSettings::formValues();
        $settings = $request->settings();

        foreach ($settings as $key => $value) {
            MaintenanceSettings::write($key, $value);
        }

        // The reader memoises the group per request, and the redirect draws the
        // form and the "in force now" line again, so a stale value here would
        // report the state the site was in before this save.
        MaintenanceSettings::flush();

        AdminLogger::activity(
            'settings.maintenance.update',
            $settings['enabled'] === '1'
                ? 'Turned public maintenance mode ON.'
                : 'Turned public maintenance mode OFF.',
        );
        AdminLogger::audit(
            new Setting(['key' => 'maintenance.*', 'group' => 'maintenance']),
            'settings.updated',
            $before,
            MaintenanceSettings::formValues(),
        );

        return redirect()
            ->route('admin.settings.general', ['tab' => 'maintenance'])
            ->with('status', 'Maintenance settings saved.');
    }

    /**
     * The holding page as a visitor would see it, inside the admin, with the site
     * still up.
     *
     * Renders the SAME view the middleware renders, from the same values, so what
     * is checked here is what is served. A 200 and not a 503: this is a page in the
     * admin, and a 503 would have the browser — and anything watching the admin —
     * believe the panel itself had fallen over. Nothing is written, so looking at
     * the page cannot turn maintenance on, and it draws the same whether the switch
     * is on or off.
     */
    public function previewMaintenance()
    {
        return response()->view(
            MaintenanceSettings::HOLDING_PAGE_VIEW,
            MaintenanceSettings::holdingPageData(),
        );
    }

    public function updateSecurity(UpdateSecurityConfigRequest $request)
    {
        $before = SecuritySettings::formValues();
        $validated = $request->settings();

        foreach ($validated as $key => $value) {
            SecuritySettings::write($key, (string) $value);
        }

        // The reader memoises the group per request, and the redirect draws the
        // form again, so a stale value here would show the old policy until the
        // next click.
        SecuritySettings::flush();

        /*
         | Both of these are written even when this very save switched logging off.
         | The action and the audit event both begin with "settings.security.",
         | which is on AdminLogger::ALWAYS_RECORDED, so neither switch can silence
         | them. That is deliberate: otherwise somebody could turn the audit trail
         | off, act, and turn it back on with nothing recording that they had.
         |
         | The audit event is "settings.security.updated" rather than the generic
         | "settings.updated" the other tabs use precisely so it carries that
         | prefix, and so the Security tab's own history is identifiable on the
         | Audit Log screen.
         */
        AdminLogger::activity('settings.security.update', 'Updated security settings.');
        AdminLogger::audit(
            new Setting(['key' => 'security.*', 'group' => 'security']),
            'settings.security.updated',
            $before,
            $validated,
        );

        return redirect()
            ->route('admin.settings.general', ['tab' => 'security'])
            ->with('status', 'Security settings saved.');
    }

    /**
     * Remove one row from the Banned IPs list: lifts that address's ban and clears
     * its failure count.
     */
    public function destroyBan(BannedIp $bannedIp, LoginBanService $bans)
    {
        $ip = $bannedIp->ip_address;

        $bans->lift($ip);

        AdminLogger::activity('settings.security.unban', sprintf('Removed the sign-in ban on %s.', $ip));

        return redirect()
            ->route('admin.settings.general', ['tab' => 'security'])
            ->with('status', sprintf('The ban on %s was removed. It can sign in again now.', $ip));
    }

    /** Clear all: lifts every ban at once. */
    public function clearBans(LoginBanService $bans)
    {
        $lifted = $bans->liftAll();

        AdminLogger::activity(
            'settings.security.unban_all',
            $lifted->isEmpty()
                ? 'Cleared the Banned IPs list; no ban was in force.'
                : sprintf('Cleared every sign-in ban (%d): %s.', $lifted->count(), $lifted->pluck('ip_address')->implode(', ')),
        );

        return redirect()
            ->route('admin.settings.general', ['tab' => 'security'])
            ->with('status', $lifted->isEmpty()
                ? 'There were no bans to clear.'
                : sprintf('%d %s lifted.', $lifted->count(), $lifted->count() === 1 ? 'ban' : 'bans'));
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
        // The defaults live with the accessor that the public site reads, so the
        // form cannot show one fallback while the footer renders another.
        return GeneralSettings::formValues();
    }

    /**
     * What is in force on the public site at this moment, for the tab to state
     * plainly: off, on by hand, on by schedule, armed for later, or finished.
     *
     * Resolved here rather than in the Blade so the tab cannot disagree with the
     * middleware about whether the site is up — both read the same methods.
     *
     * @return array<string, mixed>
     */
    private function maintenanceState(): array
    {
        return [
            'state' => MaintenanceSettings::state(),
            'holding' => MaintenanceSettings::isHoldingPublicSite(),
            'start' => MaintenanceSettings::windowStart(),
            'end' => MaintenanceSettings::windowEnd(),
            'return_at' => MaintenanceSettings::expectedReturnAt(),
            'return_passed' => MaintenanceSettings::returnTimeHasPassed(),
        ];
    }

    /**
     * Queue a backup. The job does the work, not this request.
     *
     * Zipping the uploads folder on shared hosting takes longer than a web request
     * may live, so pressing the button writes a job and returns. The cron worker
     * picks it up within the minute.
     */
    public function runBackup(Request $request)
    {
        Cache::put(RunBackup::PENDING_KEY, true, RunBackup::PENDING_TTL);

        RunBackup::dispatch($request->user()->id, $request->user()->logLabel());

        AdminLogger::activity('settings.backup.queue', 'Queued a manual backup.');

        return redirect()
            ->route('admin.settings.general', ['tab' => 'backup'])
            ->with('status', 'Backup queued. It will appear in the list here within a few minutes.');
    }

    /**
     * Save the three retention limits.
     *
     * Behind settings.backup.update, which is a permission of its own: a role
     * trusted to read the backup list is not thereby trusted to decide how long
     * the archives live. Nothing is deleted here — the next automatic run applies
     * whatever is saved, and it can never remove the newest automatic archive.
     */
    public function updateBackupRetention(UpdateBackupRetentionRequest $request)
    {
        $before = BackupSettings::formValues();
        $validated = $request->validated();

        foreach ($validated as $key => $value) {
            BackupSettings::write($key, (string) $value);
        }

        // The reader memoises the group per request and the redirect draws the
        // form again, so a stale value here would show the old limits.
        BackupSettings::flush();

        AdminLogger::activity(
            'settings.backup.retention',
            sprintf(
                'Set backup retention: keep %s, %s, %s.',
                (int) $validated['keep_count'] === 0 ? 'every automatic backup' : $validated['keep_count'] . ' automatic backups',
                (int) $validated['keep_days'] === 0 ? 'no age limit' : 'up to ' . $validated['keep_days'] . ' days old',
                (int) $validated['keep_mb'] === 0 ? 'no size limit' : 'under ' . $validated['keep_mb'] . ' MB in total',
            ),
        );
        AdminLogger::audit(
            new Setting(['key' => 'backup.*', 'group' => 'backup']),
            'settings.updated',
            $before,
            $validated,
        );

        return redirect()
            ->route('admin.settings.general', ['tab' => 'backup'])
            ->with('status', 'Retention settings saved. They are applied by the next automatic backup.');
    }

    /**
     * Send one archive to the browser.
     *
     * The record is written before the file is, and deliberately so: an archive
     * holds every participant's identity card number and every password hash in
     * the system, so who asked for it is logged whether or not the transfer
     * finishes.
     *
     * The name goes through BackupStore::resolve(), which refuses anything that is
     * not an archive sitting in the backups folder. A name that is not one is a 404
     * rather than an error page naming the path it looked in.
     */
    public function downloadBackup(string $file, BackupStore $store)
    {
        $path = $store->resolve($file);

        abort_if($path === null, 404);

        $name = basename($path);

        AdminLogger::activity(
            'settings.backup.download',
            sprintf('Downloaded the backup %s.', $name),
            null,
            null,
            AdminLogger::LEVEL_WARN,
        );

        return response()->download($path, $name, [
            'Content-Type' => 'application/zip',
        ]);
    }

    public function destroyBackup(string $file, BackupStore $store)
    {
        $path = $store->resolve($file);

        abort_if($path === null, 404);

        $name = basename($path);

        if (! $store->delete($name)) {
            return redirect()
                ->route('admin.settings.general', ['tab' => 'backup'])
                ->with('status', sprintf('%s could not be deleted. Check the folder permissions.', $name));
        }

        AdminLogger::activity('settings.backup.delete', sprintf('Deleted the backup %s.', $name));

        return redirect()
            ->route('admin.settings.general', ['tab' => 'backup'])
            ->with('status', sprintf('%s was deleted.', $name));
    }

    /**
     * The database, and the archives held on disk.
     *
     * Restoring is not here. Reading an archive back overwrites live data, so it is
     * separate work with its own confirmation path; the manifest inside each
     * archive exists so that work can check what it has been handed first.
     *
     * @return array<string, mixed>
     */
    private function backupOverview(): array
    {
        $connection = config('database.default');
        $store = app(BackupStore::class);
        $files = $store->all();

        return [
            'connection' => $connection,
            'driver' => config("database.connections.{$connection}.driver"),
            'database' => config("database.connections.{$connection}.database"),
            'host' => config("database.connections.{$connection}.host"),
            'table_count' => $this->countTables(),
            'files' => $files,

            /*
             | The total INCLUDES manual archives, because that is what actually
             | occupies the hosting quota, even though pruning only ever deletes
             | automatic ones. A figure that left them out would be the one number
             | on this screen that does not match what the account is using.
             */
            'total_bytes' => array_sum(array_column($files, 'bytes')),
            'auto_count' => count(array_filter($files, fn (array $row) => $row['type'] === BackupStore::TYPE_AUTO)),
            'pending' => (bool) Cache::get(RunBackup::PENDING_KEY, false),
            'retention' => BackupSettings::formValues(),
            'warnings' => $store->retentionWarnings(BackupSettings::keepMb()),
            'daily_at' => (string) config('backup.daily_at', '03:00'),
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
