<?php

namespace Tests\Feature\Settings;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\BrandingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\ViteManifestNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite;
use Illuminate\View\ViewException;
use Tests\TestCase;

/**
 * The public holding page shown while maintenance mode is on.
 *
 * Two of these tests are the ones worth having. The first is that the page carries
 * no built asset: maintenance mode goes on during a deploy, so a page that resolved
 * its CSS through the Vite manifest could throw at the only moment it is ever
 * needed. The second is that the admin area still answers, because the whole reason
 * this middleware exists instead of `artisan down` is that nobody should be able to
 * lock themselves out by flipping the switch.
 *
 * The rest guard the things an operator can break from the form: long copy, copy
 * with punctuation in it, and an uploaded logo that the page used to ignore.
 */
class MaintenancePageTest extends TestCase
{
    use RefreshDatabase;

    private const HEADING = 'We are carrying out maintenance';

    private const MESSAGE = 'The website is back shortly. Thank you for your patience.';

    protected function setUp(): void
    {
        parent::setUp();

        // Static per-request cache. Flushed on the way in and on the way out so a
        // logo written here cannot decide what an unrelated test reads.
        BrandingSettings::flush();
    }

    protected function tearDown(): void
    {
        BrandingSettings::flush();

        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    /** Turn maintenance on with the given copy, the way the form saves it. */
    private function maintenanceOn(string $heading = self::HEADING, string $message = self::MESSAGE): void
    {
        Setting::write('maintenance.enabled', '1', 'maintenance');
        Setting::write('maintenance.heading', $heading, 'maintenance');
        Setting::write('maintenance.message', $message, 'maintenance');
    }

    private function superAdmin(): User
    {
        $role = Role::create([
            'slug' => Role::SUPER_ADMIN,
            'name' => 'Super Admin',
            'is_active' => true,
        ]);

        return User::create([
            'name' => 'Admin',
            'username' => 'admin-'.uniqid(),
            'email' => uniqid().'@example.test',
            'password' => 'secret-password',
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    /**
     * The rendered page, parsed, so copy can be read back decoded.
     *
     * Asserting on the raw body cannot tell "escaped correctly" from "mangled":
     * both contain entities. Reading the text of the h1 back through a parser can,
     * and a body that does not parse fails here rather than in a browser.
     */
    private function parse(string $html): \DOMXPath
    {
        $document = new \DOMDocument;

        $this->assertTrue(@$document->loadHTML($html), 'The holding page did not parse as HTML.');

        return new \DOMXPath($document);
    }

    private function textOf(\DOMXPath $xpath, string $expression): string
    {
        $node = $xpath->query($expression)?->item(0);

        $this->assertNotNull($node, 'Nothing matched '.$expression.' on the holding page.');

        return trim($node->textContent);
    }

    /* ---------------------------------------------------------------------
     | The switch
     * ------------------------------------------------------------------ */

    public function test_a_public_page_returns_503_with_the_operator_copy(): void
    {
        $this->maintenanceOn('Pit stop', 'We are upgrading the booking system.');

        $response = $this->get('/');

        // 503 and not 200: it is what tells a crawler to come back rather than
        // index the holding page as the site.
        $response->assertStatus(503);
        $response->assertSee('Pit stop');
        $response->assertSee('We are upgrading the booking system.');
    }

    public function test_the_controller_defaults_are_used_when_nothing_has_been_saved(): void
    {
        // Only the switch, no copy, which is the state of a site whose operator
        // ticked the box without editing the words.
        Setting::write('maintenance.enabled', '1', 'maintenance');

        $response = $this->get('/');

        $response->assertStatus(503);
        $response->assertSee('We are carrying out maintenance');
        $response->assertSee('The website is temporarily unavailable. Please check back shortly.');
    }

    public function test_public_pages_are_unaffected_while_maintenance_is_off(): void
    {
        $this->get('/')->assertOk();

        Setting::write('maintenance.enabled', '0', 'maintenance');

        $this->get('/')->assertOk()->assertDontSee(self::HEADING);
    }

    /* ---------------------------------------------------------------------
     | Nobody gets locked out
     * ------------------------------------------------------------------ */

    public function test_the_admin_area_is_still_reachable_while_maintenance_is_on(): void
    {
        $this->maintenanceOn();

        // The sign in screen, for an operator who is not signed in yet. This is the
        // one that matters: without it the switch is a lock with the key inside.
        $this->get(route('admin.login'))->assertOk();

        // And the screen the switch itself lives on.
        $this->actingAs($this->superAdmin())
            ->get(route('admin.settings.general', ['tab' => 'maintenance']))
            ->assertOk();
    }

    public function test_a_signed_in_administrator_still_sees_the_live_site(): void
    {
        $this->maintenanceOn();

        $this->actingAs($this->superAdmin())
            ->get('/')
            ->assertOk()
            ->assertDontSee(self::HEADING);
    }

    public function test_the_health_endpoint_still_answers_while_maintenance_is_on(): void
    {
        $this->maintenanceOn();

        $this->get('/up')->assertOk();
    }

    /* ---------------------------------------------------------------------
     | It cannot fail during a deploy
     * ------------------------------------------------------------------ */

    public function test_the_page_carries_no_built_asset(): void
    {
        $this->maintenanceOn();

        $html = $this->get('/')->assertStatus(503)->getContent();

        // A build URL here would mean the page reads the manifest, which is the
        // one thing that is unreliable while a deploy is in flight.
        $this->assertStringNotContainsString('/build/', $html);
        $this->assertStringNotContainsString('rel="stylesheet"', $html);
        $this->assertStringNotContainsString('<script', $html);

        // And it does style itself, so "no asset" is not "no styling".
        $this->assertStringContainsString('<style>', $html);
    }

    public function test_the_page_still_renders_with_the_vite_manifest_unavailable(): void
    {
        $this->maintenanceOn('Scheduled upgrade', 'We will be back within the hour.');

        // Points Vite at a build directory that does not exist, which is the state
        // public/build is in part way through a deploy.
        Vite::useBuildDirectory('build-missing-during-deploy');

        $response = $this->get('/');

        $response->assertStatus(503);
        $response->assertSee('Scheduled upgrade');
        $response->assertSee('We will be back within the hour.');
    }

    public function test_the_missing_manifest_is_a_real_failure_for_a_page_that_uses_vite(): void
    {
        /*
         | The control for the test above. Without this, pointing Vite at a missing
         | directory could be doing nothing at all and the previous test would pass
         | for the wrong reason. The sign in screen does use @vite, and it throws.
         */
        Vite::useBuildDirectory('build-missing-during-deploy');

        $this->withoutExceptionHandling();

        try {
            $this->get(route('admin.login'));

            $this->fail('A page using @vite rendered with no manifest, so the test above proves nothing.');
        } catch (ViewException $exception) {
            // Blade wraps whatever a view throws, so the cause is what identifies it.
            $this->assertInstanceOf(ViteManifestNotFoundException::class, $exception->getPrevious());
        }
    }

    /* ---------------------------------------------------------------------
     | Branding
     * ------------------------------------------------------------------ */

    public function test_an_uploaded_logo_is_shown_on_the_page(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/custom-logo.png', 'png-bytes');

        Setting::write('general.login_logo_path', 'branding/custom-logo.png', 'general');
        BrandingSettings::flush();

        $this->maintenanceOn();

        $response = $this->get('/');

        $response->assertStatus(503);
        $response->assertSee('/storage/branding/custom-logo.png', false);
        $response->assertDontSee('images/logo.png', false);
    }

    public function test_the_shipped_logo_is_shown_when_none_has_been_uploaded(): void
    {
        $this->maintenanceOn();

        $this->get('/')
            ->assertStatus(503)
            ->assertSee('images/logo.png', false);
    }

    /* ---------------------------------------------------------------------
     | Operator copy
     * ------------------------------------------------------------------ */

    public function test_copy_containing_an_ampersand_and_an_apostrophe_is_escaped(): void
    {
        $heading = "We're upgrading Events & Registration";
        $message = "Shop & registration are paused. We'll be back by 9pm.";

        $this->maintenanceOn($heading, $message);

        $html = $this->get('/')->assertStatus(503)->getContent();

        // Raw, unescaped copy in the body would be the bug.
        $this->assertStringNotContainsString($heading, $html);
        $this->assertStringNotContainsString($message, $html);

        // Escaped, and still the operator's exact words once decoded.
        $xpath = $this->parse($html);
        $this->assertSame($heading, $this->textOf($xpath, '//h1'));
        $this->assertSame($message, $this->textOf($xpath, '//p[contains(@class, "message")]'));
    }

    public function test_a_long_heading_and_a_long_message_survive_intact(): void
    {
        /*
         | 150 characters is the form's maxlength on the heading and 1000 on the
         | message, so this is the worst an operator can submit. Whether it looks
         | right is a question for a browser; what is checked here is that nothing
         | truncates it and the document still parses with it in place.
         */
        $heading = 'Scheduled maintenance of the registration, ticketing and tournament scoring systems is in progress across every SmartCreative-event-platform';
        $message = str_repeat(
            'We are moving the registration and scoring systems to new hardware. '
            .'Entries already submitted are safe and nothing needs to be sent again. ',
            6,
        );

        $this->assertLessThanOrEqual(150, mb_strlen($heading));
        $this->assertLessThanOrEqual(1000, mb_strlen($message));

        $this->maintenanceOn($heading, $message);

        $html = $this->get('/')->assertStatus(503)->getContent();

        $xpath = $this->parse($html);
        $this->assertSame($heading, $this->textOf($xpath, '//h1'));
        $this->assertSame(trim($message), $this->textOf($xpath, '//p[contains(@class, "message")]'));
    }

    /* ---------------------------------------------------------------------
     | Regression guard
     * ------------------------------------------------------------------ */

    public function test_the_under_development_placeholder_is_a_different_page_and_untouched(): void
    {
        /*
         | pages/maintenance.blade.php is rendered by MaintenanceController for the
         | Services section, which is simply not built yet. It is a 200 inside the
         | normal site layout and has nothing to do with maintenance mode.
         */
        $this->get(route('services'))
            ->assertOk()
            ->assertSee('Under Development')
            ->assertDontSee(self::HEADING);
    }
}
