<?php

namespace Tests\Feature\Settings;

use App\Models\Coupon;
use App\Models\CouponCode;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationPayment;
use App\Models\Setting;
use App\Models\WifiCredential;
use App\Services\Coupon\CouponOutcome;
use App\Services\Coupon\CouponRedeemer;
use App\Support\DashboardMetrics;
use App\Support\GeneralSettings;
use App\Support\ParticipantOptions;
use App\Support\PaymentFigures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Every day boundary on the money screens is the office clock's, not UTC's.
 *
 * Two kinds of column and opposite treatment, which is the whole of this file.
 *
 * An INSTANT — received_at, created_at — is stored in UTC and shown on the office
 * clock, so a date filter must CONVERT the picker's local day into the UTC instants
 * it spans. Compared directly, a payment taken at 04:00 in Kuala Lumpur sits on the
 * previous UTC day and falls out of its own day, which is money missing from the
 * figure an operator is reading.
 *
 * A WALL CLOCK date — an event's ends_at, a coupon's expires_at, a Wi-Fi expires_on —
 * is a date somebody typed and must not be shifted at all. There the bug runs the
 * other way: the comparison used UTC's today, and between local midnight and 08:00
 * UTC is still on yesterday, so an expired coupon stayed claimable all night.
 *
 * THE CLOCK IS PINNED, AND THE PIN IS THE TEST
 *
 * 16:30 UTC is already 00:30 the next day in Kuala Lumpur, so the stored UTC date and
 * the date an operator reads disagree on every run. Read off a bare now(), these
 * assertions would only exercise the boundary between 16:00 and midnight UTC — they
 * would pass all day and break one evening. The midday guard at the bottom pins
 * 04:00 UTC instead, where UTC and the office clock share a date, and holds every
 * figure to what it has always reported: that is the assertion that proves an edge
 * was corrected rather than everything being moved eight hours.
 */
class LocalDayFiguresTest extends TestCase
{
    use RefreshDatabase;

    /** 00:30 on the office clock, the morning of the 10th. UTC is still on the 9th. */
    private const NIGHT = '2026-10-09 16:30:00';

    /** The day an operator is on at that instant. */
    private const LOCAL_TODAY = '2026-10-10';

    /** Midday on the office clock, where UTC agrees with it. */
    private const MIDDAY = '2026-10-09 04:00:00';

    /** The day both clocks are on at that instant. */
    private const SHARED_DAY = '2026-10-09';

    protected function setUp(): void
    {
        parent::setUp();

        // The saved zone is what LocalTime reads, and GeneralSettings memoises per
        // process, so the row is written and the cache dropped.
        Setting::write('general.timezone', 'Asia/Kuala_Lumpur', 'general');
        GeneralSettings::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | Fixtures
     * ------------------------------------------------------------------ */

    /** Half past midnight on the office clock. */
    private function pinToTheSmallHours(): void
    {
        Carbon::setTestNow(Carbon::parse(self::NIGHT, 'UTC'));
    }

    /** Midday on the office clock, where the two dates agree. */
    private function pinToMidday(): void
    {
        Carbon::setTestNow(Carbon::parse(self::MIDDAY, 'UTC'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function event(array $overrides = []): Event
    {
        return Event::create($overrides + [
            'slug' => 'event-' . uniqid(),
            'title' => 'Hari Sukan Negara',
            'category' => 'Community',
            'starts_at' => '2026-11-01',
            'ends_at' => '2026-11-01',
            'location' => 'Sibu',
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_INDIVIDUAL,
            'fee' => 100,
            'seats_total' => 0,
            'min_players' => 1,
        ]);
    }

    /**
     * One entry, placed at an exact UTC instant.
     *
     * created_at is forced rather than written through the clock, because several of
     * these sit on instants that are not "now" in any test here, and the column is
     * not fillable.
     *
     * @param  array<string, mixed>  $columns
     */
    private function registration(Event $event, string $createdAtUtc, array $columns = []): EventRegistration
    {
        $registration = EventRegistration::create([
            'event_id' => $event->id,
            'reference' => 'REG-2026-' . str_pad((string) (EventRegistration::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'mode' => Event::MODE_INDIVIDUAL,
            'status' => EventRegistration::STATUS_PENDING,
            'payment_status' => EventRegistration::PAYMENT_UNPAID,
            'registration_fee' => 100,
            'addons_total' => 0,
            'amount' => 100,
        ]);

        $registration->forceFill($columns + ['created_at' => Carbon::parse($createdAtUtc, 'UTC')])->save();

        return $registration->fresh();
    }

    /** One receipt against an entry, at an exact UTC instant. */
    private function payment(EventRegistration $registration, float $amount, string $receivedAtUtc): EventRegistrationPayment
    {
        return EventRegistrationPayment::create([
            'event_registration_id' => $registration->id,
            'amount' => $amount,
            'received_at' => Carbon::parse($receivedAtUtc, 'UTC'),
            'reference' => 'TRF-' . uniqid(),
            'note' => 'Bank transfer.',
            'source' => EventRegistrationPayment::SOURCE_MANUAL,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function coupon(array $overrides = []): Coupon
    {
        return Coupon::create($overrides + [
            'kind' => Coupon::KIND_EVENT,
            'name' => strtoupper(substr(md5(uniqid('', true)), 0, 8)),
            'quantity' => 0,
            'expires_at' => self::LOCAL_TODAY,
            'discount_type' => Coupon::DISCOUNT_FIXED,
            'discount_value' => 10,
            'design' => 'classic',
        ]);
    }

    /** A Wi-Fi login for one competitor, expiring on a typed date. */
    private function wifi(Event $event, string $expiresOn): WifiCredential
    {
        $registration = $this->registration($event, self::NIGHT);

        $participant = $registration->participants()->create([
            'role' => ParticipantOptions::ROLE_PARTICIPANT,
            'full_name' => 'Member ' . uniqid(),
            'ic_number' => '9001' . random_int(10000000, 99999999),
            'phone' => '0140000001',
            'email' => 'member-' . uniqid() . '@example.com',
            'gender' => 'male',
            'race' => 'malay',
        ]);

        return WifiCredential::create([
            'event_id' => $event->id,
            'event_registration_id' => $registration->id,
            'event_participant_id' => $participant->id,
            'username' => 'user-' . uniqid(),
            'password' => 'secret-pass',
            'expires_on' => $expiresOn,
        ]);
    }

    /** @return array<string, mixed> */
    private function dashboard(): array
    {
        // The payload is cached for two minutes, and two tests in one process would
        // otherwise read each other's figures.
        DashboardMetrics::forget();

        return app(DashboardMetrics::class)->all();
    }

    /* =====================================================================
     | The money: instants filtered by a local day
     * ================================================================== */

    public function test_a_payment_taken_in_the_small_hours_is_bucketed_on_its_local_day(): void
    {
        $this->pinToTheSmallHours();

        $entry = $this->registration($this->event(), self::NIGHT, [
            'amount' => 200,
            'amount_paid' => 200,
            'payment_status' => EventRegistration::PAYMENT_PAID,
        ]);

        // 04:00 on the office clock's 10th, which is 20:00 on the 9th in UTC.
        $this->payment($entry, 120, '2026-10-09 20:00:00');

        // 23:00 on the office clock's 9th — the SAME UTC date as the row above, and a
        // different local day. Grouping by DATE(received_at) merged the two.
        $this->payment($entry, 80, '2026-10-09 15:00:00');

        $this->assertSame([
            ['date' => '2026-10-10', 'count' => 1, 'total' => 120.0],
            ['date' => '2026-10-09', 'count' => 1, 'total' => 80.0],
        ], PaymentFigures::dailyCollected('2026-10-09', self::LOCAL_TODAY));

        // And asking for today alone finds the 04:00 receipt and nothing else.
        $this->assertSame(
            [['date' => '2026-10-10', 'count' => 1, 'total' => 120.0]],
            PaymentFigures::dailyCollected(self::LOCAL_TODAY, self::LOCAL_TODAY),
        );
    }

    public function test_the_revenue_chart_draws_the_small_hours_payment_on_todays_bar(): void
    {
        $this->pinToTheSmallHours();

        $entry = $this->registration($this->event(), self::NIGHT, [
            'amount' => 200,
            'amount_paid' => 200,
            'payment_status' => EventRegistration::PAYMENT_PAID,
        ]);

        $this->payment($entry, 120, '2026-10-09 20:00:00');
        $this->payment($entry, 80, '2026-10-09 15:00:00');

        $series = $this->dashboard()['revenue_series'];

        $today = end($series);
        $yesterday = prev($series);

        $this->assertSame('10 Oct', $today['label']);
        $this->assertSame(120.0, $today['value']);

        $this->assertSame('9 Oct', $yesterday['label']);
        $this->assertSame(80.0, $yesterday['value']);
    }

    public function test_a_registration_made_in_the_small_hours_is_inside_its_local_day(): void
    {
        $this->pinToTheSmallHours();

        $event = $this->event();

        // 04:00 local on the 10th: part paid, so it holds money and still owes some.
        $this->registration($event, '2026-10-09 20:00:00', [
            'amount' => 250,
            'amount_paid' => 100,
            'payment_status' => EventRegistration::PAYMENT_PARTIAL,
        ]);

        // 05:00 local on the 10th: settled, then partly refunded.
        $this->registration($event, '2026-10-09 21:00:00', [
            'amount' => 200,
            'amount_paid' => 200,
            'refunded_amount' => 50,
            'payment_status' => EventRegistration::PAYMENT_PAID,
        ]);

        $from = self::LOCAL_TODAY;
        $to = self::LOCAL_TODAY;

        // Every figure on the Payments screens runs through window(), so all of them
        // are asserted: a conversion applied to one and not the others is how two
        // screens end up disagreeing about the same day.
        $this->assertSame(250.0, PaymentFigures::collected($from, $to));
        $this->assertSame(150.0, PaymentFigures::outstanding($from, $to));
        $this->assertSame(300.0, PaymentFigures::grossCollected($from, $to));
        $this->assertSame(50.0, PaymentFigures::refunded($from, $to));

        $byEvent = PaymentFigures::byEvent($from, $to);

        $this->assertCount(1, $byEvent);
        $this->assertSame(2, $byEvent[0]['count']);
        $this->assertSame(300.0, $byEvent[0]['collected']);
        $this->assertSame(150.0, $byEvent[0]['outstanding']);

        $counts = PaymentFigures::countsByStatus($from, $to);

        $this->assertSame(1, $counts[EventRegistration::PAYMENT_PARTIAL]);
        $this->assertSame(1, $counts[EventRegistration::PAYMENT_PAID]);
    }

    public function test_both_edges_of_a_range_are_inside_it_and_a_minute_outside_is_not(): void
    {
        $this->pinToTheSmallHours();

        $event = $this->event();

        // 00:01 local on the 7th — the first instant of the range's first day, stored
        // on the 6th in UTC.
        $this->registration($event, '2026-10-06 16:01:00', [
            'amount' => 100,
            'amount_paid' => 100,
            'payment_status' => EventRegistration::PAYMENT_PAID,
        ]);

        // 23:59 local on the 9th — the last minute of the range's last day.
        $this->registration($event, '2026-10-09 15:59:00', [
            'amount' => 100,
            'amount_paid' => 60,
            'payment_status' => EventRegistration::PAYMENT_PARTIAL,
        ]);

        // 23:59 local on the 6th: one minute before the range opens.
        $this->registration($event, '2026-10-06 15:59:00', [
            'amount' => 100,
            'amount_paid' => 77,
            'payment_status' => EventRegistration::PAYMENT_PARTIAL,
        ]);

        // 00:01 local on the 10th: one minute after it closes.
        $this->registration($event, '2026-10-09 16:01:00', [
            'amount' => 100,
            'amount_paid' => 33,
            'payment_status' => EventRegistration::PAYMENT_PARTIAL,
        ]);

        // Exact amounts, not counts: the figures are what the office reconciles.
        $this->assertSame(160.0, PaymentFigures::collected('2026-10-07', '2026-10-09'));
        $this->assertSame(40.0, PaymentFigures::outstanding('2026-10-07', '2026-10-09'));
        $this->assertSame(160.0, PaymentFigures::grossCollected('2026-10-07', '2026-10-09'));

        $byEvent = PaymentFigures::byEvent('2026-10-07', '2026-10-09');

        $this->assertSame(2, $byEvent[0]['count']);
        $this->assertSame(160.0, $byEvent[0]['collected']);
        $this->assertSame(40.0, $byEvent[0]['outstanding']);
    }

    public function test_the_dashboard_counts_a_small_hours_registration_on_its_local_day(): void
    {
        $this->pinToTheSmallHours();

        $event = $this->event();

        // 04:00 local today, stored on the previous UTC day.
        $this->registration($event, '2026-10-09 20:00:00');

        // 04:00 local on the first day of the thirty, which is inside the window.
        $this->registration($event, '2026-09-10 20:00:00');

        // 09:00 local the day BEFORE the window opens, which is outside it. Its UTC
        // date is the same as the row above, which is how a UTC comparison let it in.
        $this->registration($event, '2026-09-10 01:00:00');

        $metrics = $this->dashboard();

        $this->assertSame(2, $metrics['registrations']['value']);

        $series = $metrics['registration_series'];
        $today = end($series);

        $this->assertSame('10 Oct', $today['label']);
        $this->assertSame(1.0, $today['value']);
    }

    /* =====================================================================
     | Wall clock dates compared against the right today
     * ================================================================== */

    public function test_an_event_that_ended_yesterday_is_not_ongoing_at_half_past_midnight(): void
    {
        $this->pinToTheSmallHours();

        $finished = $this->event(['starts_at' => '2026-10-08', 'ends_at' => '2026-10-09']);

        $this->assertSame([], Event::query()->ongoing()->pluck('id')->all());
        $this->assertSame([$finished->id], Event::query()->completed()->pluck('id')->all());
        $this->assertSame([], Event::query()->upcoming()->pluck('id')->all());

        // The badge on the card reads the same answer as the tab it is listed under.
        $this->assertSame('completed', $finished->lifecycle());
    }

    public function test_an_event_starting_today_is_ongoing_at_half_past_midnight(): void
    {
        $this->pinToTheSmallHours();

        $today = $this->event(['starts_at' => self::LOCAL_TODAY, 'ends_at' => '2026-10-11']);

        $this->assertSame([$today->id], Event::query()->ongoing()->pluck('id')->all());
        $this->assertSame([$today->id], Event::query()->upcoming()->pluck('id')->all());

        // It has started, so it is not waiting to.
        $this->assertSame([], Event::query()->notStarted()->pluck('id')->all());
        $this->assertSame('ongoing', $today->lifecycle());
    }

    public function test_a_coupon_that_expired_yesterday_is_neither_offered_nor_claimable(): void
    {
        $this->pinToTheSmallHours();

        $dead = $this->coupon(['name' => 'DEADONE', 'expires_at' => '2026-10-09']);
        $alive = $this->coupon(['name' => 'LIVEONE', 'expires_at' => self::LOCAL_TODAY]);

        $this->assertTrue($dead->isExpired());
        $this->assertFalse($dead->isRedeemable());

        $this->assertSame(['LIVEONE'], Coupon::query()->offered()->pluck('name')->all());

        // The claim path re-reads the expiry under its own lock, and that is the read
        // that decides whether money comes off. An offer list that merely hid the
        // batch would still have honoured a typed code.
        $refused = app(CouponRedeemer::class)->claim($dead, 100);

        $this->assertSame(CouponOutcome::EXPIRED, $refused->status);
        $this->assertSame(0, CouponCode::query()->count());

        // And the one that dies tonight still works, which is the other half: the
        // date was not simply moved a day.
        $claimed = app(CouponRedeemer::class)->claim($alive, 100);

        $this->assertTrue($claimed->succeeded());
        $this->assertSame(10.0, $claimed->discount);
    }

    public function test_a_wifi_login_that_expired_yesterday_is_not_live_at_half_past_midnight(): void
    {
        $this->pinToTheSmallHours();

        $event = $this->event();

        $dead = $this->wifi($event, '2026-10-09');
        $live = $this->wifi($event, self::LOCAL_TODAY);

        $ids = WifiCredential::query()->live()->pluck('id')->all();

        $this->assertSame([$live->id], $ids);
        $this->assertNotContains($dead->id, $ids);
    }

    /* =====================================================================
     | The regression guard: midday, where both clocks agree
     * ================================================================== */

    public function test_nothing_moves_when_utc_and_the_office_clock_share_a_date(): void
    {
        /*
         | 04:00 UTC is midday in Kuala Lumpur, so the stored date and the date on
         | screen are the same and every figure below is the figure this system
         | reported before any of this changed. If a conversion had been applied the
         | wrong way round, these are the assertions that would move by a day.
         */
        $this->pinToMidday();

        $event = $this->event(['starts_at' => self::SHARED_DAY, 'ends_at' => self::SHARED_DAY]);

        $entry = $this->registration($event, self::MIDDAY, [
            'amount' => 150,
            'amount_paid' => 90,
            'payment_status' => EventRegistration::PAYMENT_PARTIAL,
        ]);

        $this->payment($entry, 90, self::MIDDAY);

        // The receipt ledger, bucketed on the day both clocks call today.
        $this->assertSame(
            [['date' => self::SHARED_DAY, 'count' => 1, 'total' => 90.0]],
            PaymentFigures::dailyCollected(self::SHARED_DAY, self::SHARED_DAY),
        );

        // The window figures, unchanged.
        $this->assertSame(90.0, PaymentFigures::collected(self::SHARED_DAY, self::SHARED_DAY));
        $this->assertSame(60.0, PaymentFigures::outstanding(self::SHARED_DAY, self::SHARED_DAY));
        $this->assertSame(90.0, PaymentFigures::grossCollected(self::SHARED_DAY, self::SHARED_DAY));
        $this->assertSame(0.0, PaymentFigures::refunded(self::SHARED_DAY, self::SHARED_DAY));

        $byEvent = PaymentFigures::byEvent(self::SHARED_DAY, self::SHARED_DAY);

        $this->assertSame(1, $byEvent[0]['count']);
        $this->assertSame(90.0, $byEvent[0]['collected']);
        $this->assertSame(60.0, $byEvent[0]['outstanding']);

        // An event on today's date is running, not finished.
        $this->assertSame([$event->id], Event::query()->ongoing()->pluck('id')->all());
        $this->assertSame([], Event::query()->completed()->pluck('id')->all());
        $this->assertSame('ongoing', $event->lifecycle());

        // A coupon expiring today is still on offer and still claimable.
        $coupon = $this->coupon(['name' => 'TODAYOK', 'expires_at' => self::SHARED_DAY]);

        $this->assertFalse($coupon->isExpired());
        $this->assertSame(['TODAYOK'], Coupon::query()->offered()->pluck('name')->all());
        $this->assertTrue(app(CouponRedeemer::class)->claim($coupon, 100)->succeeded());

        // A login expiring today still works today.
        $credential = $this->wifi($event, self::SHARED_DAY);

        $this->assertContains($credential->id, WifiCredential::query()->live()->pluck('id')->all());

        // And the dashboard reads the same day as the report beside it.
        $metrics = $this->dashboard();

        $series = $metrics['revenue_series'];
        $today = end($series);

        $this->assertSame('9 Oct', $today['label']);
        $this->assertSame(90.0, $today['value']);
    }
}
