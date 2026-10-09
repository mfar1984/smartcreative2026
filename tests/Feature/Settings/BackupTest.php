<?php

namespace Tests\Feature\Settings;

use App\Jobs\RunBackup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupRunner;
use App\Services\Backup\BackupStore;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\MysqlDumper;
use App\Support\LocalTime;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Taking a backup: the archive, the schedule, the button and the three
 * permissions. Nothing here restores anything, because nothing restores anything.
 *
 * The test database is sqlite in memory and mysqldump cannot read it, so the
 * dumper is faked through its interface and the assertions are about the archive
 * the runner builds around it. The one thing that must be true of the real dumper
 * — that the password never reaches the command line — is asserted against the
 * real MysqlDumper's own argument list.
 *
 * Both storage roots are faked and config('backup.include') is pointed at them,
 * so a run here sweeps up a handful of fixture files rather than the project's
 * actual uploads folder.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

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

        Storage::disk('public')->put('branding/logo.png', 'a-logo');
        Storage::disk('local')->put('ic/front.jpg', 'an-identity-card');

        $this->bindDumper();
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    private function bindDumper(bool $fail = false): void
    {
        $this->app->bind(DatabaseDumper::class, fn () => new FakeDumper($fail));
    }

    private function backupsDirectory(): string
    {
        return app(BackupStore::class)->directory();
    }

    /** @return array<int, string> archive names in the folder */
    private function archives(): array
    {
        return array_column(app(BackupStore::class)->all(), 'name');
    }

    /** An archive on disk under a name of our choosing, with a readable manifest. */
    private function writeArchive(string $name): string
    {
        $path = $this->backupsDirectory() . DIRECTORY_SEPARATOR . $name;

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('database.sql.gz', 'not-a-real-dump');
        $zip->addFromString('manifest.json', json_encode(['dump_method' => 'mysqldump']));
        $zip->close();

        return $path;
    }

    private function userWithRole(string $slug): User
    {
        return User::create([
            'name' => 'Test ' . $slug,
            'username' => $slug . '-' . uniqid(),
            'email' => uniqid() . '@example.test',
            'password' => 'Backup-Pass-123!',
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    private function superAdmin(): User
    {
        return $this->userWithRole(Role::SUPER_ADMIN);
    }

    /** @param  array<int, string>  $permissions */
    private function userWith(array $permissions): User
    {
        $role = Role::create([
            'slug' => 'custom-' . uniqid(),
            'name' => 'Custom',
            'is_active' => true,
        ]);

        $role->permissions()->sync(Permission::whereIn('slug', $permissions)->pluck('id')->all());

        return User::create([
            'name' => 'Custom',
            'username' => 'custom-' . uniqid(),
            'email' => uniqid() . '@example.test',
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
     | The archive
     * ------------------------------------------------------------------ */

    public function test_a_run_writes_one_archive_with_the_documented_layout(): void
    {
        $this->artisan('backup:run')
            ->expectsOutputToContain('mysqldump')
            ->assertSuccessful();

        $names = $this->archives();
        $this->assertCount(1, $names);
        $this->assertMatchesRegularExpression('/^backup-\d{4}-\d{2}-\d{2}-\d{6}-manual\.zip$/', $names[0]);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($this->backupsDirectory() . DIRECTORY_SEPARATOR . $names[0]) === true);

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }

        $this->assertContains('database.sql.gz', $entries);
        $this->assertContains('manifest.json', $entries);
        $this->assertContains('files/public/branding/logo.png', $entries);
        $this->assertContains('files/private/ic/front.jpg', $entries);

        // The dump is readable gzip holding what the dumper wrote.
        $this->assertSame(
            FakeDumper::SQL,
            gzdecode($zip->getFromName('database.sql.gz')),
        );

        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        foreach ([
            'format', 'app', 'type', 'created_at', 'created_at_local', 'display_timezone',
            'dump_method', 'database', 'files', 'latest_migration',
        ] as $key) {
            $this->assertArrayHasKey($key, $manifest, $key . ' is missing from the manifest');
        }

        $this->assertSame('manual', $manifest['type']);
        $this->assertSame('mysqldump', $manifest['dump_method']);
        $this->assertSame(LocalTime::zone(), $manifest['display_timezone']);
        $this->assertSame(BackupRunner::FORMAT, $manifest['format']);

        // An ISO 8601 instant in UTC, plus the same moment on the display clock.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $manifest['created_at']);
        $this->assertNotSame('—', $manifest['created_at_local']);

        foreach (['driver', 'name', 'table_count', 'row_counts'] as $key) {
            $this->assertArrayHasKey($key, $manifest['database']);
        }

        // Row counts per table, and the users table is one this test has filled.
        $this->assertArrayHasKey('users', $manifest['database']['row_counts']);
        $this->assertSame(
            Permission::query()->count(),
            $manifest['database']['row_counts']['permissions'],
        );
        $this->assertGreaterThan(0, $manifest['database']['table_count']);

        $this->assertSame(2, $manifest['files']['count']);
        $this->assertGreaterThan(0, $manifest['files']['bytes']);
        $this->assertNotNull($manifest['latest_migration']);

        $this->assertDatabaseHas('activity_logs', ['action' => 'settings.backup.create']);
    }

    public function test_the_backups_folder_is_never_swept_into_the_archive(): void
    {
        // An archive already sitting in the folder when the next run starts.
        $this->writeArchive('backup-2026-01-01-030000-auto.zip');

        $this->artisan('backup:run')->assertSuccessful();

        $fresh = collect($this->archives())->first(fn (string $name) => str_contains($name, 'manual'));

        $zip = new ZipArchive();
        $zip->open($this->backupsDirectory() . DIRECTORY_SEPARATOR . $fresh);

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }
        $zip->close();

        foreach ($entries as $entry) {
            $this->assertStringNotContainsString('backups/', $entry);
            $this->assertStringNotContainsString('.zip', $entry);
        }
    }

    public function test_a_failed_dump_leaves_nothing_behind(): void
    {
        $this->bindDumper(fail: true);

        $this->artisan('backup:run')
            ->expectsOutputToContain('Backup failed')
            ->assertFailed();

        $this->assertSame([], $this->archives());

        // Not even a temporary file: the folder is exactly as it was.
        $left = array_values(array_diff(scandir($this->backupsDirectory()), ['.', '..']));
        $this->assertSame([], $left);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'settings.backup.failed',
            'level' => 'error',
        ]);
    }

    /* ---------------------------------------------------------------------
     | The credential
     * ------------------------------------------------------------------ */

    public function test_the_mysqldump_command_never_carries_the_password(): void
    {
        $secret = 'sup3r-s3cret-passw0rd';

        $dumper = new MysqlDumper([
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => '3306',
            'database' => 'smartcreative',
            'username' => 'smartcre_user',
            'password' => $secret,
            'unix_socket' => '/var/lib/mysql/mysql.sock',
            'charset' => 'utf8mb4',
        ], 'mysqldump', 600);

        $command = $dumper->command('/tmp/option-file.cnf');
        $flat = implode(' ', $command);

        $this->assertStringNotContainsString($secret, $flat);
        $this->assertStringNotContainsString('--password', $flat);
        $this->assertStringNotContainsString('-p', str_replace('--', '', $flat));

        // The credentials arrive through the option file instead, which mysqldump
        // insists on seeing before anything else.
        $this->assertSame('--defaults-extra-file=/tmp/option-file.cnf', $command[1]);

        // And the flags a live InnoDB database on shared hosting needs.
        foreach (['--single-transaction', '--quick', '--no-tablespaces'] as $flag) {
            $this->assertContains($flag, $command);
        }

        $this->assertSame('smartcreative', end($command));
    }

    /* ---------------------------------------------------------------------
     | Pruning
     * ------------------------------------------------------------------ */

    public function test_pruning_keeps_the_newest_seven_automatic_archives_and_no_manual_one(): void
    {
        for ($day = 1; $day <= 9; $day++) {
            $this->writeArchive(sprintf('backup-2026-03-%02d-030000-auto.zip', $day));
        }

        $this->writeArchive('backup-2026-02-01-120000-manual.zip');
        $this->writeArchive('backup-2026-02-02-120000-manual.zip');

        $removed = app(BackupRunner::class)->prune();

        $kept = $this->archives();

        $this->assertCount(2, $removed);
        $this->assertContains('backup-2026-03-01-030000-auto.zip', $removed);
        $this->assertContains('backup-2026-03-02-030000-auto.zip', $removed);

        $this->assertCount(7, array_filter($kept, fn (string $n) => str_contains($n, '-auto.zip')));
        $this->assertCount(2, array_filter($kept, fn (string $n) => str_contains($n, '-manual.zip')));
        $this->assertContains('backup-2026-02-01-120000-manual.zip', $kept);
        $this->assertContains('backup-2026-03-09-030000-auto.zip', $kept);
    }

    public function test_an_automatic_run_prunes_but_a_manual_run_never_does(): void
    {
        for ($day = 1; $day <= 7; $day++) {
            $this->writeArchive(sprintf('backup-2026-04-%02d-030000-auto.zip', $day));
        }

        // Manual: adds one, removes nothing, so the seven old automatic ones stay.
        $this->artisan('backup:run')->assertSuccessful();
        $this->assertCount(7, array_filter($this->archives(), fn (string $n) => str_contains($n, '-auto.zip')));

        // Automatic: adds one and prunes back to seven, so the oldest goes.
        $this->artisan('backup:run', ['--type' => 'auto'])->assertSuccessful();

        $auto = array_values(array_filter($this->archives(), fn (string $n) => str_contains($n, '-auto.zip')));
        $this->assertCount(7, $auto);
        $this->assertNotContains('backup-2026-04-01-030000-auto.zip', $auto);
        $this->assertCount(1, array_filter($this->archives(), fn (string $n) => str_contains($n, '-manual.zip')));
    }

    /* ---------------------------------------------------------------------
     | The schedule
     * ------------------------------------------------------------------ */

    public function test_the_nightly_backup_runs_at_three_on_the_display_clock(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'backup:run --type=auto'));

        $this->assertCount(1, $events, 'backup:run --type=auto is not scheduled');

        $event = $events->first();

        $this->assertSame('0 3 * * *', $event->expression);

        /*
         | The whole point of the entry. config/app.php is UTC, so without this the
         | nightly backup would run at 11:00 in Malaysia, in the middle of the
         | working day, against a live database.
         */
        $this->assertSame(LocalTime::zone(), (string) $event->timezone);
        $this->assertNotSame('UTC', (string) $event->timezone);
        $this->assertNotEmpty($event->mutexName());
    }

    /* ---------------------------------------------------------------------
     | Back up now
     * ------------------------------------------------------------------ */

    public function test_back_up_now_queues_exactly_one_job(): void
    {
        Queue::fake();

        $owner = $this->superAdmin();

        $this->actingAs($owner)
            ->post(route('admin.settings.backup.run'))
            ->assertRedirect(route('admin.settings.general', ['tab' => 'backup']))
            ->assertSessionHas('status');

        Queue::assertPushed(RunBackup::class, 1);

        // The job carries who pressed it, because the worker has no session to ask.
        Queue::assertPushed(RunBackup::class, fn (RunBackup $job) => $job->userId === $owner->id
            && $job->actorLabel === $owner->logLabel()
            && $job->tries === 1
            && $job->timeout === 900);

        // Nothing is written inside the request itself.
        $this->assertSame([], $this->archives());

        $this->assertDatabaseHas('activity_logs', ['action' => 'settings.backup.queue']);
    }

    public function test_back_up_now_needs_its_permission_and_refuses_a_get(): void
    {
        Queue::fake();

        $reader = $this->userWith(['admin.access', 'settings.general.view', 'settings.backup.view']);

        $this->actingAs($reader)
            ->post(route('admin.settings.backup.run'))
            ->assertForbidden();

        $this->actingAs($this->superAdmin())
            ->get('/admin/settings/backup')
            ->assertStatus(405);

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('activity_logs', ['action' => 'settings.backup.queue']);
    }

    /* ---------------------------------------------------------------------
     | The tab
     * ------------------------------------------------------------------ */

    public function test_the_tab_lists_archives_and_says_so_when_there_are_none(): void
    {
        $this->actingAs($this->superAdmin())
            ->backupTab()
            ->assertOk()
            ->assertSee('No backups yet.')
            ->assertSee('Back up now')
            ->assertDontSee('Backup and restore actions are not enabled yet');

        $this->writeArchive('backup-2026-05-04-030000-auto.zip');
        $this->writeArchive('backup-2026-05-05-141500-manual.zip');

        $this->actingAs($this->superAdmin())
            ->backupTab()
            ->assertOk()
            ->assertSee('backup-2026-05-04-030000-auto.zip')
            ->assertSee('backup-2026-05-05-141500-manual.zip')
            ->assertSee('Automatic')
            ->assertSee('Manual')
            ->assertSee('mysqldump')
            ->assertSee('Download backup-2026-05-05-141500-manual.zip')
            ->assertSee('Delete backup-2026-05-04-030000-auto.zip')
            ->assertSee('Total')
            ->assertDontSee('No backups yet.');
    }

    public function test_a_role_with_only_view_sees_the_list_but_no_buttons(): void
    {
        $this->writeArchive('backup-2026-05-06-030000-auto.zip');

        $reader = $this->userWith(['admin.access', 'settings.general.view', 'settings.backup.view']);

        $this->actingAs($reader)
            ->backupTab()
            ->assertOk()
            ->assertSee('backup-2026-05-06-030000-auto.zip')
            ->assertDontSee('Back up now')
            ->assertDontSee('Download backup-2026-05-06-030000-auto.zip')
            ->assertDontSee('Delete backup-2026-05-06-030000-auto.zip');
    }

    /* ---------------------------------------------------------------------
     | Download and delete
     * ------------------------------------------------------------------ */

    public function test_download_sends_the_archive_and_records_who_took_it(): void
    {
        $name = 'backup-2026-06-01-030000-auto.zip';
        $this->writeArchive($name);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.settings.backup.download', $name))
            ->assertOk()
            ->assertDownload($name);

        // An archive holds every identity card number and every password hash in
        // the system, so who fetched one is on record, as a warning.
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'settings.backup.download',
            'description' => 'Downloaded the backup ' . $name . '.',
            'level' => 'warn',
        ]);

        // Reading one does not remove it.
        $this->assertContains($name, $this->archives());
    }

    public function test_download_is_refused_without_its_own_permission(): void
    {
        $name = 'backup-2026-06-02-030000-auto.zip';
        $this->writeArchive($name);

        // Holds everything else on the tab, including the right to delete one.
        $other = $this->userWith([
            'admin.access', 'settings.general.view', 'settings.backup.view',
            'settings.backup.create', 'settings.backup.delete',
        ]);

        $this->actingAs($other)
            ->get(route('admin.settings.backup.download', $name))
            ->assertForbidden();

        $this->assertDatabaseMissing('activity_logs', ['action' => 'settings.backup.download']);
    }

    public function test_delete_removes_the_archive_and_logs_it(): void
    {
        $gone = 'backup-2026-07-01-030000-auto.zip';
        $kept = 'backup-2026-07-02-030000-auto.zip';
        $this->writeArchive($gone);
        $this->writeArchive($kept);

        $this->actingAs($this->superAdmin())
            ->delete(route('admin.settings.backup.destroy', $gone))
            ->assertRedirect(route('admin.settings.general', ['tab' => 'backup']))
            ->assertSessionHas('status');

        $this->assertNotContains($gone, $this->archives());
        $this->assertContains($kept, $this->archives());

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'settings.backup.delete',
            'description' => 'Deleted the backup ' . $gone . '.',
        ]);
    }

    public function test_delete_is_refused_without_its_permission_and_on_a_get(): void
    {
        $name = 'backup-2026-07-03-030000-auto.zip';
        $this->writeArchive($name);

        $reader = $this->userWith(['admin.access', 'settings.general.view', 'settings.backup.view']);

        $this->actingAs($reader)
            ->delete(route('admin.settings.backup.destroy', $name))
            ->assertForbidden();

        /*
         | A GET on the same path cannot delete anything: it is the download route,
         | behind its own permission. Somebody trusted to delete an archive but not
         | to download one is refused, which is what proves the two are separate
         | rather than one action reachable by two verbs.
         */
        $deleter = $this->userWith([
            'admin.access', 'settings.general.view', 'settings.backup.view', 'settings.backup.delete',
        ]);

        $this->actingAs($deleter)
            ->get('/admin/settings/backup/' . $name)
            ->assertForbidden();

        $this->assertContains($name, $this->archives());
    }

    /* ---------------------------------------------------------------------
     | Path traversal
     * ------------------------------------------------------------------ */

    public function test_a_name_that_is_not_an_archive_in_the_folder_is_refused(): void
    {
        // Real files, in places a traversal would be aiming at.
        Storage::disk('local')->put('ic/front.jpg', 'an-identity-card');
        file_put_contents($this->backupsDirectory() . DIRECTORY_SEPARATOR . 'notes.txt', 'not an archive');

        $owner = $this->superAdmin();

        /*
         | A backslash cannot be put in a URL at all (Symfony rejects the request
         | before routing), so the Windows-style path is asserted against the store
         | directly in the test below rather than over HTTP.
         */
        foreach ([
            '../ic/front.jpg',
            '../../.env',
            '/etc/passwd',
            'notes.txt',
            'backup-2026-08-01-030000-auto.zip',  // the right shape, but not there
            'backup-2026-08-01-030000-other.zip', // not a type we write
        ] as $attempt) {
            $this->actingAs($owner)
                ->get('/admin/settings/backup/' . $attempt)
                ->assertNotFound();

            $this->actingAs($owner)
                ->delete('/admin/settings/backup/' . $attempt)
                ->assertNotFound();
        }

        // Nothing was read and nothing was removed.
        $this->assertTrue(Storage::disk('local')->exists('ic/front.jpg'));
        $this->assertFileExists($this->backupsDirectory() . DIRECTORY_SEPARATOR . 'notes.txt');
        $this->assertDatabaseMissing('activity_logs', ['action' => 'settings.backup.download']);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'settings.backup.delete']);
    }

    /* ---------------------------------------------------------------------
     | Not reachable over HTTP
     * ------------------------------------------------------------------ */

    public function test_an_archive_cannot_be_fetched_over_http(): void
    {
        $name = 'backup-2026-08-09-030000-auto.zip';
        $this->writeArchive($name);

        /*
         | The only route that reaches the private disk at all is Laravel's own
         | storage/{path}, registered because config/filesystems.php sets
         | 'serve' => true on the local disk. Its visibility is private, so
         | ServeFile demands a valid relative signature: 403 off production, 404 on
         | it, and a signature cannot be produced without the APP_KEY.
         |
         | The first three are the literal filesystem paths somebody would try if
         | the application had been cloned straight into public_html. Each is
         | refused — what matters is that none of them answers with the archive.
         */
        foreach ([
            '/storage/app/private/backups/' . $name,
            '/storage/private/backups/' . $name,
            '/backups/' . $name,
            '/storage/backups/' . $name,
        ] as $url) {
            $status = $this->get($url)->getStatusCode();

            $this->assertContains($status, [403, 404], $url . ' answered ' . $status);
        }

        // Still there: nothing above read, moved or removed it.
        $this->assertContains($name, $this->archives());
    }

    public function test_the_store_refuses_the_same_names_directly(): void
    {
        $store = app(BackupStore::class);
        $name = 'backup-2026-09-01-030000-manual.zip';
        $this->writeArchive($name);

        $this->assertNotNull($store->resolve($name));

        foreach ([
            '../' . $name,
            './' . $name,
            '..\\' . $name,
            $this->privateRoot . '/ic/front.jpg',
            'C:\\Windows\\win.ini',
            'manifest.json',
            '',
        ] as $attempt) {
            $this->assertNull($store->resolve($attempt), $attempt . ' should not resolve');
            $this->assertFalse($store->delete($attempt));
        }

        $this->assertContains($name, $this->archives());
    }
}

/**
 * Stands in for mysqldump, because the test database is sqlite in memory.
 *
 * Writes a known gzip so the runner's half of the work — the archive, the
 * manifest, the exclusion, the cleanup — can be asserted on exactly.
 */
class FakeDumper implements DatabaseDumper
{
    public const SQL = "-- a dump\nSET FOREIGN_KEY_CHECKS=0;\n";

    public function __construct(private readonly bool $fail = false)
    {
    }

    public function dump(string $target): string
    {
        if ($this->fail) {
            throw new BackupException('mysqldump exited with code 2. Access denied for the database user.');
        }

        $gz = gzopen($target, 'wb');
        gzwrite($gz, self::SQL);
        gzclose($gz);

        return 'mysqldump';
    }
}
