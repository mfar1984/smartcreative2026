<?php

namespace Tests\Feature\Settings;

use App\Models\Permission;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\Setting;
use App\Models\User;
use App\Support\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Security Log tab: filters by severity, by IP and by date.
 *
 * The date filter is the one worth pinning. last_seen_at is stored in UTC, the
 * screen reads it on the office clock, and the picker is read on that same clock —
 * so a local day has to be CONVERTED to the UTC instants it spans rather than
 * compared against the raw column. That bug has shipped four times in this project,
 * which is why the clock here is pinned at 16:30 UTC: it is already tomorrow in
 * Asia/Kuching, so a filter that compares the wrong way round cannot pass by luck.
 */
class SecurityLogScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The office clock the pickers are read on. flush() because GeneralSettings
        // memoises per process and the container booted before this line ran.
        Setting::write('general.timezone', 'Asia/Kuching', 'general');
        GeneralSettings::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function reader(): User
    {
        $role = Role::create([
            'slug' => 'staff-' . uniqid(),
            'name' => 'Staff',
            'is_active' => true,
        ]);

        $ids = [];

        foreach (['admin.access', 'logs.activity.view', 'logs.security.view'] as $index => $slug) {
            $ids[] = Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'group' => 'Logging', 'module' => 'Security Log', 'action' => 'view', 'sort_order' => $index],
            )->id;
        }

        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'Staff',
            'username' => 'staff-' . uniqid(),
            'email' => uniqid() . '@example.com',
            'password' => 'secret-password',
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function event(array $overrides = []): SecurityEvent
    {
        $seen = $overrides['last_seen_at'] ?? now();
        unset($overrides['last_seen_at']);

        return SecurityEvent::create($overrides + [
            'severity' => SecurityEvent::SEVERITY_WARNING,
            'type' => SecurityEvent::TYPE_ACCESS_DENIED,
            'description' => 'Missing the "payments.view" permission.',
            'ip_address' => '203.0.113.9',
            'method' => 'GET',
            'path' => '/admin/payments',
            'hits' => 1,
            'first_seen_at' => $seen,
            'last_seen_at' => $seen,
        ]);
    }

    /**
     * @param  array<string, string>  $filters
     * @return array<int, int>
     */
    private function ids(User $user, array $filters = []): array
    {
        $response = $this->actingAs($user)
            ->get(route('admin.settings.logging', ['tab' => 'security'] + $filters));

        $response->assertOk();

        return $response->viewData('securityEntries')->pluck('id')->all();
    }

    /* ------------------------------------------------------------------ */

    public function test_the_tab_is_drawn_beside_activity_and_audit(): void
    {
        $user = $this->reader();

        $this->event();

        $response = $this->actingAs($user)->get(route('admin.settings.logging', ['tab' => 'security']));

        $response->assertOk();
        $response->assertSee('Security Log');
        $response->assertSee('Missing the &quot;payments.view&quot; permission.', false);
        $response->assertSee('203.0.113.9');
        $response->assertSee('Access denied');
    }

    public function test_it_filters_by_severity(): void
    {
        $user = $this->reader();

        $warning = $this->event();
        $critical = $this->event([
            'severity' => SecurityEvent::SEVERITY_CRITICAL,
            'type' => SecurityEvent::TYPE_PATH_REFUSED,
            'description' => 'Backup file name refused.',
        ]);
        $this->event([
            'severity' => SecurityEvent::SEVERITY_INFO,
            'type' => SecurityEvent::TYPE_SUSPICIOUS_INPUT,
            'description' => 'Observed a sql-tautology pattern.',
        ]);

        $this->assertSame([$warning->id], $this->ids($user, ['severity' => 'warning']));
        $this->assertSame([$critical->id], $this->ids($user, ['severity' => 'critical']));
        $this->assertCount(3, $this->ids($user));

        // A severity nobody uses is dropped rather than returning nothing.
        $this->assertCount(3, $this->ids($user, ['severity' => 'nonsense']));
    }

    public function test_the_severity_chips_count_every_row_the_chip_would_return(): void
    {
        $user = $this->reader();

        $this->event();
        $this->event();
        $this->event(['severity' => SecurityEvent::SEVERITY_CRITICAL]);

        $response = $this->actingAs($user)
            ->get(route('admin.settings.logging', ['tab' => 'security', 'severity' => 'critical']));

        $counts = $response->viewData('severityCounts');

        // The severity filter itself is left out of the counts, so the chips keep
        // showing what each one would bring back.
        $this->assertSame(2, $counts['warning']);
        $this->assertSame(1, $counts['critical']);
        $this->assertSame(0, $counts['info']);
    }

    public function test_it_filters_by_ip(): void
    {
        $user = $this->reader();

        $mine = $this->event(['ip_address' => '203.0.113.9']);
        $this->event(['ip_address' => '198.51.100.44']);

        $this->assertSame([$mine->id], $this->ids($user, ['ip' => '203.0.113.9']));

        // A partial address narrows a whole network, which is how somebody reads a
        // sweep that came from one range.
        $this->assertSame([$mine->id], $this->ids($user, ['ip' => '203.0.113.']));
    }

    public function test_it_shows_how_many_times_each_address_has_been_seen(): void
    {
        $user = $this->reader();

        $this->event(['ip_address' => '198.51.100.44', 'hits' => 120, 'path' => '/admin/payments']);
        $this->event(['ip_address' => '198.51.100.44', 'hits' => 80, 'path' => '/admin/coupons']);
        $this->event(['ip_address' => '203.0.113.9', 'hits' => 3]);

        $response = $this->actingAs($user)->get(route('admin.settings.logging', ['tab' => 'security']));

        $totals = $response->viewData('ipTotals');

        // Summed over hits, not counted over rows, because repeats collapse. 200
        // says somebody is walking the URL space; 3 says somebody fumbled.
        $this->assertSame(200, $totals['198.51.100.44']);
        $this->assertSame(3, $totals['203.0.113.9']);
    }

    /* ------------------------------------------------------------------
     | The date filter, at an hour where UTC and Malaysia disagree
     * ------------------------------------------------------------------ */

    public function test_the_date_filter_converts_its_boundaries_rather_than_comparing_a_utc_column(): void
    {
        // 16:30 UTC on the 18th is 00:30 on the 19th in Asia/Kuching. An operator
        // reading the screen right now sees "19 Oct"; a filter that compared the UTC
        // column against the string '2026-10-19' would lose the row entirely.
        Carbon::setTestNow(Carbon::parse('2026-10-18 16:30:00', 'UTC'));

        $user = $this->reader();

        $tonight = $this->event(['last_seen_at' => Carbon::parse('2026-10-18 16:30:00', 'UTC')]);

        $this->assertSame([$tonight->id], $this->ids($user, ['from' => '2026-10-19', 'to' => '2026-10-19']));

        // And it is NOT on the local 18th, which is where the broken comparison
        // would have put it.
        $this->assertSame([], $this->ids($user, ['from' => '2026-10-18', 'to' => '2026-10-18']));
    }

    public function test_rows_either_side_of_local_midnight_land_on_the_right_local_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-18 16:30:00', 'UTC'));

        $user = $this->reader();

        // Local midnight between the 18th and the 19th is 16:00 UTC on the 18th.
        $before = $this->event([
            'last_seen_at' => Carbon::parse('2026-10-18 15:59:00', 'UTC'),
            'path' => '/admin/payments',
        ]);
        $after = $this->event([
            'last_seen_at' => Carbon::parse('2026-10-18 16:01:00', 'UTC'),
            'path' => '/admin/coupons',
        ]);

        $this->assertSame([$before->id], $this->ids($user, ['from' => '2026-10-18', 'to' => '2026-10-18']));
        $this->assertSame([$after->id], $this->ids($user, ['from' => '2026-10-19', 'to' => '2026-10-19']));
    }

    public function test_a_severity_chip_combined_with_a_date_range_returns_what_the_chip_counts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-18 16:30:00', 'UTC'));

        $user = $this->reader();

        $wanted = $this->event([
            'severity' => SecurityEvent::SEVERITY_CRITICAL,
            'last_seen_at' => Carbon::parse('2026-10-18 16:30:00', 'UTC'),
            'path' => '/admin/a',
        ]);
        $this->event([
            'severity' => SecurityEvent::SEVERITY_WARNING,
            'last_seen_at' => Carbon::parse('2026-10-18 16:30:00', 'UTC'),
            'path' => '/admin/b',
        ]);
        $this->event([
            'severity' => SecurityEvent::SEVERITY_CRITICAL,
            'last_seen_at' => Carbon::parse('2026-10-16 02:00:00', 'UTC'),
            'path' => '/admin/c',
        ]);

        $this->assertSame([$wanted->id], $this->ids($user, [
            'severity' => 'critical',
            'from' => '2026-10-19',
            'to' => '2026-10-19',
        ]));
    }

    public function test_a_malformed_date_is_ignored_rather_than_zeroing_the_result(): void
    {
        $user = $this->reader();

        $event = $this->event();

        $this->assertSame([$event->id], $this->ids($user, ['from' => 'not-a-date']));
    }

    public function test_it_searches_the_description_the_type_and_the_path(): void
    {
        $user = $this->reader();

        $denied = $this->event(['description' => 'Missing the "payments.refund" permission.']);
        $refused = $this->event([
            'type' => SecurityEvent::TYPE_PATH_REFUSED,
            'description' => 'Backup file name refused: "../../.env" carries a path separator.',
            'path' => '/admin/settings/general/backup/download',
        ]);

        $this->assertSame([$denied->id], $this->ids($user, ['q' => 'payments.refund']));
        $this->assertSame([$refused->id], $this->ids($user, ['q' => 'path_refused']));
        $this->assertSame([$refused->id], $this->ids($user, ['q' => 'backup/download']));
    }

    public function test_the_screen_says_banning_is_off_while_it_is(): void
    {
        $user = $this->reader();

        $response = $this->actingAs($user)->get(route('admin.settings.logging', ['tab' => 'security']));

        $response->assertOk();
        $this->assertFalse($response->viewData('securityBanArmed'));
        $response->assertSee('refusals are counted and shown here, and no address is blocked by', false);
    }
}
