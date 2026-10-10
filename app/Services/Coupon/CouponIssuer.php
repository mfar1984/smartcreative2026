<?php

namespace App\Services\Coupon;

use App\Models\Coupon;
use App\Models\CouponAllocation;
use App\Models\CouponHolder;
use App\Models\CouponIssuedCode;
use App\Services\AdminLogger;
use Illuminate\Support\Facades\DB;

/**
 * Minting a block of unique codes and handing it to a holder.
 *
 * ONE ISSUE IS ONE ALLOCATION. Asking for a hundred more codes never grows an existing
 * block; it creates a new one with its own holder. That is how a second representative
 * gets their own share, and it is what makes "whose codes ran out" answerable at all.
 *
 * WHY THE CODES ARE GENERATED IN BULK RATHER THAN ONE AT A TIME
 *
 * Coupon::generateCode() asks the database whether each candidate is free, which is
 * right for one code on a form and wrong for a thousand: that would be a thousand
 * round trips while an operator watches a spinner. So candidates are drawn from the
 * same legible alphabet in memory, and the whole batch of them is checked against both
 * namespaces in two queries. The alphabet, the length and the collision check are
 * exactly the ones the single-code path uses — 24 characters over 6 places is 191
 * million combinations, so a collision is rare and a retry is cheap.
 *
 * The batch `quantity` is kept as the total number of codes issued, so remaining(),
 * isExhausted() and every screen that reads them keep working without being told about
 * allocations at all.
 */
class CouponIssuer
{
    /** Bounded, so a saturated alphabet cannot spin for ever. */
    private const MAX_ROUNDS = 25;

    /**
     * Mint a block of codes on a unique batch and record who handles it.
     *
     * @param  array<string, mixed>  $holderFields  full_name, email, ic_number, phone — all optional
     */
    public function issue(Coupon $coupon, int $count, array $holderFields = []): CouponAllocation
    {
        if (! $coupon->isUnique()) {
            throw new \InvalidArgumentException('Only a unique-code coupon issues individual codes.');
        }

        if ($count < 1) {
            throw new \InvalidArgumentException('A block has to contain at least one code.');
        }

        $allocation = DB::transaction(function () use ($coupon, $count, $holderFields) {
            /*
             | The holder first, so the allocation is never written pointing at
             | nothing. Resolved by identity key rather than created blindly: a second
             | block for the same person lands on the same holder row even when the
             | name was typed slightly differently.
             */
            $holder = CouponHolder::resolve($coupon, $holderFields);

            $allocation = CouponAllocation::create([
                'coupon_id' => $coupon->id,
                'coupon_holder_id' => $holder?->id,
                'quantity' => $count,
                'issued_at' => now(),
            ]);

            $this->mint($coupon, $allocation, $count);

            /*
             | The cap follows the stock. `quantity` on the batch is the total number
             | of codes in existence, which is what makes isExhausted() and
             | remaining() correct in unique mode without either of them knowing that
             | allocations exist.
             */
            $coupon->forceFill([
                'quantity' => $coupon->issuedCodes()->count(),
            ])->save();

            return $allocation;
        });

        $this->log($coupon, $allocation, $count);

        return $allocation->fresh(['holder']);
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * Write $count codes nothing else is using.
     *
     * Inserted rather than created one model at a time: a thousand Eloquent saves is a
     * thousand statements, and there is nothing on these rows for a model event to do.
     */
    private function mint(Coupon $coupon, CouponAllocation $allocation, int $count): void
    {
        $minted = 0;
        $now = now();

        for ($round = 0; $round < self::MAX_ROUNDS && $minted < $count; $round++) {
            $free = $this->freeCodes($count - $minted);

            if ($free === []) {
                continue;
            }

            $rows = [];

            foreach ($free as $code) {
                $rows[] = [
                    'coupon_id' => $coupon->id,
                    'coupon_allocation_id' => $allocation->id,
                    'code' => $code,
                    'used_at' => null,
                    'coupon_code_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Chunked because SQLite caps how many bound parameters one statement may
            // carry, and seven columns a row reaches it well before a thousand rows.
            foreach (array_chunk($rows, 100) as $chunk) {
                CouponIssuedCode::query()->insert($chunk);
            }

            $minted += count($free);
        }

        if ($minted < $count) {
            throw new \RuntimeException('Could not generate enough coupon codes that are not already in use.');
        }
    }

    /**
     * $wanted candidate codes that collide with nothing, drawn from the safe alphabet.
     *
     * Over-generated, because some candidates will collide with each other or with
     * what is already stored, and two queries to find out is far cheaper than one
     * query per code. Fewer than $wanted may come back; the caller loops.
     *
     * @return array<int, string>
     */
    private function freeCodes(int $wanted): array
    {
        $candidates = [];

        // A tenth over, plus a few, so a small ask still has slack.
        $target = $wanted + (int) ceil($wanted * 0.1) + 4;

        while (count($candidates) < $target) {
            $candidates[$this->randomCode()] = true;
        }

        $candidates = array_keys($candidates);

        /*
         | Both namespaces, which is the same rule Coupon::codeTaken() holds for a
         | single code: a batch name and an issued code go into the same box on the
         | public form, so one must never shadow the other.
         */
        $taken = CouponIssuedCode::query()
            ->whereIn('code', $candidates)
            ->pluck('code')
            ->all();

        $taken = array_merge($taken, Coupon::query()
            ->whereIn('name', $candidates)
            ->pluck('name')
            ->all());

        return array_slice(array_values(array_diff($candidates, $taken)), 0, $wanted);
    }

    /**
     * One code from the legible alphabet.
     *
     * The characters that get misread off a screen are already excluded from
     * Coupon::CODE_ALPHABET — the owner read a B as an 8 on his own screen in
     * production — and a distributed code is typed off paper, so this is the one
     * alphabet that may be used.
     */
    private function randomCode(): string
    {
        $alphabet = Coupon::CODE_ALPHABET;
        $last = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < Coupon::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $last)];
        }

        return $code;
    }

    /**
     * Every issue leaves a trail entry, naming the holder.
     *
     * Outside the transaction so a logging failure cannot roll back codes that have
     * already been committed and possibly printed.
     */
    private function log(Coupon $coupon, CouponAllocation $allocation, int $count): void
    {
        AdminLogger::activity('coupons.issue', sprintf(
            'Issued %d %s of coupon %s to %s.',
            $count,
            $count === 1 ? 'code' : 'codes',
            $coupon->name,
            $allocation->holderLabel(),
        ));

        AdminLogger::audit($coupon, 'coupon.codes_issued', null, [
            'coupon' => $coupon->name,
            'allocation' => $allocation->id,
            'quantity' => $count,
            'holder' => $allocation->holderLabel(),
            'total_issued' => $coupon->fresh()?->quantity,
        ]);
    }
}
