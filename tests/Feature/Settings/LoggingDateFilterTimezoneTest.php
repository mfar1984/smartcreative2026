<?php

namespace Tests\Feature\Settings;

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Logging date filter speaks the office clock, not UTC.
 *
 * created_at is stored in UTC, but the screen shows every timestamp in the
 * display zone (App\Support\LocalTime). The date pickers are read on that same
 * clock, so a 'from'/'to' day must be converted to the UTC instant range that
 * local day covers before it touches the column. The old whereDate compared a
 * local date string against the raw UTC column, so an entry made near local
 * midnight — shown as 08 Oct locally while stored as 07 Oct UTC — fell out of a
 * range the user believed included it. That is the 'chip counts 77, lists 0'
 * bug this covers. Both the audit tab and the activity tab filtered the same
 * wrong way, so both are exercised here.
 */
class LoggingDateFilterTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The row is the thing under test: the zone the pickers are read on is the
        // one the General Config screen saved. flush() because GeneralSettings
        // memoises per process and the container booted before this line ran.
        Setting::write('general.timezone', 'Asia/Kuala_Lumpur', 'general');
        GeneralSettings::flush();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $role = Role::create([
            'slug' => 'staff-' . uniqid(),
            'name' => 'Staff',
            'is_active' => true,
        ]);

        $ids = [];

        foreach (array_unique(['admin.access', ...$permissions]) as $index => $slug) {
            $ids[] = Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'group' => 'Event', 'module' => 'Participants', 'action' => 'view', 'sort_order' => $index],
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

    private function auditUser(): User
    {
        return $this->userWith(['logs.activity.view', 'logs.audit.view']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function audit(array $overrides): AuditLog
    {
        $createdAt = $overrides['created_at'];
        unset($overrides['created_at']);

        $entry = AuditLog::create($overrides + [
            'actor_label' => 'Staff',
            'actor_role' => 'Super Admin',
            'auditable_type' => Setting::class,
            'auditable_id' => 1,
            'event' => 'updated',
            'ip_address' => '203.0.113.9',
        ]);

        $entry->forceFill(['created_at' => $createdAt])->save();

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function activity(array $overrides): ActivityLog
    {
        $createdAt = $overrides['created_at'];
        unset($overrides['created_at']);

        $entry = ActivityLog::create($overrides + [
            'actor_label' => 'Staff',
            'action' => 'settings.general.updated',
            'level' => 'info',
            'category' => 'Settings',
            'description' => 'Saved the general configuration.',
            'ip_address' => '203.0.113.9',
        ]);

        $entry->forceFill(['created_at' => $createdAt])->save();

        return $entry;
    }

    /** @param array<string, string> $filters */
    private function auditIds(User $user, array $filters): array
    {
        $response = $this->actingAs($user)
            ->get(route('admin.settings.logging', ['tab' => 'audit'] + $filters));

        $response->assertOk();

        return $response->viewData('auditEntries')->pluck('id')->all();
    }

    /** @param array<string, string> $filters */
    private function activityIds(User $user, array $filters): array
    {
        $response = $this->actingAs($user)
            ->get(route('admin.settings.logging', ['tab' => 'activity'] + $filters));

        $response->assertOk();

        return $response->viewData('activityEntries')->pluck('id')->all();
    }

    /* ------------------------------------------------------------------ */

    public function test_a_local_day_entry_stored_on_the_previous_utc_day_is_returned(): void
    {
        $user = $this->auditUser();

        // 2026-10-07 20:00 UTC == 2026-10-08 04:00 in Kuala Lumpur. The user
        // filtering the 8th (local) must get it, even though the UTC date is the 7th.
        $entry = $this->audit([
            'event' => 'payment-settled',
            'created_at' => Carbon::parse('2026-10-07 20:00:00', 'UTC'),
        ]);

        $ids = $this->auditIds($user, ['from' => '2026-10-08', 'to' => '2026-10-08']);

        $this->assertSame([$entry->id], $ids);
    }

    public function test_entries_either_side_of_local_midnight_are_bucketed_into_the_right_local_day(): void
    {
        $user = $this->auditUser();

        // Local midnight between 7 Oct and 8 Oct is 2026-10-07 16:00 UTC.
        // Just before: 15:59 UTC -> 23:59 local on the 7th.
        $before = $this->audit(['created_at' => Carbon::parse('2026-10-07 15:59:00', 'UTC')]);
        // Just after: 16:01 UTC -> 00:01 local on the 8th.
        $after = $this->audit(['created_at' => Carbon::parse('2026-10-07 16:01:00', 'UTC')]);

        $this->assertSame([$before->id], $this->auditIds($user, ['from' => '2026-10-07', 'to' => '2026-10-07']));
        $this->assertSame([$after->id], $this->auditIds($user, ['from' => '2026-10-08', 'to' => '2026-10-08']));
    }

    public function test_an_event_chip_combined_with_a_date_range_returns_the_rows_the_chip_counts(): void
    {
        $user = $this->auditUser();

        // Two payment-settled rows on the local 8th, one other event, one
        // payment-settled on a different day — the chip + range must return
        // exactly the two.
        $one = $this->audit(['event' => 'payment-settled', 'created_at' => Carbon::parse('2026-10-07 20:00:00', 'UTC')]);
        $two = $this->audit(['event' => 'payment-settled', 'created_at' => Carbon::parse('2026-10-08 09:00:00', 'UTC')]);
        $this->audit(['event' => 'created', 'created_at' => Carbon::parse('2026-10-08 09:00:00', 'UTC')]);
        $this->audit(['event' => 'payment-settled', 'created_at' => Carbon::parse('2026-10-10 09:00:00', 'UTC')]);

        $ids = $this->auditIds($user, [
            'event' => 'payment-settled',
            'from' => '2026-10-08',
            'to' => '2026-10-08',
        ]);

        sort($ids);
        $expected = [$one->id, $two->id];
        sort($expected);

        $this->assertSame($expected, $ids);
    }

    public function test_the_event_chip_filters_by_the_event_string_not_a_bare_boolean(): void
    {
        // Regression for the 'where event = 1' bug: $applyEvent && $filters['event']
        // collapsed to boolean true, so the chip listing matched nothing. With no
        // date range in play, clicking the chip must return exactly its rows.
        $user = $this->auditUser();

        $settled = $this->audit(['event' => 'payment-settled', 'created_at' => Carbon::parse('2026-10-08 09:00:00', 'UTC')]);
        $this->audit(['event' => 'created', 'created_at' => Carbon::parse('2026-10-08 09:00:00', 'UTC')]);

        $this->assertSame([$settled->id], $this->auditIds($user, ['event' => 'payment-settled']));
    }

    public function test_the_level_chip_filters_by_the_level_string_not_a_bare_boolean(): void
    {
        // Same latent bug on the activity tab: $applyLevel && $filters['level'].
        $user = $this->auditUser();

        $warn = $this->activity(['level' => 'warn', 'created_at' => Carbon::parse('2026-10-08 09:00:00', 'UTC')]);
        $this->activity(['level' => 'info', 'created_at' => Carbon::parse('2026-10-08 09:00:00', 'UTC')]);

        $this->assertSame([$warn->id], $this->activityIds($user, ['level' => 'warn']));
    }

    public function test_the_activity_tab_filters_the_same_corrected_way(): void
    {
        $user = $this->auditUser();

        $entry = $this->activity([
            'created_at' => Carbon::parse('2026-10-07 20:00:00', 'UTC'),
        ]);

        $ids = $this->activityIds($user, ['from' => '2026-10-08', 'to' => '2026-10-08']);

        $this->assertSame([$entry->id], $ids);
    }

    public function test_a_from_with_no_to_returns_everything_from_that_local_day_onward(): void
    {
        $user = $this->auditUser();

        // Local 7th 23:00 (15:00 UTC) is excluded by from=8th; the 8th is kept.
        $this->audit(['created_at' => Carbon::parse('2026-10-07 15:00:00', 'UTC')]);
        $kept = $this->audit(['created_at' => Carbon::parse('2026-10-08 09:00:00', 'UTC')]);

        $this->assertSame([$kept->id], $this->auditIds($user, ['from' => '2026-10-08']));
    }

    public function test_a_to_with_no_from_returns_everything_up_to_that_local_day(): void
    {
        $user = $this->auditUser();

        $kept = $this->audit(['created_at' => Carbon::parse('2026-10-07 15:00:00', 'UTC')]);
        // Local 9th (01:00 UTC on the 9th) is after end of the local 8th, excluded.
        $this->audit(['created_at' => Carbon::parse('2026-10-09 01:00:00', 'UTC')]);

        $this->assertSame([$kept->id], $this->auditIds($user, ['to' => '2026-10-08']));
    }

    public function test_a_malformed_date_is_ignored_rather_than_zeroing_the_result(): void
    {
        $user = $this->auditUser();

        $entry = $this->audit(['created_at' => Carbon::parse('2026-10-08 09:00:00', 'UTC')]);

        // Garbage in 'from' is dropped; the row is still returned, not filtered away.
        $this->assertSame([$entry->id], $this->auditIds($user, ['from' => 'not-a-date']));
    }
}
