<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\CouponHolder;
use App\Models\CouponIssuedCode;
use App\Services\Coupon\CouponOutcome;
use App\Services\Coupon\CouponRedeemer;
use App\Support\CouponHolderIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Unique mode: minted codes, issued in blocks, each block handled by somebody.
 *
 * The two things these tests are really holding:
 *
 *   a generated code never contains a character that gets misread off a screen. The
 *   owner read NG68BJ off his own monitor and typed NG6883, and a distributed code is
 *   read off paper, so this is the difference between a coupon that works and a
 *   visitor told their code does not exist.
 *
 *   two blocks issued to the same person are ONE holder, however the name was typed.
 *   Fragment that and the "whose allocation is finished" report only looks grouped.
 */
class CouponUniqueCodeTest extends CouponTestCase
{
    use RefreshDatabase;

    private function redeemer(): CouponRedeemer
    {
        return app(CouponRedeemer::class);
    }

    /* ---------------------------------------------------------------------
     | Minting
     * ------------------------------------------------------------------ */

    public function test_a_shared_batch_mints_nothing_and_its_name_is_still_the_code(): void
    {
        $coupon = $this->coupon(['quantity' => 5]);

        $this->assertTrue($coupon->isShared());
        $this->assertSame(Coupon::MODE_SHARED, $coupon->mode);
        $this->assertSame(0, $coupon->issuedCodes()->count());
        $this->assertSame(0, $coupon->allocations()->count());

        // Exactly as before: the name redeems and the cap counts.
        $this->assertTrue($this->redeemer()->claimByCode($coupon->name, Coupon::KIND_EVENT, 100)->succeeded());
        $this->assertSame(4, $coupon->fresh()->remaining());
    }

    public function test_unique_mode_mints_the_number_asked_for_and_every_code_is_different(): void
    {
        $coupon = $this->uniqueCoupon();

        $this->issue($coupon, 40);

        $codes = $coupon->issuedCodes()->pluck('code');

        $this->assertCount(40, $codes);
        $this->assertCount(40, $codes->unique(), 'Every minted code has to be its own.');

        // The cap follows the stock, so remaining() and isExhausted() keep working
        // without knowing allocations exist.
        $this->assertSame(40, (int) $coupon->fresh()->quantity);
        $this->assertSame(40, $coupon->fresh()->remaining());
        $this->assertFalse($coupon->fresh()->isExhausted());
    }

    public function test_no_minted_code_contains_a_character_that_gets_misread(): void
    {
        $coupon = $this->uniqueCoupon();

        $this->issue($coupon, 60);

        foreach ($coupon->issuedCodes()->pluck('code') as $code) {
            $this->assertSame(Coupon::CODE_LENGTH, strlen($code));

            foreach (str_split(Coupon::CODE_EXCLUDED) as $confusable) {
                $this->assertStringNotContainsString(
                    $confusable,
                    $code,
                    sprintf('%s contains %s, which gets misread off a screen.', $code, $confusable),
                );
            }

            // And nothing outside the legible alphabet got in by another route.
            foreach (str_split($code) as $character) {
                $this->assertStringContainsString($character, Coupon::CODE_ALPHABET);
            }
        }
    }

    public function test_a_minted_code_cannot_collide_with_a_batch_name(): void
    {
        $taken = $this->coupon(['name' => 'ABCDEF']);
        $coupon = $this->uniqueCoupon();

        $this->issue($coupon, 10);

        $this->assertNotContains('ABCDEF', $coupon->issuedCodes()->pluck('code')->all());

        // And the check runs the other way too: a name that collides with an issued
        // code is refused, because both go into the same box on the public form.
        $existing = $coupon->issuedCodes()->first()->code;

        $this->assertTrue(Coupon::codeTaken($existing));
        $this->assertTrue(Coupon::codeTaken($taken->name));
    }

    public function test_a_unique_batch_name_is_not_a_code(): void
    {
        $coupon = $this->uniqueCoupon(['name' => 'SPONSOR1', 'discount_type' => Coupon::DISCOUNT_FIXED, 'discount_value' => 20]);
        $this->issue($coupon, 5);

        // Typing the batch name redeems nothing: only a code somebody was actually
        // given says whose block to draw from.
        $outcome = $this->redeemer()->claimByCode('SPONSOR1', Coupon::KIND_EVENT, 100);

        $this->assertFalse($outcome->succeeded());
        $this->assertSame(CouponOutcome::NOT_FOUND, $outcome->status);
        $this->assertSame(5, $coupon->fresh()->remaining());
    }

    public function test_claiming_a_unique_batch_without_a_code_is_refused_by_name(): void
    {
        $coupon = $this->uniqueCoupon(['discount_type' => Coupon::DISCOUNT_FIXED, 'discount_value' => 20]);
        $this->issue($coupon, 3);

        // The admin-side entry point, handed the batch itself. There is no code, so
        // there is no block to draw from, and it says so rather than guessing one.
        $outcome = $this->redeemer()->claim($coupon, 100);

        $this->assertSame(CouponOutcome::NEEDS_CODE, $outcome->status);
        $this->assertStringContainsString('individual codes', $outcome->message());
        $this->assertSame(3, $coupon->fresh()->remaining());
    }

    public function test_an_individual_code_redeems_once_and_then_refuses(): void
    {
        $coupon = $this->uniqueCoupon(['discount_type' => Coupon::DISCOUNT_FIXED, 'discount_value' => 20]);
        $allocation = $this->issue($coupon, 3);
        $code = $this->codeFrom($allocation);

        $first = $this->redeemer()->claimByCode($code, Coupon::KIND_EVENT, 100);

        $this->assertTrue($first->succeeded());
        $this->assertSame(20.0, $first->discount);

        // The ledger row carries the code that was typed, not the batch name.
        $this->assertSame($code, $first->code->code);

        // And the code itself is now spent, paired to the row it paid for.
        $issued = CouponIssuedCode::query()->where('code', $code)->sole();

        $this->assertTrue($issued->isUsed());
        $this->assertSame($first->code->id, $issued->coupon_code_id);

        $second = $this->redeemer()->claimByCode($code, Coupon::KIND_EVENT, 100);

        $this->assertSame(CouponOutcome::ALREADY_USED, $second->status);
        $this->assertSame(2, $coupon->fresh()->remaining());
    }

    /* ---------------------------------------------------------------------
     | Blocks and who handles them
     * ------------------------------------------------------------------ */

    public function test_a_block_can_be_handled_by_somebody_and_the_handler_is_optional(): void
    {
        $coupon = $this->uniqueCoupon();

        $named = $this->issue($coupon, 5, ['full_name' => 'Siti Nurhaliza', 'phone' => '0123456789']);
        $anonymous = $this->issue($coupon, 5);

        $this->assertSame('Siti Nurhaliza', $named->holderLabel());
        $this->assertTrue($named->hasHolder());

        // Untagged is a valid state with a clear label, not a blank.
        $this->assertFalse($anonymous->hasHolder());
        $this->assertSame(CouponHolderIdentity::UNASSIGNED, $anonymous->holderLabel());

        // Every code in the block follows its block's handler.
        foreach ($named->codes as $code) {
            $this->assertSame('Siti Nurhaliza', $code->holderLabel());
        }

        $this->assertSame(10, (int) $coupon->fresh()->quantity);
    }

    public function test_a_handler_records_all_four_fields(): void
    {
        $coupon = $this->uniqueCoupon();

        $allocation = $this->issue($coupon, 2, [
            'full_name' => 'Ahmad Bin Ali',
            'email' => 'ahmad@ngo.test',
            'ic_number' => '900101-13-5566',
            'phone' => '0198887777',
        ]);

        $holder = $allocation->holder;

        $this->assertSame('Ahmad Bin Ali', $holder->full_name);
        $this->assertSame('ahmad@ngo.test', $holder->email);

        // Stored as typed: the dashes are only stripped for comparison.
        $this->assertSame('900101-13-5566', $holder->ic_number);
        $this->assertSame('0198887777', $holder->phone);

        $this->assertStringContainsString('ahmad@ngo.test', $holder->contactLine());
    }

    public function test_two_spellings_of_one_name_are_one_handler(): void
    {
        $coupon = $this->uniqueCoupon();

        // Whitespace and case only. Typed twice by two different people on two
        // different days, which is exactly how this happens.
        $first = $this->issue($coupon, 5, ['full_name' => 'Ahmad Bin Ali']);
        $second = $this->issue($coupon, 5, ['full_name' => '  ahmad   bin  ali ']);

        $this->assertSame(
            1,
            CouponHolder::query()->where('coupon_id', $coupon->id)->count(),
            'Two spellings of one name must not become two handlers.',
        );

        $this->assertSame($first->coupon_holder_id, $second->coupon_holder_id);

        // The first spelling is what is shown, because that is what was typed.
        $this->assertSame('Ahmad Bin Ali', $second->fresh('holder')->holderLabel());

        // Two separate BLOCKS, though: the person was handed codes twice.
        $this->assertSame(2, $coupon->allocations()->count());
        $this->assertSame(10, (int) $coupon->fresh()->quantity);
    }

    public function test_an_ic_outranks_a_differently_typed_name(): void
    {
        $coupon = $this->uniqueCoupon();

        // Same IC, name written two ways, punctuation different. One person.
        $first = $this->issue($coupon, 3, ['full_name' => 'Ahmad B. Ali', 'ic_number' => '900101-13-5566']);
        $second = $this->issue($coupon, 3, ['full_name' => 'AHMAD BIN ALI', 'ic_number' => '900101135566']);

        $this->assertSame($first->coupon_holder_id, $second->coupon_holder_id);
        $this->assertSame(1, CouponHolder::query()->where('coupon_id', $coupon->id)->count());
    }

    public function test_an_email_groups_two_blocks_when_there_is_no_ic(): void
    {
        $coupon = $this->uniqueCoupon();

        $first = $this->issue($coupon, 2, ['full_name' => 'Persatuan Belia', 'email' => 'Belia@NGO.test']);
        $second = $this->issue($coupon, 2, ['full_name' => 'Persatuan Belia Sibu', 'email' => 'belia@ngo.test']);

        $this->assertSame($first->coupon_holder_id, $second->coupon_holder_id);
    }

    public function test_a_second_block_fills_in_a_detail_the_first_was_missing(): void
    {
        $coupon = $this->uniqueCoupon();

        $this->issue($coupon, 2, ['full_name' => 'Siti Aminah']);
        $second = $this->issue($coupon, 2, ['full_name' => 'Siti Aminah', 'phone' => '0134445555']);

        $holder = $second->holder;

        $this->assertSame('Siti Aminah', $holder->full_name);
        $this->assertSame('0134445555', $holder->phone, 'A detail learned later should be recorded.');
    }

    public function test_handlers_are_scoped_to_their_own_coupon(): void
    {
        $first = $this->uniqueCoupon();
        $second = $this->uniqueCoupon();

        $a = $this->issue($first, 2, ['full_name' => 'Ahmad Bin Ali']);
        $b = $this->issue($second, 2, ['full_name' => 'Ahmad Bin Ali']);

        // One sponsor's commitment is a separate allocation from another's, even for
        // the same representative, so the two batches do not share a holder row.
        $this->assertNotSame($a->coupon_holder_id, $b->coupon_holder_id);
        $this->assertSame(1, $first->holders()->count());
        $this->assertSame(1, $second->holders()->count());
    }

    /* ---------------------------------------------------------------------
     | Still refused at redeem time
     * ------------------------------------------------------------------ */

    public function test_an_expired_unique_batch_refuses_a_live_code(): void
    {
        $coupon = $this->uniqueCoupon([
            'expires_at' => now()->subDay()->toDateString(),
            'discount_type' => Coupon::DISCOUNT_FIXED,
            'discount_value' => 20,
        ]);

        $code = $this->codeFrom($this->issue($coupon, 5));

        // Five codes going spare, so nothing about the stock would stop this.
        $this->assertSame(5, $coupon->fresh()->remaining());

        $outcome = $this->redeemer()->claimByCode($code, Coupon::KIND_EVENT, 100);

        $this->assertSame(CouponOutcome::EXPIRED, $outcome->status);
        $this->assertFalse(CouponIssuedCode::query()->where('code', $code)->sole()->isUsed());
    }

    public function test_a_unique_batch_with_every_code_spent_is_exhausted(): void
    {
        $coupon = $this->uniqueCoupon(['discount_type' => Coupon::DISCOUNT_FIXED, 'discount_value' => 10]);
        $allocation = $this->issue($coupon, 2);

        foreach ($allocation->codes as $code) {
            $this->assertTrue($this->redeemer()->claimByCode($code->code, Coupon::KIND_EVENT, 100)->succeeded());
        }

        $coupon->refresh();

        $this->assertSame(0, $coupon->remaining());
        $this->assertTrue($coupon->isExhausted());
        $this->assertFalse($coupon->isRedeemable());
        $this->assertSame('Used up', $coupon->stateLabel());
    }

    public function test_a_unique_batch_with_no_codes_is_not_unlimited(): void
    {
        $coupon = $this->uniqueCoupon();

        // Zero means no stock here, never no limit: reading it as unlimited would
        // offer a batch that has nothing to give.
        $this->assertFalse($coupon->isUnlimited());
        $this->assertTrue($coupon->isExhausted());
        $this->assertSame(0, $coupon->remaining());
    }

    public function test_issuing_codes_is_logged(): void
    {
        $coupon = $this->uniqueCoupon();

        $this->issue($coupon, 4, ['full_name' => 'Siti Nurhaliza']);

        $this->assertDatabaseHas('activity_logs', ['action' => 'coupons.issue']);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Coupon::class,
            'auditable_id' => $coupon->id,
            'event' => 'coupon.codes_issued',
        ]);
    }

    public function test_a_shared_batch_refuses_to_mint(): void
    {
        $coupon = $this->coupon(['quantity' => 5]);

        $this->expectException(\InvalidArgumentException::class);

        $this->issue($coupon, 3);
    }
}
