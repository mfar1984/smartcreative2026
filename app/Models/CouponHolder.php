<?php

namespace App\Models;

use App\Support\CouponHolderIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Whoever handles a block of unique codes.
 *
 * A representative: an NGO, a company, or a person. Four fields, all optional, so the
 * owner can record only what he has — but not all four empty, which is refused at
 * validation because that is not a holder. The reason the fields exist, in the owner's
 * words: so that if somebody has to be found, it is already known whose code this is.
 *
 * ONE ROW, MANY CODES. A representative handling a hundred codes is one row here, one
 * allocation pointing at it, and a hundred codes inside that allocation. Repeating the
 * four fields per code would mean a single typo fragmenting them, and the "whose
 * allocation is finished" grouping splitting one person into two while still looking
 * grouped.
 *
 * Scoped to the batch. A batch is one sponsor's commitment and the allocation question
 * is always asked inside a batch, so the same person acting for two sponsors is two
 * holder rows with two separate allocations.
 *
 * THIS IS THE DISTRIBUTOR, NOT THE REDEEMER
 *
 * The contact details here belong to whoever hands the codes out, and they exist for
 * one reason: so the office can trace a code back to the person who was given it. The
 * participant who actually redeems is recorded on the ledger row, by NAME ONLY. The
 * two must not be conflated — a sponsor-facing view may show a redeemer's name and
 * must never be able to reach their IC or phone.
 */
class CouponHolder extends Model
{
    protected $fillable = [
        'coupon_id',
        'full_name',
        'email',
        'ic_number',
        'phone',
        'identity_key',
    ];

    /* ---------------------------------------------------------------------
     | Relations
     * ------------------------------------------------------------------ */

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** The blocks of codes handed to this person. */
    public function allocations(): HasMany
    {
        return $this->hasMany(CouponAllocation::class)->orderBy('id');
    }

    /* ---------------------------------------------------------------------
     | Reading
     * ------------------------------------------------------------------ */

    /** How this holder reads on screen: the name, or the best identifier there is. */
    public function label(): string
    {
        return CouponHolderIdentity::label(
            $this->full_name,
            $this->email,
            $this->ic_number,
            $this->phone,
        );
    }

    /**
     * The contact details, in one line, for a detail row.
     *
     * Empty string rather than a dash when there is nothing, so a caller can decide
     * whether the line is worth drawing at all.
     */
    public function contactLine(): string
    {
        return collect([$this->email, $this->phone, $this->ic_number])
            ->map(fn (?string $value) => CouponHolderIdentity::clean($value))
            ->filter()
            ->implode(' · ');
    }

    /* ---------------------------------------------------------------------
     | Writing
     * ------------------------------------------------------------------ */

    /**
     * Find or create the holder these four fields describe, on this batch.
     *
     * Matched on the identity key rather than on the name, so a second block issued
     * to the same person lands on the same holder even when the name was typed
     * slightly differently. Returns null when nothing was given, which means the
     * codes are untagged — a valid state.
     */
    public static function resolve(Coupon $coupon, array $fields): ?self
    {
        $fullName = CouponHolderIdentity::clean($fields['full_name'] ?? null);
        $email = CouponHolderIdentity::clean($fields['email'] ?? null);
        $ic = CouponHolderIdentity::clean($fields['ic_number'] ?? null);
        $phone = CouponHolderIdentity::clean($fields['phone'] ?? null);

        $key = CouponHolderIdentity::key($ic, $email, $fullName);

        /*
         | Nothing identifying, so there is nobody to be. A phone number alone cannot
         | be a key — two people sharing an office line would merge — so a holder
         | given only a phone is refused at validation; this is the belt and braces.
         */
        if ($key === null) {
            return null;
        }

        $holder = self::query()
            ->where('coupon_id', $coupon->id)
            ->where('identity_key', $key)
            ->first();

        if ($holder === null) {
            return self::create([
                'coupon_id' => $coupon->id,
                'full_name' => $fullName,
                'email' => $email,
                'ic_number' => $ic,
                'phone' => $phone,
                'identity_key' => $key,
            ]);
        }

        /*
         | Fill in anything the existing row is missing, and leave what it has alone.
         |
         | Matched by IC and told an email this time? Record the email. Already has an
         | email and told a different one? Keep the first: the key says this is the
         | same person, and overwriting would quietly rewrite history on a hundred
         | codes that were already handed out.
         */
        $holder->fill(array_filter([
            'full_name' => $holder->full_name === null ? $fullName : null,
            'email' => $holder->email === null ? $email : null,
            'ic_number' => $holder->ic_number === null ? $ic : null,
            'phone' => $holder->phone === null ? $phone : null,
        ], fn ($value) => $value !== null))->save();

        return $holder;
    }
}
