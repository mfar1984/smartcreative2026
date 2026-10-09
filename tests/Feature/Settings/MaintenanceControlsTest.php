<?php

namespace Tests\Feature\Settings;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\MaintenanceSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The four controls added to the Maintenance tab: the preview, the IP exemption
 * list, the expected return time and the scheduled window.
 *
 * The test that matters most here is the last one in the window section. The
 * window is decided by the middleware on every request, NOT by a job that flips
 * the switch, because a job-driven window leaves the public site down forever
 * when cron stops or the box is rebooted across it. So the proof is that the site
 * comes back with nothing whatsoever having run: no command, no scheduler, no
 * queued job, and the stored switch still off the whole time.
 *
 * Every test involving time pins the clock at 16:30 UTC, which is already
 * tomorrow in Malaysia. The window and the return time are wall-clock values on
 * the office clock, so reading them as UTC instants puts them a day and eight
 * hours out — and that is exactly the bug this project has fixed three times.
 * Pinned at this hour, the boundary is exercised on every run rather than only on
 * the days somebody happens to test after midnight.
 */
class MaintenanceControlsTest extends TestCase
{
    use RefreshDatabase;

    /** 2026-10-09 16:30 UTC is 2026-10-10 00:30 in Asia/Kuala_Lumpur. */
    private const NOW_UTC = '2026-10-09 16:30:00';

    private const HEADING = 'Upgrading the registration system';

    private const MESSAGE = 'Entries already submitted are safe. Nothing needs to be sent again.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        // The group is memoised in a static for the life of the process, so it is
        // cleared on the way in and on the way out: a switch left on here must not
        // decide what an unrelated test's public page renders.
        MaintenanceSettings::flush();
    }

    protected function tearDown(): void
    {
        MaintenanceSettings::flush();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    private function pinClock(): void
    {
        Carbon::setTestNow(Carbon::parse(self::NOW_UTC, 'UTC'));

        // The premise of every assertion about a window or a return time below.
        $this->assertSame('2026-10-10', Carbon::now(\App\Support\LocalTime::zone())->toDateString());
        $this->assertSame('2026-10-09', Carbon::now('UTC')->toDateString());
    }

    /** @param  array<string, string>  $values  key => value, without the group prefix */
    private function maintenance(array $values): void
    {
        foreach ($values as $key => $value) {
            MaintenanceSettings::write($key, $value);
        }

        MaintenanceSettings::flush();
    }

    private function maintenanceOn(): void
    {
        $this->maintenance([
            'enabled' => '1',
            'heading' => self::HEADING,
            'message' => self::MESSAGE,
        ]);
    }

    private function userWithRole(string $slug): User
    {
        return User::create([
            'name' => 'Test ' . $slug,
            'username' => $slug . '-' . uniqid(),
            'email' => uniqid() . '@example.test',
            'password' => 'Maintenance-Pass-123!',
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    private function superAdmin(): User
    {
        return $this->userWithRole(Role::SUPER_ADMIN);
    }

    /**
     * A user holding exactly these permissions, plus admin.access.
     *
     * admin.access is always granted: without it EnsureUserCanAccessAdmin refuses
     * the session before any permission on the route is consulted, and a 403 here
     * would be the wrong 403.
     *
     * @param  array<int, string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $role = Role::create([
            'slug' => 'custom-' . uniqid(),
            'name' => 'Custom',
            'is_active' => true,
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', array_unique(['admin.access', ...$permissions]))->pluck('id')->all(),
        );

        return User::create([
            'name' => 'Custom',
            'username' => 'custom-' . uniqid(),
            'email' => uniqid() . '@example.test',
            'password' => 'Maintenance-Pass-123!',
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    /** Every following request arrives from this client address. */
    private function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    /**
     * Stop acting as the signed-in administrator.
     *
     * Needed before any assertion about what a VISITOR sees: an administrator is
     * exempt from the holding page by design, so a public request made while still
     * signed in would answer 200 whatever the switch says.
     */
    private function signOut(): void
    {
        Auth::logout();
    }

    private function maintenanceTab()
    {
        return $this->actingAs($this->superAdmin())
            ->get(route('admin.settings.general', ['tab' => 'maintenance']));
    }

    /* =====================================================================
     | 1. Previewing the holding page without turning it on
     * ================================================================== */

    public function test_the_preview_shows_the_saved_copy_without_taking_the_site_down(): void
    {
        $this->maintenance([
            'enabled' => '0',
            'heading' => self::HEADING,
            'message' => self::MESSAGE,
        ]);

        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.settings.maintenance.preview'));

        // 200, not 503: this is a page inside the admin, and a 503 would have the
        // browser — and anything watching the admin — believe the panel had fallen.
        $response->assertOk();
        $response->assertSee(self::HEADING);
        $response->assertSee(self::MESSAGE);

        // Looking at it changed nothing, and the public site never went down.
        $this->assertSame('0', Setting::read('maintenance.enabled'));
        $this->assertFalse(MaintenanceSettings::isHoldingPublicSite());

        $this->signOut();
        $this->get('/')->assertOk()->assertDontSee(self::HEADING);
    }

    public function test_the_preview_works_with_nothing_saved_at_all(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.settings.maintenance.preview'))
            ->assertOk()
            ->assertSee('We are carrying out maintenance');
    }

    public function test_the_preview_renders_the_same_view_the_middleware_renders(): void
    {
        $this->maintenanceOn();

        // The assertion that breaks the moment somebody answers "the preview needs
        // its own copy of the Blade": both name the same view file.
        $live = $this->get('/');
        $live->assertStatus(503);
        $live->assertViewIs(MaintenanceSettings::HOLDING_PAGE_VIEW);

        $preview = $this->actingAs($this->superAdmin())
            ->get(route('admin.settings.maintenance.preview'));
        $preview->assertOk();
        $preview->assertViewIs(MaintenanceSettings::HOLDING_PAGE_VIEW);

        // And the same values through it, so the page an operator checks is the
        // page a visitor is being served.
        $this->assertSame($live->getContent(), $preview->getContent());
    }

    public function test_the_preview_needs_the_maintenance_view_permission(): void
    {
        $this->actingAs($this->userWith(['settings.general.view']))
            ->get(route('admin.settings.maintenance.preview'))
            ->assertForbidden();

        $this->actingAs($this->userWith(['settings.general.view', 'settings.maintenance.view']))
            ->get(route('admin.settings.maintenance.preview'))
            ->assertOk();
    }

    public function test_the_tab_links_to_the_preview_in_a_new_tab(): void
    {
        $response = $this->maintenanceTab();

        $response->assertOk();
        $response->assertSee(route('admin.settings.maintenance.preview'), false);
        $response->assertSee('target="_blank"', false);
    }

    /* =====================================================================
     | 2. Letting staff through by IP
     * ================================================================== */

    public function test_an_exempt_address_sees_the_live_site_while_everybody_else_does_not(): void
    {
        $this->maintenanceOn();
        $this->maintenance(['exempt_ips' => '203.0.113.10']);

        $this->fromIp('203.0.113.10')->get('/')->assertOk()->assertDontSee(self::HEADING);

        $this->fromIp('198.51.100.7')->get('/')
            ->assertStatus(503)
            ->assertSee(self::HEADING);
    }

    public function test_a_cidr_range_covers_the_addresses_inside_it_only(): void
    {
        $this->maintenanceOn();
        $this->maintenance(['exempt_ips' => "198.51.100.0/24\n203.0.113.10"]);

        $this->fromIp('198.51.100.25')->get('/')->assertOk();
        $this->fromIp('198.51.101.25')->get('/')->assertStatus(503);
    }

    public function test_an_empty_exempt_list_holds_everybody(): void
    {
        $this->maintenanceOn();

        $this->assertSame([], MaintenanceSettings::exemptIps());

        $this->fromIp('203.0.113.10')->get('/')->assertStatus(503);
        $this->fromIp('198.51.100.7')->get('/')->assertStatus(503);
    }

    public function test_the_admin_area_is_reachable_whatever_the_exempt_list_says(): void
    {
        $this->maintenanceOn();

        // A list that does not include the operator's own address, which is the
        // mistake that must never be able to lock anybody out.
        $this->maintenance(['exempt_ips' => '192.0.2.1']);

        $this->fromIp('203.0.113.99')->get(route('admin.login'))->assertOk();

        $this->fromIp('203.0.113.99')
            ->actingAs($this->superAdmin())
            ->get(route('admin.settings.general', ['tab' => 'maintenance']))
            ->assertOk();
    }

    public function test_the_tab_shows_the_operators_own_address_and_separates_the_two_lists(): void
    {
        $response = $this->fromIp('203.0.113.77')->maintenanceTab();

        $response->assertOk();
        $response->assertSee('203.0.113.77');

        // The Security tab's allowlist restricts who may SIGN IN; this one exempts
        // who sees the live site. An operator must not be able to read one as the
        // other.
        $response->assertSee('This is not the IP allowlist on the Security tab.');
    }

    public function test_an_unreadable_exempt_entry_is_refused_by_the_form(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.maintenance.update'), [
                'heading' => self::HEADING,
                'message' => self::MESSAGE,
                'exempt_ips' => "203.0.113.10\nnot-an-address",
            ])
            ->assertSessionHasErrors('exempt_ips');
    }

    /* =====================================================================
     | 3. The expected return time
     * ================================================================== */

    public function test_an_expected_return_time_is_shown_on_the_office_clock_and_not_shifted(): void
    {
        $this->pinClock();
        $this->maintenanceOn();

        // Nine in the morning on the office clock, the morning after the pinned
        // instant.
        $this->maintenance(['expected_return_at' => '2026-10-10 09:00']);

        $response = $this->get('/');

        $response->assertStatus(503);
        $response->assertSee('Expected back by');
        $response->assertSee('10 Oct 2026, 9:00 am');

        /*
         | The two ways of getting it wrong, both excluded. Shifted forward as if
         | the typed time were UTC gives 5:00 pm; shifted backwards as if it had to
         | be stored in UTC gives 1:00 am. Neither may appear.
         */
        $html = $response->getContent();
        $this->assertStringNotContainsString('5:00 pm', $html);
        $this->assertStringNotContainsString('1:00 am', $html);
    }

    public function test_a_return_time_that_has_passed_is_hidden_rather_than_promised(): void
    {
        $this->pinClock();
        $this->maintenanceOn();

        // An hour and a half before the pinned instant, on the office clock.
        $this->maintenance(['expected_return_at' => '2026-10-09 23:00']);

        $response = $this->get('/');

        $response->assertStatus(503);
        $response->assertSee(self::HEADING);
        $response->assertDontSee('Expected back by');
        $response->assertDontSee('09 Oct 2026, 11:00 pm');
    }

    public function test_the_tab_says_a_return_time_has_passed_so_it_can_be_corrected(): void
    {
        $this->pinClock();
        $this->maintenance(['enabled' => '1', 'expected_return_at' => '2026-10-09 23:00']);

        $response = $this->maintenanceTab();

        $response->assertOk();
        $response->assertSee('has passed, so the holding page');
        $response->assertSee('09 Oct 2026, 11:00 pm');
    }

    public function test_no_return_time_means_the_page_says_nothing_about_timing(): void
    {
        $this->pinClock();
        $this->maintenanceOn();

        $this->get('/')
            ->assertStatus(503)
            ->assertDontSee('Expected back by');
    }

    public function test_a_return_time_is_stored_and_read_back_exactly_as_typed(): void
    {
        $this->pinClock();

        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.maintenance.update'), [
                'heading' => self::HEADING,
                'message' => self::MESSAGE,
                'expected_return_at' => '2026-10-10T09:00',
            ])
            ->assertSessionHasNoErrors();

        MaintenanceSettings::flush();

        // Stored as typed, with no eight hour shift applied on the way in.
        $this->assertSame('2026-10-10 09:00', Setting::read('maintenance.expected_return_at'));
        $this->assertSame('2026-10-10T09:00', MaintenanceSettings::formValues()['expected_return_at']);
    }

    /* =====================================================================
     | 4. The scheduled window
     * ================================================================== */

    public function test_the_window_holds_the_site_with_the_manual_switch_off(): void
    {
        $this->pinClock();

        $this->maintenance([
            'enabled' => '0',
            'heading' => self::HEADING,
            'message' => self::MESSAGE,
            'window_start' => '2026-10-10 00:00',
            'window_end' => '2026-10-10 02:00',
        ]);

        // 00:30 on the office clock is inside it, even though in UTC it is still
        // the 9th and the window looks like tomorrow.
        $this->get('/')->assertStatus(503)->assertSee(self::HEADING);

        // And the switch itself was never touched.
        $this->assertSame('0', Setting::read('maintenance.enabled'));
    }

    public function test_the_site_is_live_before_the_window_opens(): void
    {
        $this->pinClock();

        $this->maintenance([
            'enabled' => '0',
            'window_start' => '2026-10-10 03:00',
            'window_end' => '2026-10-10 05:00',
        ]);

        $this->get('/')->assertOk();
    }

    public function test_the_site_is_live_after_the_window_has_closed(): void
    {
        $this->pinClock();

        $this->maintenance([
            'enabled' => '0',
            'window_start' => '2026-10-09 20:00',
            'window_end' => '2026-10-09 22:00',
        ]);

        $this->get('/')->assertOk();
    }

    public function test_nothing_has_to_run_for_the_window_to_end(): void
    {
        /*
         | THE TEST THAT PROVES THE DESIGN.
         |
         | A queue is faked so that anything dispatched would be recorded. No
         | command is called, the scheduler is never run, and no worker exists. The
         | site is held inside the window and live again afterwards purely because
         | the time passed — which is what makes this safe to leave switched on
         | during a deploy: if cron dies, the queue stops and the box reboots, the
         | window still ends.
         */
        Queue::fake();

        $this->pinClock();

        $this->maintenance([
            'enabled' => '0',
            'heading' => self::HEADING,
            'message' => self::MESSAGE,
            'window_start' => '2026-10-10 00:00',
            'window_end' => '2026-10-10 02:00',
        ]);

        $this->get('/')->assertStatus(503);

        // Time passes. That is the only thing that happens.
        Carbon::setTestNow(Carbon::parse('2026-10-09 18:01:00', 'UTC')); // 02:01 on the office clock

        $this->get('/')->assertOk()->assertDontSee(self::HEADING);

        // Nothing was queued, and no stored value was changed to bring it back:
        // the window ended because it was read, not because it was executed.
        Queue::assertNothingPushed();
        $this->assertSame('0', Setting::read('maintenance.enabled'));
        $this->assertSame('2026-10-10 00:00', Setting::read('maintenance.window_start'));
        $this->assertSame('2026-10-10 02:00', Setting::read('maintenance.window_end'));
    }

    public function test_a_window_with_only_a_start_stays_on_until_the_switch_is_cleared(): void
    {
        $this->pinClock();

        $this->maintenance([
            'enabled' => '0',
            'heading' => self::HEADING,
            'message' => self::MESSAGE,
            'window_start' => '2026-10-10 00:00',
        ]);

        $this->get('/')->assertStatus(503);

        // A week later, with no end time, it is still holding — which is what the
        // tab warns about, and why an end time is the normal way to use this.
        Carbon::setTestNow(Carbon::parse('2026-10-16 16:30:00', 'UTC'));

        $this->get('/')->assertStatus(503);
    }

    public function test_the_manual_switch_holds_the_site_outside_any_window(): void
    {
        $this->pinClock();

        $this->maintenance([
            'enabled' => '1',
            'heading' => self::HEADING,
            'message' => self::MESSAGE,
            'window_start' => '2026-10-11 03:00',
            'window_end' => '2026-10-11 05:00',
        ]);

        $this->get('/')->assertStatus(503)->assertSee(self::HEADING);
    }

    public function test_an_end_before_its_start_is_refused(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.maintenance.update'), [
                'heading' => self::HEADING,
                'message' => self::MESSAGE,
                'window_start' => '2026-10-10T02:00',
                'window_end' => '2026-10-10T00:00',
            ])
            ->assertSessionHasErrors('window_end');

        $this->assertNull(Setting::read('maintenance.window_start'));
    }

    public function test_an_end_with_no_start_is_refused(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.maintenance.update'), [
                'heading' => self::HEADING,
                'message' => self::MESSAGE,
                'window_start' => '',
                'window_end' => '2026-10-10T02:00',
            ])
            ->assertSessionHasErrors('window_end');

        $this->assertNull(Setting::read('maintenance.window_end'));
    }

    public function test_a_window_is_saved_as_typed(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.maintenance.update'), [
                'heading' => self::HEADING,
                'message' => self::MESSAGE,
                'window_start' => '2026-10-10T00:00',
                'window_end' => '2026-10-10T02:00',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-10-10 00:00', Setting::read('maintenance.window_start'));
        $this->assertSame('2026-10-10 02:00', Setting::read('maintenance.window_end'));
    }

    /* =====================================================================
     | What the tab reports is in force
     * ================================================================== */

    public function test_the_tab_reports_the_site_is_live_with_nothing_set(): void
    {
        $response = $this->maintenanceTab();

        $response->assertOk();
        $response->assertSee('No window is set and the switch is off.');
        $this->assertSame(MaintenanceSettings::STATE_OFF, MaintenanceSettings::state());
    }

    public function test_the_tab_reports_a_manual_switch(): void
    {
        $this->maintenance(['enabled' => '1']);

        $this->maintenanceTab()
            ->assertOk()
            ->assertSee('Switched on by hand.');

        $this->assertSame(MaintenanceSettings::STATE_MANUAL, MaintenanceSettings::state());
    }

    public function test_the_tab_reports_a_window_that_is_running(): void
    {
        $this->pinClock();

        $this->maintenance([
            'enabled' => '0',
            'window_start' => '2026-10-10 00:00',
            'window_end' => '2026-10-10 02:00',
        ]);

        $response = $this->maintenanceTab();

        $response->assertOk();
        $response->assertSee('On by schedule until');
        $response->assertSee('10 Oct 2026, 2:00 am');

        $this->assertSame(MaintenanceSettings::STATE_WINDOW_RUNNING, MaintenanceSettings::state());
    }

    public function test_the_tab_reports_a_window_waiting_to_begin(): void
    {
        $this->pinClock();

        $this->maintenance([
            'enabled' => '0',
            'window_start' => '2026-10-10 03:00',
            'window_end' => '2026-10-10 05:00',
        ]);

        $response = $this->maintenanceTab();

        $response->assertOk();
        $response->assertSee('Scheduled to begin at');
        $response->assertSee('10 Oct 2026, 3:00 am');

        $this->assertSame(MaintenanceSettings::STATE_WINDOW_PENDING, MaintenanceSettings::state());
    }

    public function test_the_tab_shows_a_finished_window_as_over_rather_than_armed(): void
    {
        $this->pinClock();

        $this->maintenance([
            'enabled' => '0',
            'window_start' => '2026-10-09 20:00',
            'window_end' => '2026-10-09 22:00',
        ]);

        $response = $this->maintenanceTab();

        $response->assertOk();
        $response->assertSee('The scheduled window finished at');
        $response->assertSee('09 Oct 2026, 10:00 pm');

        $this->assertSame(MaintenanceSettings::STATE_WINDOW_FINISHED, MaintenanceSettings::state());

        // And it is holding nothing.
        $this->get('/')->assertOk();
    }

    /* =====================================================================
     | Regression guards
     * ================================================================== */

    public function test_the_holding_page_still_asks_nothing_of_the_build_with_a_return_time_on_it(): void
    {
        $this->pinClock();
        $this->maintenanceOn();
        $this->maintenance(['expected_return_at' => '2026-10-10 09:00']);

        $html = $this->get('/')->assertStatus(503)->getContent();

        $this->assertStringContainsString('Expected back by', $html);

        // The page is switched on during a deploy, when the manifest may be stale.
        $this->assertStringNotContainsString('/build/', $html);
        $this->assertStringNotContainsString('rel="stylesheet"', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('<style>', $html);
    }

    public function test_an_empty_settings_table_behaves_exactly_as_before(): void
    {
        $this->pinClock();

        $this->assertSame([], Setting::readGroup('maintenance'));

        // Off, nobody exempt, no window, nothing about timing.
        $this->assertFalse(MaintenanceSettings::isHoldingPublicSite());
        $this->assertSame([], MaintenanceSettings::exemptIps());
        $this->assertNull(MaintenanceSettings::windowStart());
        $this->assertNull(MaintenanceSettings::windowEnd());
        $this->assertNull(MaintenanceSettings::upcomingReturnAt());

        $this->get('/')->assertOk();
    }

    public function test_saving_the_original_three_fields_alone_still_works(): void
    {
        // Exactly the payload the tab posted before this change: no exempt list, no
        // times. Nothing may become required by their absence.
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.maintenance.update'), [
                'enabled' => '1',
                'heading' => 'Back shortly',
                'message' => 'We will be back within the hour.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.settings.general', ['tab' => 'maintenance']));

        MaintenanceSettings::flush();

        $this->assertSame('1', Setting::read('maintenance.enabled'));
        $this->assertSame('Back shortly', Setting::read('maintenance.heading'));
        $this->assertSame('', Setting::read('maintenance.exempt_ips'));
        $this->assertSame('', Setting::read('maintenance.window_start'));

        $this->signOut();
        $this->get('/')->assertStatus(503)->assertSee('Back shortly');
    }
}
