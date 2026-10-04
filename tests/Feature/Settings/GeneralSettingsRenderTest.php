<?php

namespace Tests\Feature\Settings;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\GeneralSettings;
use App\Support\LocalTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The General Config screen is the source of truth for the company facts.
 *
 * It used to write nine general.* rows that nothing read. The name, registration
 * number, address, email and telephone on the public site were literals in
 * HomeController, ContactController, the footer, the top header and the policy
 * layout, so an administrator could edit the address, be told it was saved, and
 * find the website unchanged with nothing to explain why.
 *
 * So the test worth having is not "the row was written" — that always worked. It is
 * that a save moves what a visitor sees, that an installation which never opened the
 * screen still renders the words it rendered before, and that reading the settings
 * on every public request did not turn into a query per partial.
 */
class GeneralSettingsRenderTest extends TestCase
{
    use RefreshDatabase;

    /** What the site shows when the settings table is empty. */
    private const DEFAULT_EMAIL = 'event@smartcreative.my';
    private const DEFAULT_PHONE = '019-866 6898';
    private const DEFAULT_REGISTRATION = '202303326459 / 003562257-U';
    private const DEFAULT_ADDRESS_LINE = 'Menara Keck Seng';
    private const DEFAULT_TAGLINE = 'Innovate, Create &amp; Manage';

    protected function setUp(): void
    {
        parent::setUp();

        /*
         | GeneralSettings memoises the whole group for the request, and these tests
         | write settings after the container has booted. Flushed here for the same
         | reason ShopPaymentTestCase flushes ShopSettings, and because the cache is
         | static: without this, the first test in the class would decide what the
         | rest of them read.
         */
        GeneralSettings::flush();
    }

    /** Kept so a test that saves and then opens the screen does not insert the role twice. */
    private ?User $admin = null;

    private function administrator(): User
    {
        if ($this->admin !== null) {
            return $this->admin;
        }

        $role = Role::create([
            'slug' => Role::SUPER_ADMIN,
            'name' => 'Super Admin',
            'is_active' => true,
        ]);

        return $this->admin = User::create([
            'name' => 'Admin',
            'username' => 'admin-' . uniqid(),
            'email' => uniqid() . '@example.test',
            'password' => 'secret-password',
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    /**
     * A complete, valid submission of the General Config form.
     *
     * Complete because the form validates site_name, contact_email, contact_phone
     * and timezone as required, so a partial payload would fail validation and the
     * test would be asserting against a redirect back rather than a save.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'site_name' => 'Acme Events Sdn Bhd',
            'tagline' => 'Plan, Run, Repeat',
            'contact_email' => 'hello@acme-events.test',
            'contact_phone' => '03-1234 5678',
            'whatsapp' => '011-222 3333',
            'registration_no' => '199901000001 / 123456-A',
            'address' => "Level 9, Wisma Acme\n12 Jalan Ampang\n50450 Kuala Lumpur",
            'timezone' => 'Asia/Kuching',
        ], $overrides);
    }

    /**
     * Save the form as an administrator, the way the screen does.
     *
     * @param  array<string, string>  $overrides
     */
    private function save(array $overrides = []): void
    {
        $response = $this->actingAs($this->administrator())
            ->put(route('admin.settings.general.update'), $this->payload($overrides));

        $response->assertRedirect(route('admin.settings.general', ['tab' => 'general']));
        $response->assertSessionHasNoErrors();
    }

    /* ---------------------------------------------------------------------
     | A save reaches the public site
     * ------------------------------------------------------------------ */

    public function test_saving_the_company_address_changes_what_the_home_page_renders(): void
    {
        $this->save();

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('Level 9, Wisma Acme');
        $response->assertSee('12 Jalan Ampang');
        $response->assertSee('50450 Kuala Lumpur');

        // The literal the footer used to carry is gone, not merely overwritten.
        $response->assertDontSee(self::DEFAULT_ADDRESS_LINE);
    }

    public function test_saving_the_contact_details_changes_the_top_header_and_the_footer(): void
    {
        $this->save();

        $response = $this->get(route('home'));

        $response->assertOk();

        // Top header: the mailto and the text beside the envelope icon.
        $response->assertSee('mailto:hello@acme-events.test', false);
        $response->assertSee('hello@acme-events.test');
        $response->assertSee('03-1234 5678');

        $response->assertDontSee(self::DEFAULT_EMAIL);
        $response->assertDontSee(self::DEFAULT_PHONE);
    }

    public function test_saving_the_registration_number_changes_the_footer(): void
    {
        $this->save();

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('Registration: 199901000001 / 123456-A');
        $response->assertDontSee(self::DEFAULT_REGISTRATION);
    }

    public function test_saving_the_tagline_changes_the_hero_heading(): void
    {
        $this->save();

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('Plan, Run, Repeat');
        $response->assertDontSee(self::DEFAULT_TAGLINE, false);
    }

    public function test_saving_the_site_name_changes_the_company_block_on_a_policy_page(): void
    {
        $this->save();

        $response = $this->get(route('legal.privacy'));

        $response->assertOk();
        $response->assertSee('Acme Events Sdn Bhd');
        $response->assertSee('Registration: 199901000001 / 123456-A');
        $response->assertSee('Level 9, Wisma Acme');
    }

    public function test_the_policy_page_telephone_link_follows_the_saved_number(): void
    {
        $this->save();

        $response = $this->get(route('legal.privacy'));

        $response->assertOk();

        // 03-1234 5678 as Malaysia dials it abroad: country code 60, trunk zero gone.
        $response->assertSee('tel:+60312345678', false);
        $response->assertDontSee('tel:+60198666898', false);
    }

    public function test_saving_the_whatsapp_number_changes_the_contact_page_link(): void
    {
        $this->save();

        $response = $this->get(route('contact'));

        $response->assertOk();
        $response->assertSee('https://wa.me/60112223333', false);
        $response->assertSee('011-222 3333');
        $response->assertDontSee('https://wa.me/60198666898', false);
    }

    public function test_the_contact_page_office_block_follows_the_saved_values(): void
    {
        $this->save();

        $response = $this->get(route('contact'));

        $response->assertOk();
        $response->assertSee('Acme Events Sdn Bhd');
        $response->assertSee('(199901000001 / 123456-A)');
        $response->assertSee('12 Jalan Ampang');
        $response->assertDontSee(self::DEFAULT_ADDRESS_LINE);
    }

    public function test_the_settings_screen_shows_the_same_values_it_published(): void
    {
        // The form and the public site now share one set of defaults, so the screen
        // cannot show one fallback while the footer renders another.
        $this->save();

        $response = $this->actingAs($this->administrator())
            ->get(route('admin.settings.general', ['tab' => 'general']));

        $response->assertOk();
        $response->assertSee('Acme Events Sdn Bhd');
        $response->assertSee('199901000001 / 123456-A');
        $response->assertSee('hello@acme-events.test');
        $response->assertSee('Level 9, Wisma Acme');
    }

    public function test_the_settings_screen_falls_back_to_the_shipped_values(): void
    {
        $response = $this->actingAs($this->administrator())
            ->get(route('admin.settings.general', ['tab' => 'general']));

        $response->assertOk();
        $response->assertSee(self::DEFAULT_EMAIL);
        $response->assertSee(self::DEFAULT_PHONE);
        $response->assertSee(self::DEFAULT_REGISTRATION);
    }

    public function test_saving_the_timezone_moves_the_clock_timestamps_are_read_on(): void
    {
        // The row the screen writes is what the display clock reads, which is what
        // the field's own help text promises and what it did not do before.
        $this->save();

        $this->assertSame('Asia/Kuching', LocalTime::zone());

        // Storage stays UTC. Only the reading moves.
        $this->assertSame('UTC', config('app.timezone'));
    }

    /* ---------------------------------------------------------------------
     | Fallbacks
     * ------------------------------------------------------------------ */

    public function test_the_home_page_renders_the_shipped_values_with_an_empty_settings_table(): void
    {
        $this->assertSame(0, Setting::query()->count());

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee(self::DEFAULT_EMAIL);
        $response->assertSee(self::DEFAULT_PHONE);
        $response->assertSee('Registration: ' . self::DEFAULT_REGISTRATION);
        $response->assertSee(self::DEFAULT_ADDRESS_LINE);
        $response->assertSee(self::DEFAULT_TAGLINE, false);

        // Never a bare label, and never the word a null would print. Checked at the
        // render points rather than across the whole document, because the header
        // script legitimately contains the word null.
        $this->assertRendersNoNulls($response->getContent());
        $response->assertDontSee('Registration: </p>', false);
    }

    /**
     * No render point printed a null or an empty value.
     *
     * The failure this guards against is specific: a view that reads a setting the
     * store has nothing for prints the word "null" or leaves the line blank, and
     * both read as a broken page rather than as a missing setting.
     */
    private function assertRendersNoNulls(string $html): void
    {
        foreach ([
            'Registration: null',
            'Registration: </p>',
            'mailto:null',
            'mailto:"',
            'tel:+null',
            'href="tel:"',
            'https://wa.me/null',
        ] as $bad) {
            $this->assertStringNotContainsString($bad, $html, 'Rendered ' . $bad);
        }
    }

    public function test_a_policy_page_renders_the_shipped_values_with_an_empty_settings_table(): void
    {
        $response = $this->get(route('legal.privacy'));

        $response->assertOk();
        $response->assertSee('Smart Digital Creative Management &amp; Resources', false);
        $response->assertSee(self::DEFAULT_ADDRESS_LINE);
        $response->assertSee('tel:+60198666898', false);
        $this->assertRendersNoNulls($response->getContent());
    }

    public function test_a_setting_saved_blank_falls_back_rather_than_rendering_nothing(): void
    {
        /*
         | A row that exists and holds an empty string, which is what the nullable
         | fields on the screen write when they are cleared. Treated as "nothing
         | saved" on purpose: a public page that states the company's address reads
         | worse with a gap than with the address it showed yesterday.
         */
        foreach (['tagline', 'whatsapp', 'registration_no', 'address'] as $key) {
            Setting::write('general.' . $key, '', 'general');
        }

        GeneralSettings::flush();

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('Registration: ' . self::DEFAULT_REGISTRATION);
        $response->assertSee(self::DEFAULT_ADDRESS_LINE);
        $response->assertSee(self::DEFAULT_TAGLINE, false);
        $this->assertRendersNoNulls($response->getContent());
    }

    public function test_an_unknown_saved_timezone_falls_back_to_the_configured_one(): void
    {
        // Settings are strings in a table other tools can write to, and Carbon
        // throws on a zone it does not know.
        Setting::write('general.timezone', 'Mars/Olympus_Mons', 'general');

        GeneralSettings::flush();

        $this->assertSame(config('app.display_timezone'), LocalTime::zone());
    }

    public function test_the_accessor_never_hands_a_view_a_null(): void
    {
        foreach (array_keys(GeneralSettings::DEFAULTS) as $key) {
            $this->assertNotNull(GeneralSettings::get($key), $key . ' resolved to null');
            $this->assertNotSame('', trim((string) GeneralSettings::get($key)), $key . ' resolved to blank');
        }
    }

    /* ---------------------------------------------------------------------
     | Cost
     * ------------------------------------------------------------------ */

    public function test_the_general_settings_are_read_once_per_request_not_once_per_partial(): void
    {
        $this->save();

        GeneralSettings::flush();

        $queries = [];

        DB::listen(function ($query) use (&$queries) {
            // Identifier quoting differs between the SQLite the suite runs on and
            // the MySQL the site runs on, so it is taken off before matching.
            $queries[] = str_replace(['`', '"'], '', $query->sql);
        });

        $this->get(route('home'))->assertOk();

        /*
         | The top header, the hero and the footer all ask for these values on this
         | one page, and the group is fetched in one statement rather than key by
         | key, so three partials cost one query between them.
         */
        $groupReads = array_filter(
            $queries,
            fn (string $sql) => str_contains($sql, 'from settings where group ='),
        );

        $this->assertCount(1, $groupReads, 'The general settings group was read ' . count($groupReads) . ' times.');
    }
}
