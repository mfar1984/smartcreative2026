<?php

namespace Tests\Feature\Security;

use App\Models\ActivityLog;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\SecurityEvent;
use App\Models\Setting;
use App\Services\AdminLogger;
use App\Services\Backup\BackupStore;
use App\Support\ParticipantOptions;
use App\Support\SecuritySettings;
use Illuminate\Support\Facades\Schema;

/**
 * The Security Log records the refusals the system already makes.
 *
 * What was missing before this log was never the refusing — a prober could walk the
 * whole admin URL space and be turned away every time — it was that nobody could
 * see it happen. So most of this file is about VISIBILITY, and two tests are about
 * the opposite: that noticing a probe does not refuse anybody, and that an
 * apostrophe in a surname is not an attack.
 */
class SecurityLogTest extends SecurityTestCase
{
    private const PROBER = '198.51.100.44';

    /* ------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    /** A free, open event anybody can sign up to, for the two "not refused" tests. */
    private function openEvent(): Event
    {
        return Event::create([
            'slug' => 'event-' . uniqid(),
            'title' => 'Drop Zone Open Day',
            'category' => 'Community',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_GROUPING,
            'fee' => 0,
            'seats_total' => 100,
            'min_players' => 1,
            'max_players' => 5,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function person(array $overrides = []): array
    {
        return $overrides + [
            'role' => ParticipantOptions::ROLE_PARTICIPANT,
            'full_name' => "Siobhan O'Brien",
            'ic_number' => '900101010001',
            'address_line_1' => "12 O'Connell Road",
            'city' => 'Kuching',
            'state' => 'Sarawak',
            'country' => 'Malaysia',
            'phone' => '0123456789',
            'email' => 'siobhan@example.test',
            'gender' => 'female',
            'race' => 'malay',
        ];
    }

    private function onlyEvent(): SecurityEvent
    {
        $events = SecurityEvent::query()->get();

        $this->assertCount(
            1,
            $events,
            'Expected exactly one security event, got ' . $events->count() . ': ' . $events->pluck('type')->implode(', '),
        );

        return $events->first();
    }

    /**
     * Make the real CSRF middleware run.
     *
     * Laravel skips token verification while the container reports it is running
     * tests, which is why every other POST in this suite needs no token. Moving the
     * container's env off 'testing' for one test is the only way to exercise the
     * genuine 419, which is the thing being asserted.
     */
    private function enforceCsrf(): void
    {
        $this->app['env'] = 'local';
    }

    /* ------------------------------------------------------------------
     | 403 from the permission middleware
     * ------------------------------------------------------------------ */

    public function test_a_403_from_the_permission_middleware_names_the_missing_permission(): void
    {
        // A role that may reach the admin and holds nothing else.
        $user = $this->userWith(['admin.access']);

        $this->actingAs($user)
            ->fromIp(self::PROBER)
            ->get(route('admin.settings.logging'))
            ->assertForbidden();

        $event = $this->onlyEvent();

        $this->assertSame(SecurityEvent::TYPE_ACCESS_DENIED, $event->type);
        $this->assertSame(SecurityEvent::SEVERITY_WARNING, $event->severity);
        $this->assertStringContainsString('logs.activity.view', $event->description);
        $this->assertSame(self::PROBER, $event->ip_address);
        $this->assertSame($user->id, $event->user_id);
        $this->assertSame('GET', $event->method);
        $this->assertSame('/admin/settings/logging', $event->path);
        $this->assertSame(1, $event->hits);
    }

    public function test_a_cross_tab_id_in_user_management_is_recorded_as_a_refusal(): void
    {
        // Somebody granted administrator management, changing the number in the URL
        // to reach an account that belongs to the Sponsorship tab. The refusal
        // already existed; this is that refusal becoming visible.
        $actor = $this->userWith(['admin.access', 'users.view', 'users.delete']);
        $sponsor = $this->userWith(['admin.access']);
        $sponsor->forceFill(['is_sponsor' => true])->save();

        $this->actingAs($actor)
            ->fromIp(self::PROBER)
            ->delete(route('admin.settings.users.destroy', $sponsor))
            ->assertForbidden();

        $event = $this->onlyEvent();

        $this->assertSame(SecurityEvent::TYPE_ACCESS_DENIED, $event->type);
        $this->assertStringContainsString('tab', $event->description);
    }

    public function test_a_sponsor_asking_for_another_sponsorship_id_is_recorded(): void
    {
        $sponsor = $this->userWith(['admin.access', 'sponsorship.portal.view']);
        $sponsor->forceFill(['is_sponsor' => true])->save();

        $this->actingAs($sponsor)
            ->fromIp(self::PROBER)
            ->get(route('admin.sponsorship.index', ['sponsor' => 9999]))
            ->assertForbidden();

        $this->assertSame(SecurityEvent::TYPE_ACCESS_DENIED, $this->onlyEvent()->type);
    }

    /* ------------------------------------------------------------------
     | CSRF, signatures and rate limits
     * ------------------------------------------------------------------ */

    public function test_a_csrf_failure_writes_one_event(): void
    {
        $this->enforceCsrf();

        $event = $this->openEvent();

        $this->fromIp(self::PROBER)
            ->post(route('registration.store', ['event' => $event->slug]), [
                'participants' => [$this->person()],
            ])
            ->assertStatus(419);

        $recorded = $this->onlyEvent();

        $this->assertSame(SecurityEvent::TYPE_CSRF_FAILURE, $recorded->type);
        $this->assertSame(SecurityEvent::SEVERITY_WARNING, $recorded->severity);
        $this->assertSame(self::PROBER, $recorded->ip_address);
        $this->assertSame(0, EventRegistration::query()->count());
    }

    public function test_a_bad_signature_writes_one_event(): void
    {
        // Every payment and order page is signed, because a reference like
        // SO-2026-0007 is trivial to count upwards through.
        $this->fromIp(self::PROBER)
            ->get(route('shop.order', ['reference' => 'SO-2026-0007']) . '?signature=tampered')
            ->assertForbidden();

        $event = $this->onlyEvent();

        $this->assertSame(SecurityEvent::TYPE_INVALID_SIGNATURE, $event->type);

        // Warning, not critical: these links live thirty days and reach participants
        // by email, so the commonest cause is somebody opening last month's link.
        $this->assertSame(SecurityEvent::SEVERITY_WARNING, $event->severity);

        // The signature itself is plumbing, not intelligence, and is dropped before
        // the context is written.
        $this->assertArrayNotHasKey('signature', $event->context['input'] ?? []);
    }

    public function test_a_refused_size_confirmation_link_writes_one_event(): void
    {
        // That page answers with a 403 RESPONSE rather than an exception, so a
        // participant gets a readable page instead of a stack trace — which means it
        // cannot arrive through the hook and is recorded by the controller itself.
        $this->fromIp(self::PROBER)
            ->get(route('registration.sizes', ['reference' => 'REG-2026-0007']) . '?signature=tampered')
            ->assertForbidden();

        $event = $this->onlyEvent();

        $this->assertSame(SecurityEvent::TYPE_INVALID_SIGNATURE, $event->type);
        $this->assertStringContainsString('size confirmation link', $event->description);
    }

    public function test_a_rate_limit_hit_writes_one_event(): void
    {
        // The sign-in POST is throttle:admin-login, 10 a minute per address by
        // default. The eleventh is refused by the middleware before the controller
        // sees it. The ten before it are ordinary wrong passwords, which this log
        // deliberately does not record.
        $this->fromIp(self::PROBER);

        for ($i = 0; $i < 10; $i++) {
            $this->failedSignIn();
        }

        $this->failedSignIn()->assertStatus(429);

        $event = $this->onlyEvent();

        $this->assertSame(SecurityEvent::TYPE_RATE_LIMITED, $event->type);
        $this->assertSame(SecurityEvent::SEVERITY_WARNING, $event->severity);
    }

    /* ------------------------------------------------------------------
     | Sign-in refusals, which answer with a validation error and never an
     | HTTP status, so they are recorded by hand
     * ------------------------------------------------------------------ */

    public function test_a_banned_address_attempting_to_sign_in_writes_one_event(): void
    {
        $user = $this->administrator();

        $this->activeBan(self::PROBER);

        $this->fromIp(self::PROBER)
            ->signIn($user->username)
            ->assertSessionHasErrors('username');

        $event = $this->onlyEvent();

        $this->assertSame(SecurityEvent::TYPE_LOGIN_BANNED, $event->type);
        $this->assertSame(SecurityEvent::SEVERITY_CRITICAL, $event->severity);
        $this->assertSame(self::PROBER, $event->ip_address);
    }

    public function test_an_ordinary_wrong_password_is_not_a_security_event(): void
    {
        // Deliberate: that is somebody mistyping. It already goes to the activity
        // log and the sign-in ban already counts it. Filling this log with it would
        // bury the rows that matter.
        $user = $this->administrator();

        $this->fromIp(self::PROBER)
            ->signIn($user->username, 'wrong-password')
            ->assertSessionHasErrors('username');

        $this->assertSame(0, SecurityEvent::query()->count());
    }

    public function test_a_sign_in_refused_by_the_ip_allowlist_writes_one_event(): void
    {
        $this->security(['ip_allowlist' => '203.0.113.10']);

        $user = $this->administrator();

        $this->fromIp(self::PROBER)
            ->signIn($user->username)
            ->assertSessionHasErrors('username');

        $event = $this->onlyEvent();

        $this->assertSame(SecurityEvent::TYPE_LOGIN_NOT_ALLOWLISTED, $event->type);
        $this->assertSame(self::PROBER, $event->ip_address);
    }

    public function test_a_session_leaving_the_allowlist_is_recorded(): void
    {
        $this->security(['ip_allowlist' => '203.0.113.10']);

        $user = $this->userWith(['admin.access', 'logs.activity.view']);

        $this->actingAs($user)
            ->fromIp(self::PROBER)
            ->get(route('admin.settings.logging'))
            ->assertRedirect(route('admin.login', ['notice' => 'network']));

        $this->assertSame(SecurityEvent::TYPE_SESSION_NOT_ALLOWLISTED, $this->onlyEvent()->type);
    }

    /* ------------------------------------------------------------------
     | The backup store, which refuses a name rather than repairing it
     * ------------------------------------------------------------------ */

    public function test_a_path_refused_by_the_backup_store_writes_one_event(): void
    {
        $store = app(BackupStore::class);

        $this->assertNull($store->resolve('../../.env'));

        $event = $this->onlyEvent();

        $this->assertSame(SecurityEvent::TYPE_PATH_REFUSED, $event->type);
        $this->assertSame(SecurityEvent::SEVERITY_CRITICAL, $event->severity);
        $this->assertStringContainsString('path separator', $event->description);
    }

    public function test_a_name_that_is_not_an_archive_is_also_refused_and_recorded(): void
    {
        $store = app(BackupStore::class);

        $this->assertNull($store->resolve('.env'));

        $this->assertStringContainsString('not a backup archive name', $this->onlyEvent()->description);
    }

    public function test_a_missing_but_well_formed_archive_name_is_not_recorded(): void
    {
        // A bookmarked link to an archive retention has since removed. That happens
        // every week; recording it would bury the two cases above.
        $store = app(BackupStore::class);

        $this->assertNull($store->resolve('backup-2026-10-09-030000-auto.zip'));

        $this->assertSame(0, SecurityEvent::query()->count());
    }

    /* ------------------------------------------------------------------
     | The part that observes rather than gates
     * ------------------------------------------------------------------ */

    public function test_a_probing_pattern_is_recorded_and_the_request_is_not_refused(): void
    {
        // THE TEST THAT PROVES IT OBSERVES. The response has to be the normal one:
        // the registration goes through, exactly as it would without the probe.
        $event = $this->openEvent();

        $this->fromIp(self::PROBER)
            ->post(route('registration.store', ['event' => $event->slug]), [
                'team_name' => 'Drop Zone',
                'participants' => [$this->person(['address_line_1' => "12 Jalan Satu' OR 1=1--"])],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, EventRegistration::query()->count());

        $recorded = $this->onlyEvent();

        $this->assertSame(SecurityEvent::TYPE_SUSPICIOUS_INPUT, $recorded->type);
        $this->assertSame(SecurityEvent::SEVERITY_INFO, $recorded->severity);
        $this->assertSame('sql-tautology', $recorded->context['pattern']);
        $this->assertSame('address_line_1', $recorded->context['field']);
        $this->assertStringContainsString('served normally', $recorded->description);
    }

    public function test_a_name_with_an_apostrophe_is_neither_recorded_nor_refused(): void
    {
        // O'Brien signs up for the Drop Zone open day. A pattern gate would have
        // refused both of those, which is an outcome worse than the thing it
        // would prevent.
        $event = $this->openEvent();

        $this->fromIp(self::PROBER)
            ->post(route('registration.store', ['event' => $event->slug]), [
                'team_name' => 'Drop Zone',
                'participants' => [$this->person()],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('event_participants', ['full_name' => "Siobhan O'Brien"]);
        $this->assertSame(0, SecurityEvent::query()->count());
    }

    /* ------------------------------------------------------------------
     | Secrets
     * ------------------------------------------------------------------ */

    public function test_no_password_value_ever_reaches_the_log(): void
    {
        // A refused sign in is the one security event that certainly carries a
        // password in its payload, so it is where this has to be proven.
        $this->activeBan(self::PROBER);

        $this->fromIp(self::PROBER)
            ->post(route('admin.login.attempt'), [
                'username' => 'administrator',
                'password' => 'Hunter2-the-real-one!',
                'password_confirmation' => 'Hunter2-the-real-one!',
                'api_key' => 'sk-live-should-never-appear',
            ])
            ->assertSessionHasErrors('username');

        $event = $this->onlyEvent();
        $serialised = (string) json_encode($event->toArray());

        $this->assertStringNotContainsString('Hunter2-the-real-one!', $serialised);
        $this->assertStringNotContainsString('sk-live-should-never-appear', $serialised);
        $this->assertSame('[redacted]', $event->context['input']['password']);
        $this->assertSame('[redacted]', $event->context['input']['password_confirmation']);
        $this->assertSame('[redacted]', $event->context['input']['api_key']);

        // The username is not a secret, and is the whole point of the row.
        $this->assertSame('administrator', $event->context['input']['username']);
    }

    /* ------------------------------------------------------------------
     | Bounding a flood
     * ------------------------------------------------------------------ */

    public function test_repeats_of_the_same_refusal_collapse_into_one_row(): void
    {
        $user = $this->userWith(['admin.access']);

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)
                ->fromIp(self::PROBER)
                ->get(route('admin.settings.logging'))
                ->assertForbidden();
        }

        $event = $this->onlyEvent();

        $this->assertSame(6, $event->hits);
        $this->assertTrue($event->last_seen_at->greaterThanOrEqualTo($event->first_seen_at));
    }

    public function test_the_same_address_on_different_paths_stays_on_separate_rows(): void
    {
        // The shape that says "somebody is walking the URL space" has to survive the
        // collapsing, so only the same path folds together.
        $user = $this->userWith(['admin.access']);

        $this->actingAs($user)->fromIp(self::PROBER)
            ->get(route('admin.settings.logging'))->assertForbidden();
        $this->actingAs($user)->fromIp(self::PROBER)
            ->get(route('admin.settings.roles'))->assertForbidden();

        $this->assertSame(2, SecurityEvent::query()->count());
    }

    /* ------------------------------------------------------------------
     | Noise
     * ------------------------------------------------------------------ */

    public function test_the_health_check_never_reaches_the_log(): void
    {
        $this->fromIp(self::PROBER)->get('/up?probe=' . urlencode("' OR 1=1--"));

        $this->assertSame(0, SecurityEvent::query()->count());
    }

    public function test_a_not_found_is_not_recorded(): void
    {
        // A 404 is any mistyped URL. Recording those would bury the refusals.
        $this->fromIp(self::PROBER)
            ->get('/this-page-does-not-exist')
            ->assertNotFound();

        $this->assertSame(0, SecurityEvent::query()->count());
    }

    public function test_a_validation_error_is_not_recorded(): void
    {
        $event = $this->openEvent();

        $this->fromIp(self::PROBER)
            ->post(route('registration.store', ['event' => $event->slug]), [])
            ->assertSessionHasErrors();

        $this->assertSame(0, SecurityEvent::query()->count());
    }

    /* ------------------------------------------------------------------
     | The switches, and failure
     * ------------------------------------------------------------------ */

    public function test_a_security_event_is_written_with_both_logging_switches_off(): void
    {
        // The reason this log has a table of its own: a record somebody can silence
        // is worth nothing on the day it matters.
        $this->security(['activity_log_enabled' => '0', 'audit_log_enabled' => '0']);

        $user = $this->userWith(['admin.access']);

        $this->actingAs($user)
            ->fromIp(self::PROBER)
            ->get(route('admin.settings.logging'))
            ->assertForbidden();

        $this->assertSame(1, SecurityEvent::query()->count());
    }

    public function test_the_activity_switch_still_suppresses_an_ordinary_activity_row(): void
    {
        // Part 3 is untouched: the switch still does what it did, and the exempt
        // prefixes are still exempt.
        $this->security(['activity_log_enabled' => '0']);

        AdminLogger::activity('shop.orders.viewed', 'Opened the orders list.');
        AdminLogger::activity('auth.login', 'Signed in to the admin area.');
        AdminLogger::activity('security.banned', 'An address was blocked.');

        $this->assertSame(0, ActivityLog::query()->where('action', 'shop.orders.viewed')->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'auth.login')->count());

        // The new always-recorded prefix: the one line saying the system started
        // blocking an address survives the switch too.
        $this->assertSame(1, ActivityLog::query()->where('action', 'security.banned')->count());
    }

    public function test_a_failure_inside_the_recorder_leaves_the_403_a_403(): void
    {
        // The table is gone, so every write inside the recorder throws. A clean 403
        // must stay a clean 403: the thing watching the refusal must never be able
        // to cause the incident.
        Schema::drop('security_events');

        $user = $this->userWith(['admin.access']);

        $this->actingAs($user)
            ->fromIp(self::PROBER)
            ->get(route('admin.settings.logging'))
            ->assertForbidden();
    }

    public function test_a_failure_inside_the_observer_leaves_a_normal_request_normal(): void
    {
        Schema::drop('security_events');

        $event = $this->openEvent();

        $this->fromIp(self::PROBER)
            ->post(route('registration.store', ['event' => $event->slug]), [
                'team_name' => 'Drop Zone',
                'participants' => [$this->person(['address_line_1' => "12 Jalan Satu' OR 1=1--"])],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, EventRegistration::query()->count());
    }

    /* ------------------------------------------------------------------
     | Regression guard
     * ------------------------------------------------------------------ */

    public function test_defaults_on_an_empty_settings_table_leave_behaviour_identical(): void
    {
        $this->assertSame(0, Setting::query()->where('group', 'security')->count());

        // Banning by refusal ships OFF, and the sign-in ban, the limits and the
        // allowlist are exactly what they were.
        $this->assertFalse(SecuritySettings::securityBanEnabled());
        $this->assertTrue(SecuritySettings::banEnabled());
        $this->assertSame(10, SecuritySettings::banAfterFailures());
        $this->assertSame(15, SecuritySettings::banWindowMinutes());
        $this->assertSame(30, SecuritySettings::banDurationMinutes());
        $this->assertSame(10, SecuritySettings::loginAttemptsPerMinute());
        $this->assertSame(0, SecuritySettings::adminRequestsPerMinute());
        $this->assertSame([], SecuritySettings::ipAllowlist());
        $this->assertTrue(SecuritySettings::activityLogEnabled());
        $this->assertTrue(SecuritySettings::auditLogEnabled());

        // And the two new numbers are in place for when the switch is armed.
        $this->assertSame(20, SecuritySettings::securityBanAfterEvents());
        $this->assertSame(10, SecuritySettings::securityBanWindowMinutes());
    }

    public function test_the_activity_and_audit_tabs_still_open_for_the_roles_that_held_them(): void
    {
        $user = $this->userWith(['admin.access', 'logs.activity.view', 'logs.audit.view']);

        $this->actingAs($user)->get(route('admin.settings.logging'))->assertOk();
        $this->actingAs($user)->get(route('admin.settings.logging', ['tab' => 'audit']))->assertOk();

        // The Security Log is a third permission this role does not hold, so asking
        // for it by URL lands back on activity rather than leaking it.
        $response = $this->actingAs($user)->get(route('admin.settings.logging', ['tab' => 'security']));

        $response->assertOk();
        $this->assertSame('activity', $response->viewData('activeTab'));
        $this->assertArrayNotHasKey('security', $response->viewData('tabs'));
    }

    public function test_the_security_tab_opens_for_a_role_that_holds_it(): void
    {
        $user = $this->userWith(['admin.access', 'logs.activity.view', 'logs.security.view']);

        $response = $this->actingAs($user)->get(route('admin.settings.logging', ['tab' => 'security']));

        $response->assertOk();
        $this->assertSame('security', $response->viewData('activeTab'));
    }

    public function test_the_seeded_administrator_role_can_read_the_security_log(): void
    {
        // The permission is in the seeder's administrator list and in its BACKFILL,
        // so the role that already read the other two logs reads this one too
        // rather than landing in the catalogue granted to nobody.
        $response = $this->actingAs($this->administrator())
            ->get(route('admin.settings.logging', ['tab' => 'security']));

        $response->assertOk();
        $this->assertSame('security', $response->viewData('activeTab'));
    }

    public function test_a_super_admin_sees_every_tab(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.settings.logging', ['tab' => 'security']));

        $response->assertOk();
        $this->assertSame(['activity', 'audit', 'security'], array_keys($response->viewData('tabs')));
    }
}
