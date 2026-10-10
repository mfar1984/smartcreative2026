<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Support\CouponHolderIdentity;
use Illuminate\Validation\Validator;

/**
 * The four holder fields, validated the same way wherever a block of codes is issued.
 *
 * Shared by the coupon form (which issues the first block) and the Issue Codes form
 * (which issues every one after it), because the rule has to be identical: a holder
 * recorded one way on create and another way later is two holders in the report.
 *
 * ALL FOUR ARE OPTIONAL, AND ALL FOUR EMPTY IS A VALID ANSWER — it means an unassigned
 * block, which groups under "Not assigned" and works perfectly well.
 *
 * WHAT IS REFUSED is a block where the only thing given is a PHONE NUMBER. A phone
 * cannot be the grouping key: two people on one office line would merge into a single
 * holder and their allocations would silently add together, which is the exact failure
 * the key exists to prevent. So anything identifying — a name, an email or an IC — has
 * to be there before the contact details mean anything.
 *
 * These are the DISTRIBUTOR's details, not a participant's. They exist so the office
 * can trace a code back to whoever was handed it.
 */
trait ValidatesCouponHolder
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function holderRules(): array
    {
        return [
            'holder_full_name' => ['nullable', 'string', 'max:190'],

            // A real email rule rather than a string: the point of recording it is
            // being able to reach the person, and a typo nobody notices is worse than
            // a blank.
            'holder_email' => ['nullable', 'email', 'max:190'],

            // Stored as typed, trimmed. Dashes are only stripped for comparison, so
            // 900101-13-5566 reads back the way it was written.
            'holder_ic_number' => ['nullable', 'string', 'max:32'],
            'holder_phone' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function holderMessages(): array
    {
        return [
            'holder_email.email' => 'The handler email does not look like an email address.',
        ];
    }

    /** Trim and collapse inner whitespace before anything else looks at the values. */
    protected function prepareHolderFields(): void
    {
        $this->merge([
            'holder_full_name' => CouponHolderIdentity::clean($this->input('holder_full_name')),
            'holder_email' => CouponHolderIdentity::clean($this->input('holder_email')),
            'holder_ic_number' => CouponHolderIdentity::clean($this->input('holder_ic_number')),
            'holder_phone' => CouponHolderIdentity::clean($this->input('holder_phone')),
        ]);
    }

    protected function validateHolder(Validator $validator): void
    {
        $name = $this->input('holder_full_name');
        $email = $this->input('holder_email');
        $ic = $this->input('holder_ic_number');
        $phone = $this->input('holder_phone');

        // Nothing at all: an unassigned block, which is allowed.
        if (blank($name) && blank($email) && blank($ic) && blank($phone)) {
            return;
        }

        if (CouponHolderIdentity::key($ic, $email, $name) === null) {
            $validator->errors()->add(
                'holder_full_name',
                'A phone number on its own cannot identify a handler. Add a name, an email address or an IC number.',
            );
        }
    }

    /**
     * The holder fields under the names CouponHolder::resolve() expects.
     *
     * @return array<string, string|null>
     */
    public function holderFields(): array
    {
        return [
            'full_name' => $this->input('holder_full_name'),
            'email' => $this->input('holder_email'),
            'ic_number' => $this->input('holder_ic_number'),
            'phone' => $this->input('holder_phone'),
        ];
    }
}
