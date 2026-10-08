<?php

namespace Tests\Feature\Settings;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\GeneralSettings;
use App\Support\LocalTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Date Format and Time Format fields on General Config.
 *
 * Part A: the two settings and the LocalTime engine that reads them. The tests
 * worth having are that a chosen format persists and is read back, that an
 * invalid key is rejected, that LocalTime with no explicit format honours the
 * choice while an explicit format is untouched, that date()/time() return the
 * chosen date-only / time-only format, that a wall-clock value is formatted but
 * NOT timezone-shifted while an instant IS, that an empty settings table renders
 * exactly as before, and that each of the six time and nine date mappings
 * produces its sample string.
 */
class DateTimeFormatSettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The group is memoised per request; these tests write settings after boot.
        GeneralSettings::flush();
    }

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
            'timezone' => 'Asia/Kuala_Lumpur',
        ], $overrides);
    }

    /* ---------------------------------------------------------------------
     | Persistence and validation
     * ------------------------------------------------------------------ */

    public function test_saving_a_time_and_date_format_persists_and_is_read_back(): void
    {
        $response = $this->actingAs($this->administrator())
            ->put(route('admin.settings.general.update'), $this->payload([
                'date_format' => '13 September 2026',
                'time_format' => '1:00 PM',
            ]));

        $response->assertRedirect(route('admin.settings.general', ['tab' => 'general']));
        $response->assertSessionHasNoErrors();

        $this->assertSame('13 September 2026', Setting::read('general.date_format'));
        $this->assertSame('1:00 PM', Setting::read('general.time_format'));

        GeneralSettings::flush();
        $this->assertSame('j F Y', GeneralSettings::dateFormat());
        $this->assertSame('g:i A', GeneralSettings::timeFormat());
    }

    public function test_an_invalid_format_key_is_rejected(): void
    {
        $response = $this->actingAs($this->administrator())
            ->put(route('admin.settings.general.update'), $this->payload([
                'date_format' => 'Y-m-d H:i:s',
                'time_format' => '<script>',
            ]));

        $response->assertSessionHasErrors(['date_format', 'time_format']);
        $this->assertNull(Setting::read('general.date_format'));
        $this->assertNull(Setting::read('general.time_format'));
    }

    public function test_blank_format_is_accepted_and_means_use_default(): void
    {
        $response = $this->actingAs($this->administrator())
            ->put(route('admin.settings.general.update'), $this->payload([
                'date_format' => '',
                'time_format' => '',
            ]));

        $response->assertSessionHasNoErrors();

        GeneralSettings::flush();
        $this->assertSame(GeneralSettings::DEFAULT_DATE_FORMAT, GeneralSettings::dateFormat());
        $this->assertSame(GeneralSettings::DEFAULT_TIME_FORMAT, GeneralSettings::timeFormat());
    }

    /* ---------------------------------------------------------------------
     | LocalTime honours the choice
     * ------------------------------------------------------------------ */

    public function test_format_with_no_explicit_argument_uses_the_chosen_date_and_time(): void
    {
        $this->store('13 September 2026', '1:00 PM');

        // 2026-09-13 05:00:00 UTC = 13:00 in Asia/Kuala_Lumpur (+8).
        $instant = Carbon::parse('2026-09-13 05:00:00', 'UTC');

        $this->assertSame('13 September 2026, 1:00 PM', LocalTime::format($instant));
    }

    public function test_date_and_time_helpers_return_the_chosen_date_only_and_time_only(): void
    {
        $this->store('13-Sep-2026', '13:00');

        $instant = Carbon::parse('2026-09-13 05:00:00', 'UTC');

        $this->assertSame('13-Sep-2026', LocalTime::date($instant));
        $this->assertSame('13:00', LocalTime::time($instant));
    }

    public function test_a_caller_passing_an_explicit_format_still_gets_exactly_that(): void
    {
        $this->store('13 September 2026', '1:00 PM');

        $instant = Carbon::parse('2026-09-13 05:00:00', 'UTC');

        // Machine format must be untouched by the admin choice.
        $this->assertSame('2026-09-13 13:00', LocalTime::format($instant, 'Y-m-d H:i'));
    }

    public function test_wall_clock_is_formatted_but_not_shifted_while_an_instant_is_shifted(): void
    {
        $this->store('13 September 2026', '13:00');

        // Same stored value, read two ways.
        $value = Carbon::parse('2026-09-13 09:00:00', 'UTC');

        // Instant: shifted +8 to 17:00 local, then formatted.
        $this->assertSame('13 September 2026, 17:00', LocalTime::format($value));

        // Wall-clock: NOT shifted, kept at 09:00, but still formatted.
        $this->assertSame('13 September 2026, 09:00', LocalTime::formatWallClock($value));
        $this->assertSame('13 September 2026', LocalTime::dateWallClock($value));
        $this->assertSame('09:00', LocalTime::timeWallClock($value));
    }

    public function test_with_no_setting_saved_localtime_falls_back_to_the_current_default(): void
    {
        GeneralSettings::flush();

        // 2026-10-13 05:00:00 UTC = 13:00 Asia/Kuala_Lumpur.
        $instant = Carbon::parse('2026-10-13 05:00:00', 'UTC');

        // Historical shape: 'd M Y, g:i a'.
        $this->assertSame('13 Oct 2026, 1:00 pm', LocalTime::format($instant));
        $this->assertSame('13 Oct 2026', LocalTime::date($instant));
        $this->assertSame('1:00 pm', LocalTime::time($instant));
    }

    public function test_a_stray_stored_value_outside_the_list_falls_back_to_default(): void
    {
        // A value no dropdown offers, as if edited in by another tool.
        Setting::write('general.date_format', 'Y-m-d', 'general');
        Setting::write('general.time_format', 'H:i:s A junk', 'general');
        GeneralSettings::flush();

        $this->assertSame(GeneralSettings::DEFAULT_DATE_FORMAT, GeneralSettings::dateFormat());
        $this->assertSame(GeneralSettings::DEFAULT_TIME_FORMAT, GeneralSettings::timeFormat());
    }

    /* ---------------------------------------------------------------------
     | Every mapping renders its sample
     * ------------------------------------------------------------------ */

    /**
     * 2026-09-13 05:00:00 UTC = 2026-09-13 13:00:00 Asia/Kuala_Lumpur.
     *
     * The expected string per key is derived straight from the owner's samples.
     */
    #[DataProvider('timeFormatSamples')]
    public function test_each_time_mapping_renders_the_expected_sample(string $key, string $expected): void
    {
        $this->store('13 September 2026', $key);

        $instant = Carbon::parse('2026-09-13 05:00:00', 'UTC');

        $this->assertSame($expected, LocalTime::time($instant));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function timeFormatSamples(): array
    {
        return [
            '13:00 PM' => ['13:00 PM', '13:00 PM'],
            '13:00:00 PM' => ['13:00:00 PM', '13:00:00 PM'],
            '1:00:00 PM' => ['1:00:00 PM', '1:00:00 PM'],
            '1:00 PM' => ['1:00 PM', '1:00 PM'],
            '13:00' => ['13:00', '13:00'],
            '13:00:00' => ['13:00:00', '13:00:00'],
        ];
    }

    #[DataProvider('dateFormatSamples')]
    public function test_each_date_mapping_renders_the_expected_sample(string $key, string $expected): void
    {
        $this->store($key, '13:00');

        $instant = Carbon::parse('2026-09-13 05:00:00', 'UTC');

        $this->assertSame($expected, LocalTime::date($instant));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function dateFormatSamples(): array
    {
        return [
            '13 September 2026' => ['13 September 2026', '13 September 2026'],
            '13 Sep 2026' => ['13 Sep 2026', '13 Sep 2026'],
            '13-Sep-2026' => ['13-Sep-2026', '13-Sep-2026'],
            '13-Sep-26' => ['13-Sep-26', '13-Sep-26'],
            '13/09/2026' => ['13/09/2026', '13/09/2026'],
            '13-09-2026' => ['13-09-2026', '13-09-2026'],
            '13-09-26' => ['13-09-26', '13-09-26'],
            '13.09.2026' => ['13.09.2026', '13.09.2026'],
            '13.09.26' => ['13.09.26', '13.09.26'],
        ];
    }

    /* ---------------------------------------------------------------------
     | Helper
     * ------------------------------------------------------------------ */

    private function store(string $dateKey, string $timeKey): void
    {
        Setting::write('general.date_format', $dateKey, 'general');
        Setting::write('general.time_format', $timeKey, 'general');
        GeneralSettings::flush();
    }
}
