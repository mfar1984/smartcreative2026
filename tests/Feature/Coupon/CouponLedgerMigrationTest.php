<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\CouponCode;
use App\Services\Coupon\CouponRedeemer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * The migration that turns coupon_codes from a stock of minted codes into a ledger.
 *
 * Two things have to be true, and the second one is the one that holds money:
 *
 *   unused minted rows are DELETED. Nobody held them and nobody used them, and leaving
 *   them would make the remaining count disagree with the cap.
 *
 *   redeemed rows are PRESERVED, untouched. Each one is the record a discount on a real
 *   registration or order points at, and Tracking and Report read this table. On the
 *   live database there were none at the time, which is what made the delete safe, but
 *   the WHERE clause is what makes it safe in general — so it is asserted rather than
 *   assumed.
 *
 * The migration has already run by the time a test starts, so the pre-migration shape
 * is written back by hand and up() is run again over it. That also proves up() is safe
 * to re-run, which is what the rollback-and-re-migrate check depends on.
 */
class CouponLedgerMigrationTest extends CouponTestCase
{
    use RefreshDatabase;

    /** Run the migration under test again, over whatever is in the table now. */
    private function runUp(): void
    {
        $migration = require database_path(
            'migrations/2026_10_12_090000_make_coupon_codes_a_redemption_ledger.php',
        );

        $migration->up();
    }

    /** A pre-minted code row, as the old model wrote them. */
    private function mintRow(Coupon $coupon, string $code): int
    {
        return DB::table('coupon_codes')->insertGetId([
            'coupon_id' => $coupon->id,
            'code' => $code,
            'redeemed_at' => null,
            'discount_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_unused_minted_rows_are_dropped_and_the_name_still_redeems(): void
    {
        // The live row, as it stood: unlimited, ten minted codes, none used.
        $coupon = $this->coupon([
            'name' => 'NG68BJ',
            'quantity' => 0,
            'discount_type' => Coupon::DISCOUNT_FIXED,
            'discount_value' => 25,
        ]);

        foreach (['3H7971', 'KXU4GK', 'B5VBPD', 'JE9MEA', 'B1H04E'] as $code) {
            $this->mintRow($coupon, $code);
        }

        $this->assertSame(5, CouponCode::query()->where('coupon_id', $coupon->id)->count());

        $this->runUp();

        $this->assertSame(
            0,
            CouponCode::query()->where('coupon_id', $coupon->id)->count(),
            'Every unused minted row must be gone.',
        );

        // And the coupon still works, by its name.
        $outcome = app(CouponRedeemer::class)->claimByCode('NG68BJ', Coupon::KIND_EVENT, 100);

        $this->assertTrue($outcome->succeeded());
        $this->assertSame(25.0, $outcome->discount);
        $this->assertSame('NG68BJ', $outcome->code->code);
    }

    public function test_a_redeemed_row_is_preserved_untouched(): void
    {
        $event = $this->event(['fee' => 200]);
        $registration = $this->registration($event);

        $coupon = $this->fixedCoupon(40, ['quantity' => 5, 'name' => 'KEEPME']);

        // A real redemption, written the way the system writes them.
        app(\App\Services\Coupon\RegistrationCouponWriter::class)->apply($registration, $coupon);

        $redeemed = CouponCode::query()->where('coupon_id', $coupon->id)->redeemed()->sole();

        // Read off the table rather than the model, so the comparison is about stored
        // values and not about two Carbon instances being the same object.
        $before = (array) DB::table('coupon_codes')->where('id', $redeemed->id)->sole();

        // Unused minted rows sitting alongside it, as the old model would have left.
        $this->mintRow($coupon, 'UNUSE1');
        $this->mintRow($coupon, 'UNUSE2');

        $this->runUp();

        $this->assertSame(
            $before,
            (array) DB::table('coupon_codes')->where('id', $redeemed->id)->sole(),
            'A redeemed row must not move.',
        );

        $after = CouponCode::query()->whereKey($redeemed->id)->sole();

        $this->assertSame('40.00', $after->discount_amount);
        $this->assertSame($registration->id, $after->event_registration_id);

        // Only the unused rows went.
        $this->assertSame(1, CouponCode::query()->where('coupon_id', $coupon->id)->count());

        // The registration still points at its discount, which is the whole reason
        // the row is kept.
        $registration->refresh();

        $this->assertSame($after->id, $registration->coupon_code_id);
        $this->assertSame('40.00', $registration->discount_amount);
        $this->assertSame('160.00', $registration->amount);
    }

    public function test_the_same_code_may_appear_on_many_ledger_rows(): void
    {
        // The unique index on `code` had to go: every use of one coupon stores the
        // same string. Without this the second redemption would fail outright.
        $coupon = $this->fixedCoupon(5, ['quantity' => 0, 'name' => 'SHARED']);
        $redeemer = app(CouponRedeemer::class);

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($redeemer->claimByCode('SHARED', Coupon::KIND_EVENT, 100)->succeeded());
        }

        $this->assertSame(
            ['SHARED', 'SHARED', 'SHARED'],
            CouponCode::query()->where('coupon_id', $coupon->id)->pluck('code')->all(),
        );
    }

    public function test_running_it_again_over_a_ledger_changes_nothing(): void
    {
        $coupon = $this->fixedCoupon(10, ['quantity' => 3, 'name' => 'IDEMPO']);

        app(CouponRedeemer::class)->claimByCode('IDEMPO', Coupon::KIND_EVENT, 100);

        $before = CouponCode::query()->pluck('id')->all();

        $this->runUp();
        $this->runUp();

        $this->assertSame($before, CouponCode::query()->pluck('id')->all());
        $this->assertSame(2, $coupon->fresh()->remaining());
    }
}
