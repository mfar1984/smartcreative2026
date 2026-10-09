<?php

namespace Tests\Feature\Settings;

use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Backup\BackupRunner;
use App\Services\Backup\BackupStore;
use App\Services\Backup\DatabaseDumper;
use App\Support\BackupSettings;
use App\Support\LocalTime;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Backup retention: keep by count, by age in days, by total size in MB, and
 * whichever of them is reached first.
 *
 * The limit that protects the hosting quota is also the limit that can destroy
 * the only copy of everything, so the first thing asserted here is the guard: no
 * rule, no stored value and no combination of them can take the newest automatic
 * archive, and the folder can never be left empty. A 1 MB budget against a 46 MB
 * archive — which is the real size on the live server — keeps the archive and
 * says the limit cannot be honoured, rather than clearing the folder.
 *
 * Every test that asserts on age pins the clock at 16:30 UTC, which is already
 * tomorrow in Malaysia. The archive's name carries the LOCAL date, so a cutoff
 * built on a bare UTC now() lands a day out and these tests fail. That bug has
 * been fixed three times in this project.
 */
class BackupRetentionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 2026-10-09 16:30 UTC is 2026-10-10 00:30 in Asia/Kuala_Lumpur: the two
     * clocks are on DIFFERENT dates, every run, so the boundary is exercised
     * rather than being exercised only on the days somebody happens to test.
     */
    private const NOW_UTC = '2026-10-09 16:30:00';

    private string $privateRoot;

    private string $publicRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        Storage::fake('local');
        Storage::fake('public');

        $this->privateRoot = rtrim(Storage::disk('local')->path(''), '/\\');
        $this->publicRoot = rtrim(Storage::disk('public')->path(''), '/\\');

        config([
            'backup.include' => [
                'public' => $this->publicRoot,
                'private' => $this->privateRoot,
            ],
            'backup.keep' => 7,
        ]);

        BackupSettings::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    private function pinClock(): void
    {
        Carbon::setTestNow(Carbon::parse(self::NOW_UTC, 'UTC'));

        // The premise of every age assertion below: UTC and the office are on
        // different dates at this instant.
        $this->assertSame('2026-10-10', LocalTime::today());
        $this->assertSame('2026-10-09', Carbon::now('UTC')->toDateString());
    }

    private function backupsDirectory(): string
    {
        return app(BackupStore::class)->directory();
    }

    /** @return array<int, string> archive names in the folder, newest first */
    private function archives(): array
    {
        return array_column(app(BackupStore::class)->all(), 'name');
    }

    /**
     * An archive of a chosen size, so the size rule has something real to measure.
     *
     * The zip has to be a readable archive — BackupStore reads the manifest out of
     * every file it lists — so the padding goes inside it as an incompressible
     * stored entry.
     */
    private function writeArchive(string $name, int $bytes = 0): string
    {
        $path = $this->backupsDirectory().DIRECTORY_SEPARATOR.$name;

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('database.sql.gz', 'not-a-real-dump');
        $zip->addFromString('manifest.json', json_encode(['dump_method' => 'mysqldump']));

        if ($bytes > 0) {
            $zip->addFromString('padding.bin', random_bytes($bytes));
            $zip->setCompressionName('padding.bin', ZipArchive::CM_STORE);
        }

        $zip->close();

        return $path;
    }

    /** @param array<int, string> $names */
    private function writeArchives(array $names, int $bytes = 0): void
    {
        foreach ($names as $name) {
            $this->writeArchive($name, $bytes);
        }
    }

    private function autoName(string $date, string $time = '030000'): string
    {
        return sprintf('backup-%s-%s-auto.zip', $date, $time);
    }

    /** Whatever the three limits are now, applied. */
    private function prune(): array
    {
        BackupSettings::flush();

        return app(BackupRunner::class)->prune();
    }

    private function setLimits(?int $count = null, ?int $days = null, ?int $mb = null): void
    {
        foreach (['keep_count' => $count, 'keep_days' => $days, 'keep_mb' => $mb] as $key => $value) {
            if ($value !== null) {
                BackupSettings::write($key, (string) $value);
            }
        }

        BackupSettings::flush();
    }

    private function superAdmin(): User
    {
        return $this->userWithRole(Role::SUPER_ADMIN);
    }

    private function userWithRole(string $slug): User
    {
        return User::create([
            'name' => 'Test '.$slug,
            'username' => $slug.'-'.uniqid(),
            'email' => uniqid().'@example.test',
            'password' => 'Backup-Pass-123!',
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    /** @param array<int, string> $permissions */
    private function userWith(array $permissions): User
    {
        $role = Role::create([
            'slug' => 'custom-'.uniqid(),
            'name' => 'Custom',
            'is_active' => true,
        ]);

        $role->permissions()->sync(Permission::whereIn('slug', $permissions)->pluck('id')->all());

        return User::create([
            'name' => 'Custom',
            'username' => 'custom-'.uniqid(),
            'email' => uniqid().'@example.test',
            'password' => 'Backup-Pass-123!',
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    private function backupTab()
    {
        return $this->get(route('admin.settings.general', ['tab' => 'backup']));
    }

    /* ---------------------------------------------------------------------
     | The guard: the folder can never be emptied
     * ------------------------------------------------------------------ */

    public function test_a_size_limit_smaller_than_one_archive_still_leaves_the_newest(): void
    {
        $this->writeArchives([
            $this->autoName('2026-10-01'),
            $this->autoName('2026-10-02'),
            $this->autoName('2026-10-03'),
        ], bytes: 600_000);

        // 1 MB against three archives of roughly 600 KB each: naively applied this
        // deletes the lot and leaves no backup at all.
        $this->setLimits(count: 0, days: 0, mb: 1);

        $this->prune();

        $this->assertSame([$this->autoName('2026-10-03')], $this->archives());
    }

    public function test_the_strictest_age_limit_still_leaves_the_newest(): void
    {
        $this->pinClock();

        $this->writeArchives([
            $this->autoName('2025-01-01'),
            $this->autoName('2025-06-01'),
            $this->autoName('2026-01-01'),
        ]);

        // One day, with every archive months old. Nothing is young enough to keep,
        // and the newest survives anyway.
        $this->setLimits(count: 0, days: 1, mb: 0);

        $this->prune();

        $this->assertSame([$this->autoName('2026-01-01')], $this->archives());
    }

    public function test_the_strictest_count_limit_still_leaves_the_newest(): void
    {
        $this->writeArchives([
            $this->autoName('2026-09-01'),
            $this->autoName('2026-09-02'),
            $this->autoName('2026-09-03'),
        ]);

        $this->setLimits(count: 1, days: 0, mb: 0);

        $this->prune();

        $this->assertSame([$this->autoName('2026-09-03')], $this->archives());
    }

    public function test_an_archive_larger_than_the_whole_budget_is_kept_and_the_tab_says_so(): void
    {
        $this->writeArchive($this->autoName('2026-10-05'), bytes: 2_200_000);

        $this->setLimits(count: 0, days: 0, mb: 1);

        $this->assertSame([], $this->prune());
        $this->assertCount(1, $this->archives());

        $this->actingAs($this->superAdmin())
            ->backupTab()
            ->assertOk()
            ->assertSee('the size limit cannot be honoured', escape: false)
            ->assertSee('newest automatic archive on its own');
    }

    /* ---------------------------------------------------------------------
     | Defaults: an empty settings table behaves exactly as today
     * ------------------------------------------------------------------ */

    public function test_defaults_prune_exactly_as_the_system_does_today(): void
    {
        $this->pinClock();

        $this->assertSame([], Setting::readGroup('backup'));

        $this->assertSame(7, BackupSettings::keepCount());
        $this->assertSame(0, BackupSettings::keepDays());
        $this->assertSame(0, BackupSettings::keepMb());

        // Nine automatic archives, one of them years old and all of them large, so
        // an age or a size rule would show up immediately if one were applied.
        for ($day = 1; $day <= 9; $day++) {
            $this->writeArchive($this->autoName(sprintf('2026-03-%02d', $day)), bytes: 400_000);
        }

        $this->writeArchive($this->autoName('2020-01-01'), bytes: 400_000);
        $this->writeArchive('backup-2026-02-01-120000-manual.zip', bytes: 400_000);
        $this->writeArchive('backup-2026-02-02-120000-manual.zip', bytes: 400_000);

        $removed = $this->prune();

        $kept = $this->archives();

        // The newest seven automatic archives, and both manual ones.
        $this->assertCount(7, array_filter($kept, fn (string $n) => str_contains($n, '-auto.zip')));
        $this->assertCount(2, array_filter($kept, fn (string $n) => str_contains($n, '-manual.zip')));
        $this->assertContains($this->autoName('2026-03-09'), $kept);
        $this->assertContains($this->autoName('2026-03-03'), $kept);

        // Everything removed went under the count rule: no age or size rule applied.
        $this->assertSame(['count'], array_values(array_unique(array_column($removed, 'rule'))));
        $this->assertCount(3, $removed);
    }

    public function test_the_config_key_is_the_fallback_for_the_count_not_the_source_of_truth(): void
    {
        config(['backup.keep' => 3]);
        BackupSettings::flush();

        $this->assertSame(3, BackupSettings::keepCount());

        BackupSettings::write('keep_count', '5');
        BackupSettings::flush();

        $this->assertSame(5, BackupSettings::keepCount());
    }

    /* ---------------------------------------------------------------------
     | One limit at a time
     * ------------------------------------------------------------------ */

    public function test_the_count_limit_alone_keeps_the_newest_three(): void
    {
        for ($day = 1; $day <= 10; $day++) {
            $this->writeArchive($this->autoName(sprintf('2026-08-%02d', $day)));
        }

        $this->setLimits(count: 3, days: 0, mb: 0);

        $removed = $this->prune();

        $this->assertCount(7, $removed);
        $this->assertSame([
            $this->autoName('2026-08-10'),
            $this->autoName('2026-08-09'),
            $this->autoName('2026-08-08'),
        ], $this->archives());
    }

    public function test_the_age_limit_alone_keeps_the_two_recent_archives(): void
    {
        $this->pinClock();

        // One day, five days and forty days old, read on the office clock.
        $yesterday = $this->autoName('2026-10-09', '150000');
        $lastWeek = $this->autoName('2026-10-05', '030000');
        $ancient = $this->autoName('2026-08-31', '030000');

        $this->writeArchives([$yesterday, $lastWeek, $ancient]);

        $this->setLimits(count: 0, days: 7, mb: 0);

        $removed = $this->prune();

        $this->assertSame([$ancient], array_column($removed, 'name'));
        $this->assertSame(['age'], array_column($removed, 'rule'));

        $kept = $this->archives();
        $this->assertContains($yesterday, $kept);
        $this->assertContains($lastWeek, $kept);
        $this->assertNotContains($ancient, $kept);
    }

    public function test_the_size_limit_alone_keeps_the_newest_two_that_fit(): void
    {
        // Four archives of roughly 400 KB each; a 1 MB budget fits two of them.
        $this->writeArchives([
            $this->autoName('2026-07-01'),
            $this->autoName('2026-07-02'),
            $this->autoName('2026-07-03'),
            $this->autoName('2026-07-04'),
        ], bytes: 400_000);

        $this->setLimits(count: 0, days: 0, mb: 1);

        $removed = $this->prune();

        $this->assertSame(['size', 'size'], array_column($removed, 'rule'));
        $this->assertSame([
            $this->autoName('2026-07-04'),
            $this->autoName('2026-07-03'),
        ], $this->archives());
    }

    /* ---------------------------------------------------------------------
     | Whichever is reached first
     * ------------------------------------------------------------------ */

    public function test_the_size_limit_wins_when_it_is_the_stricter_one(): void
    {
        $this->writeArchives([
            $this->autoName('2026-06-01'),
            $this->autoName('2026-06-02'),
            $this->autoName('2026-06-03'),
            $this->autoName('2026-06-04'),
            $this->autoName('2026-06-05'),
        ], bytes: 400_000);

        // Count would keep five; 1 MB fits two.
        $this->setLimits(count: 5, days: 0, mb: 1);

        $removed = $this->prune();

        $this->assertCount(3, $removed);
        $this->assertSame(['size', 'size', 'size'], array_column($removed, 'rule'));
        $this->assertCount(2, $this->archives());
    }

    public function test_the_count_limit_wins_when_it_is_the_stricter_one(): void
    {
        $this->writeArchives([
            $this->autoName('2026-06-01'),
            $this->autoName('2026-06-02'),
            $this->autoName('2026-06-03'),
            $this->autoName('2026-06-04'),
            $this->autoName('2026-06-05'),
        ], bytes: 400_000);

        // 100 MB fits everything; the count keeps two.
        $this->setLimits(count: 2, days: 0, mb: 100);

        $removed = $this->prune();

        $this->assertCount(3, $removed);
        $this->assertSame(['count', 'count', 'count'], array_column($removed, 'rule'));
        $this->assertSame([
            $this->autoName('2026-06-05'),
            $this->autoName('2026-06-04'),
        ], $this->archives());
    }

    /* ---------------------------------------------------------------------
     | 0 means no limit, for each of the three
     * ------------------------------------------------------------------ */

    public function test_zero_switches_each_limit_off_independently(): void
    {
        $this->pinClock();

        $names = [
            $this->autoName('2019-01-01'),
            $this->autoName('2020-01-01'),
            $this->autoName('2021-01-01'),
            $this->autoName('2022-01-01'),
        ];

        $this->writeArchives($names, bytes: 400_000);

        // All three off: four ancient, large archives and nothing is touched.
        $this->setLimits(count: 0, days: 0, mb: 0);

        $this->assertSame([], $this->prune());
        $this->assertCount(4, $this->archives());

        // Age off on its own: the count is what bites, not the age.
        $this->setLimits(count: 3, days: 0, mb: 0);
        $removed = $this->prune();
        $this->assertSame(['count'], array_column($removed, 'rule'));

        // Size off on its own: nothing further goes, though the folder is well over
        // any sane budget.
        $this->setLimits(count: 0, days: 0, mb: 0);
        $this->assertSame([], $this->prune());
        $this->assertCount(3, $this->archives());
    }

    /* ---------------------------------------------------------------------
     | Manual archives
     * ------------------------------------------------------------------ */

    public function test_manual_archives_are_never_pruned_by_any_rule(): void
    {
        $this->pinClock();

        $manual = [
            'backup-2019-01-01-120000-manual.zip',
            'backup-2019-01-02-120000-manual.zip',
        ];

        $this->writeArchives($manual, bytes: 400_000);
        $this->writeArchive($this->autoName('2026-10-08'), bytes: 400_000);

        // Every rule at its strictest, all at once.
        $this->setLimits(count: 1, days: 1, mb: 1);

        $this->prune();

        $kept = $this->archives();

        foreach ($manual as $name) {
            $this->assertContains($name, $kept);
        }

        $this->assertContains($this->autoName('2026-10-08'), $kept);
    }

    public function test_the_reported_total_counts_manual_archives(): void
    {
        $this->writeArchive($this->autoName('2026-10-08'), bytes: 400_000);
        $this->writeArchive('backup-2026-10-07-120000-manual.zip', bytes: 400_000);

        $store = app(BackupStore::class);
        $rows = $store->all();

        $this->assertSame(
            (int) array_sum(array_column($rows, 'bytes')),
            $store->totalBytes(),
        );

        $this->assertGreaterThan(800_000, $store->totalBytes());
    }

    public function test_only_manual_archives_over_budget_are_named_not_deleted(): void
    {
        $manual = [
            'backup-2026-10-01-120000-manual.zip',
            'backup-2026-10-02-120000-manual.zip',
        ];

        $this->writeArchives($manual, bytes: 700_000);
        $this->writeArchive($this->autoName('2026-10-08'), bytes: 200_000);

        $this->setLimits(count: 0, days: 0, mb: 1);

        // The one automatic archive is the newest, so there is nothing prunable at
        // all, and the folder is well over 1 MB because of the manual pair.
        $this->assertSame([], $this->prune());
        $this->assertCount(3, $this->archives());

        $this->actingAs($this->superAdmin())
            ->backupTab()
            ->assertOk()
            ->assertSee('2 manual archives')
            ->assertSee('Manual backups are never removed automatically');
    }

    /* ---------------------------------------------------------------------
     | Age is judged on the office clock
     * ------------------------------------------------------------------ */

    public function test_age_is_measured_on_the_office_clock_not_on_utc(): void
    {
        $this->pinClock();

        /*
         | Eight calendar days ago on the office clock (2 Oct, with today being
         | 10 Oct there), but late enough in the local evening that the UTC instant
         | is still inside a seven-day window measured from the UTC date. A cutoff
         | built on a bare now() keeps this archive; the office clock removes it,
         | which is what the owner means by "older than 7 days".
         */
        $eightLocalDaysAgo = $this->autoName('2026-10-02', '230000');

        // Twenty minutes ago, just after local midnight, and therefore yesterday in
        // UTC. It must not be treated as a day older than it is.
        $justAfterLocalMidnight = $this->autoName('2026-10-10', '001000');

        $this->writeArchives([$eightLocalDaysAgo, $justAfterLocalMidnight]);

        $this->setLimits(count: 0, days: 7, mb: 0);

        $removed = $this->prune();

        $this->assertSame([$eightLocalDaysAgo], array_column($removed, 'name'));
        $this->assertSame(['age'], array_column($removed, 'rule'));
        $this->assertSame([$justAfterLocalMidnight], $this->archives());
    }

    public function test_an_archive_named_just_after_local_midnight_survives_a_one_day_limit(): void
    {
        $this->pinClock();

        $newest = $this->autoName('2026-10-10', '002000');
        $twentyMinutesEarlier = $this->autoName('2026-10-10', '001000');

        $this->writeArchives([$newest, $twentyMinutesEarlier]);

        $this->setLimits(count: 0, days: 1, mb: 0);

        $this->assertSame([], $this->prune());
        $this->assertCount(2, $this->archives());
    }

    /* ---------------------------------------------------------------------
     | The record
     * ------------------------------------------------------------------ */

    public function test_every_automatic_deletion_is_recorded_with_the_rule(): void
    {
        $gone = $this->autoName('2026-05-01');

        $this->writeArchives([$gone, $this->autoName('2026-05-02')]);

        $this->setLimits(count: 1, days: 0, mb: 0);

        $this->prune();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'settings.backup.prune',
            'actor_label' => 'Retention',
            'description' => 'Retention removed '.$gone.': '.BackupStore::RULES['count'].'.',
        ]);

        $this->assertSame(1, ActivityLog::where('action', 'settings.backup.prune')->count());
    }

    public function test_the_command_says_which_archives_went_and_why(): void
    {
        $this->writeArchives([
            $this->autoName('2026-05-01'),
            $this->autoName('2026-05-02'),
            $this->autoName('2026-05-03'),
        ]);

        $this->setLimits(count: 2, days: 0, mb: 0);

        $this->app->bind(DatabaseDumper::class, fn () => new RetentionFakeDumper);

        $this->artisan('backup:run', ['--type' => 'auto'])
            ->expectsOutputToContain('Retention removed')
            ->expectsOutputToContain($this->autoName('2026-05-01'))
            ->expectsOutputToContain(BackupStore::RULES['count'])
            ->assertSuccessful();
    }

    /* ---------------------------------------------------------------------
     | Saving the limits
     * ------------------------------------------------------------------ */

    public function test_the_retention_fields_and_the_total_size_are_on_the_tab(): void
    {
        $this->writeArchive($this->autoName('2026-10-08'), bytes: 400_000);

        $this->actingAs($this->superAdmin())
            ->backupTab()
            ->assertOk()
            ->assertSee('Retention')
            ->assertSee('Automatic Backups Kept')
            ->assertSee('Delete Older Than (days)')
            ->assertSee('Total Size Limit (MB)')
            ->assertSee('name="keep_count"', escape: false)
            ->assertSee('name="keep_days"', escape: false)
            ->assertSee('name="keep_mb"', escape: false)
            ->assertSee('On Disk Now')
            ->assertSee(BackupStore::humanBytes(app(BackupStore::class)->totalBytes()))
            ->assertSee('1 archive');
    }

    public function test_saving_retention_needs_its_own_permission(): void
    {
        $reader = $this->userWith(['admin.access', 'settings.general.view', 'settings.backup.view']);

        $this->actingAs($reader)
            ->put(route('admin.settings.backup.retention.update'), [
                'keep_count' => '2',
                'keep_days' => '3',
                'keep_mb' => '4',
            ])
            ->assertForbidden();

        $this->assertSame([], Setting::readGroup('backup'));

        // Shown the limits, but not the button to change them.
        $this->actingAs($reader)
            ->backupTab()
            ->assertOk()
            ->assertSee('Automatic Backups Kept')
            ->assertSee('Your role can view these limits but not change them.');
    }

    public function test_a_super_admin_saves_the_three_limits(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.backup.retention.update'), [
                'keep_count' => '4',
                'keep_days' => '14',
                'keep_mb' => '500',
            ])
            ->assertRedirect(route('admin.settings.general', ['tab' => 'backup']))
            ->assertSessionHas('status');

        BackupSettings::flush();

        $this->assertSame(4, BackupSettings::keepCount());
        $this->assertSame(14, BackupSettings::keepDays());
        $this->assertSame(500, BackupSettings::keepMb());

        $this->assertDatabaseHas('activity_logs', ['action' => 'settings.backup.retention']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'settings.updated']);
    }

    public function test_a_negative_or_absurd_limit_is_refused_by_the_form(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.backup.retention.update'), [
                'keep_count' => '-1',
                'keep_days' => '-5',
                'keep_mb' => (string) (BackupSettings::MAX_KEEP_MB + 1),
            ])
            ->assertSessionHasErrors(['keep_count', 'keep_days', 'keep_mb']);

        $this->assertSame([], Setting::readGroup('backup'));
    }

    public function test_a_stray_stored_value_is_clamped_by_the_readers(): void
    {
        // Written past the form, the way another tool editing the table would.
        BackupSettings::write('keep_count', '99999');
        BackupSettings::write('keep_days', '-3');
        BackupSettings::write('keep_mb', '99999999');
        BackupSettings::flush();

        $this->assertSame(BackupSettings::MAX_KEEP_COUNT, BackupSettings::keepCount());

        // A negative age is not a limit, so it reads as off rather than as "delete
        // everything named before the end of time".
        $this->assertSame(0, BackupSettings::keepDays());
        $this->assertSame(BackupSettings::MAX_KEEP_MB, BackupSettings::keepMb());
    }

    public function test_the_update_permission_exists_and_view_alone_does_not_carry_it(): void
    {
        $this->assertDatabaseHas('permissions', ['slug' => 'settings.backup.update']);

        $reader = $this->userWith(['admin.access', 'settings.general.view', 'settings.backup.view']);

        $this->assertTrue($reader->hasPermission('settings.backup.view'));
        $this->assertFalse($reader->hasPermission('settings.backup.update'));
        $this->assertTrue($this->superAdmin()->hasPermission('settings.backup.update'));
    }
}

/**
 * Stands in for mysqldump, because the test database is sqlite in memory.
 *
 * Only the command test here needs one; everything else writes archives straight
 * into the folder and asks the pruner what it does with them.
 */
class RetentionFakeDumper implements DatabaseDumper
{
    public function dump(string $target): string
    {
        $gz = gzopen($target, 'wb');
        gzwrite($gz, "-- a dump\n");
        gzclose($gz);

        return 'mysqldump';
    }
}
