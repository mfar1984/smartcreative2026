<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\CouponCode;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * WHO USED THE COUPON, recorded at the moment of use and shown to the sponsor.
 *
 * THE BUG THE OWNER FOUND. A sponsor's area listed two uses of a shared 50% batch and
 * both read "USED BY —", including the one whose registration plainly still existed
 * with one named participant on it.
 *
 * It was NOT the write path. Asserted below through the real public registration form,
 * for one person and for a group of ten, because that is the only way the assertion
 * means anything: a ledger row built by hand in a test would have carried a name while
 * the defect was live, and passed.
 *
 * The blank rows were written before the column existed. 2026_10_12_090000 made
 * coupon_codes a ledger and real redemptions started landing in it;
 * 2026_10_14_090000 added participant_name two deployments later and deliberately left
 * the existing rows alone. Everything redeemed in between is blank for ever unless it
 * is repaired, which 2026_10_17_090100 does and the migration tests at the bottom pin
 * down.
 */
class CouponRedeemerNameTest extends CouponTestCase
{
    use RefreshDatabase;

    /** A participant's own details, which must never reach a sponsor's screen. */
    private const REDEEMER_IC = '880101121234';

    private const REDEEMER_PHONE = '0191234567';

    /** A grouping event charging per head, so a use is a participant. */
    private function perHeadEvent(): Event
    {
        return $this->event([
            'registration_mode' => Event::MODE_GROUPING,
            'charges_addons_per_participant' => true,
            'fee' => 150,
            'min_players' => 1,
            'max_players' => 20,
        ]);
    }

    /* ---------------------------------------------------------------------
     | Through the real form, which is the only assertion that counts
     * ------------------------------------------------------------------ */

    public function test_one_person_registering_with_a_shared_code_is_named_on_the_ledger(): void
    {
        // The owner's shape exactly: a shared 50% batch on a RM30 entry, one person.
        $event = $this->event(['fee' => 30]);
        $coupon = $this->percentageCoupon(50, ['quantity' => 10, 'name' => 'NG68BJ']);
        $event->coupons()->attach($coupon);

        $this->post(route('registration.store', ['event' => $event->slug]), [
            'voucher_code' => 'NG68BJ',
            'participants' => [$this->participantFields(['full_name' => 'TERENCE TAN'])],
        ])->assertSessionHasNoErrors();

        $registration = EventRegistration::query()->sole();
        $row = CouponCode::query()->sole();

        $this->assertSame('TERENCE TAN', $row->participant_name);
        $this->assertSame($registration->participants()->value('id'), $row->event_participant_id);

        // RM15 off a RM30 charge, which leaves RM15 to pay. Both figures matter: the
        // owner's screens showed exactly this pair and they are consistent.
        $this->assertSame('15.00', $row->discount_amount);
        $this->assertSame('15.00', $registration->amount);
        $this->assertSame(9, $coupon->fresh()->remaining());
    }

    public function test_a_group_of_ten_registering_with_one_shared_code_names_all_ten(): void
    {
        $event = $this->perHeadEvent();
        $coupon = $this->percentageCoupon(50, ['quantity' => 100]);
        $event->coupons()->attach($coupon);

        $people = [];
        $expected = [];

        for ($i = 1; $i <= 10; $i++) {
            $name = 'Person '.$i;
            $expected[] = $name;
            $people[] = $this->participantFields(['full_name' => $name]);
        }

        $this->post(route('registration.store', ['event' => $event->slug]), [
            'voucher_code' => $coupon->name,
            'team_name' => 'Kumpulan Sibu',
            'participants' => $people,
        ])->assertSessionHasNoErrors();

        $rows = CouponCode::query()->orderBy('id')->get();

        $this->assertCount(10, $rows);

        // Ten rows, ten different people, every one of them named. Not nine, and not
        // "the first one and nine blanks".
        sort($expected);
        $this->assertSame($expected, $rows->pluck('participant_name')->sort()->values()->all());
        $this->assertCount(10, $rows->pluck('event_participant_id')->filter()->unique());
        $this->assertSame(10, $coupon->fresh()->redeemedCount());
    }

    public function test_a_code_applied_on_the_payment_page_also_names_the_registrant(): void
    {
        // The other way in. A visitor who registered first and typed the code at the
        // payment screen has to end up with the same record.
        $event = $this->event(['fee' => 40]);
        $coupon = $this->percentageCoupon(50, ['quantity' => 5]);
        $event->coupons()->attach($coupon);

        $this->post(route('registration.store', ['event' => $event->slug]), [
            'participants' => [$this->participantFields(['full_name' => 'Nurul Huda'])],
        ])->assertSessionHasNoErrors();

        $registration = EventRegistration::query()->sole();

        // Signed, the way the payment page itself links to it.
        $this->post(
            URL::temporarySignedRoute(
                'registration.payment.coupon',
                now()->addDay(),
                ['reference' => $registration->reference],
            ),
            ['voucher_code' => $coupon->name],
        )->assertSessionHasNoErrors();

        $this->assertSame('Nurul Huda', CouponCode::query()->sole()->participant_name);
        $this->assertSame('20.00', $registration->fresh()->discount_amount);
    }

    /* ---------------------------------------------------------------------
     | What the sponsor sees: a name, and nothing else about the person
     * ------------------------------------------------------------------ */

    public function test_the_sponsor_sees_the_name_and_no_ic_and_no_phone(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $event = $this->event(['fee' => 30]);

        // Shared, tagged to the sponsorship at the batch level, which is the shape
        // that has no block for a tag to sit on.
        $coupon = $this->percentageCoupon(50, ['quantity' => 10, 'name' => 'MAJUSHARE']);
        $coupon->events()->attach($event);

        $maju = User::create([
            'name' => 'Syarikat Maju Sdn Bhd',
            'username' => 'sponsor-'.uniqid(),
            'email' => uniqid().'@example.test',
            'password' => 'Sponsor-Pass-123!',
            'role_id' => Role::where('slug', Role::SPONSOR)->firstOrFail()->id,
            'is_active' => true,
            'is_sponsor' => true,
            'sponsor_committed_amount' => 500,
        ]);

        $coupon->forceFill(['sponsor_user_id' => $maju->id])->save();

        $this->post(route('registration.store', ['event' => $event->slug]), [
            'voucher_code' => 'MAJUSHARE',
            'participants' => [$this->participantFields([
                'full_name' => 'Aminah Binti Yusof',
                'ic_number' => self::REDEEMER_IC,
                'phone' => self::REDEEMER_PHONE,
            ])],
        ])->assertSessionHasNoErrors();

        // The Event tab, which is where who used a code is listed.
        $response = $this->actingAs($maju)->get(route('admin.sponsorship.index', ['tab' => 'event']));

        $response->assertOk();

        // The name is the point of the screen, and it is read off the ledger row the
        // public form wrote.
        $response->assertSee('Aminah Binti Yusof');

        // The absence is asserted, not assumed. A redeemer is a member of the public.
        $response->assertDontSee(self::REDEEMER_IC);
        $response->assertDontSee(self::REDEEMER_PHONE);
    }

    /* ---------------------------------------------------------------------
     | The repair: 2026_10_17_090100
     * ------------------------------------------------------------------ */

    /** Run the backfill again over whatever is in the table now. */
    private function runBackfill(): void
    {
        (require database_path('migrations/2026_10_17_090100_backfill_coupon_redeemer_names.php'))->up();
    }

    /** A ledger row as it was written before participant_name existed. */
    private function legacyRow(Coupon $coupon, EventRegistration $registration, float $discount): int
    {
        return DB::table('coupon_codes')->insertGetId([
            'coupon_id' => $coupon->id,
            'code' => $coupon->name,
            'redeemed_at' => now(),
            'discount_amount' => $discount,
            'event_registration_id' => $registration->id,
            'event_participant_id' => null,
            'participant_name' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_backfill_names_a_legacy_row_whose_registration_still_stands(): void
    {
        $registration = $this->registration($this->event(['fee' => 30]), people: 1);
        $registration->participants()->first()->update(['full_name' => 'TERENCE TAN']);

        $coupon = $this->percentageCoupon(50, ['quantity' => 10, 'name' => 'NG68BJ']);

        $id = $this->legacyRow($coupon, $registration, 15.00);

        $this->runBackfill();

        $row = CouponCode::query()->whereKey($id)->sole();

        $this->assertSame('TERENCE TAN', $row->participant_name);
        $this->assertSame($registration->participants()->value('id'), $row->event_participant_id);

        // And the money on the row is untouched. The repair is about the name.
        $this->assertSame('15.00', $row->discount_amount);
    }

    public function test_the_backfill_names_all_ten_of_a_legacy_group_in_the_order_they_were_written(): void
    {
        $registration = $this->registration($this->perHeadEvent(), ['addons_total' => 150], people: 10);

        $coupon = $this->percentageCoupon(50, ['quantity' => 100]);

        $ids = [];

        for ($i = 0; $i < 10; $i++) {
            $ids[] = $this->legacyRow($coupon, $registration, 7.50);
        }

        $this->runBackfill();

        $names = CouponCode::query()->whereIn('id', $ids)->orderBy('id')->pluck('participant_name')->all();

        // Position for position against the participants ordered by id, which is the
        // order writeLedger() wrote them in.
        $this->assertSame(
            $registration->participants()->orderBy('id')->pluck('full_name')->all(),
            $names,
        );
    }

    public function test_the_backfill_leaves_a_row_alone_when_the_counts_no_longer_match(): void
    {
        // One use covering a group of three: not attributable to any one of them, so
        // the redeemer would not have named anybody either.
        $registration = $this->registration($this->event(['fee' => 150]), people: 3);

        $coupon = $this->percentageCoupon(50, ['quantity' => 10]);

        $id = $this->legacyRow($coupon, $registration, 75.00);

        $this->runBackfill();

        $row = CouponCode::query()->whereKey($id)->sole();

        $this->assertNull($row->participant_name, 'Naming one of three would be a guess with a real name on it.');
        $this->assertNull($row->event_participant_id);
    }

    public function test_the_backfill_does_not_disturb_a_row_that_is_already_named(): void
    {
        $event = $this->event(['fee' => 30]);
        $coupon = $this->percentageCoupon(50, ['quantity' => 10]);
        $event->coupons()->attach($coupon);

        $this->post(route('registration.store', ['event' => $event->slug]), [
            'voucher_code' => $coupon->name,
            'participants' => [$this->participantFields(['full_name' => 'Already Named'])],
        ])->assertSessionHasNoErrors();

        $before = (array) DB::table('coupon_codes')->sole();

        $this->runBackfill();
        $this->runBackfill();

        $this->assertSame($before, (array) DB::table('coupon_codes')->sole());
    }
}
