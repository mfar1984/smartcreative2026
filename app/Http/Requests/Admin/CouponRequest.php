<?php

namespace App\Http\Requests\Admin;

use App\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CouponRequest extends FormRequest
{
    /**
     * How many times one coupon code may be used.
     *
     * Generous for a real giveaway, and a ceiling rather than a storage concern:
     * nothing is written up front, so this only stops a slip of the keyboard turning
     * a fifty-use coupon into a fifty-thousand-use one.
     */
    public const MAX_QUANTITY = 5000;

    public function authorize(): bool
    {
        // The routes already carry permission:coupons.create / coupons.update.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $coupon = $this->route('coupon');

        return [
            'kind' => ['required', Rule::in(array_keys(Coupon::KINDS))],

            /*
             | Uppercase letters and digits only. Not a cosmetic rule: the name IS the
             | code, read off a printed ticket and typed back in, and allowing spaces,
             | hyphens or lowercase would mean a visitor who types what they see is
             | told their coupon does not exist.
             |
             | Deliberately NOT narrowed to the generator's legible alphabet. Generate
             | avoids the characters that get misread, but a human-chosen SUKAN50 is
             | clearer than anything random, and existing names have to keep working.
             */
            'name' => [
                'required',
                'string',
                'min:4',
                'max:32',
                'regex:/^[A-Z0-9]+$/',
                Rule::unique('coupons', 'name')->ignore($coupon?->id),
            ],

            // How many times the code may be used. 0 is unlimited, which is why min
            // is 0 rather than 1.
            'quantity' => ['required', 'integer', 'min:0', 'max:' . self::MAX_QUANTITY],

            'expires_at' => ['required', 'date'],

            'discount_type' => ['required', Rule::in(array_keys(Coupon::DISCOUNT_TYPES))],
            'discount_value' => ['required', 'numeric'],

            'design' => ['required', Rule::in(array_keys(Coupon::DESIGNS))],

            /*
             | An image only, and the 'image' rule rather than a mimes list because
             | there is no document case here: this is artwork that gets drawn on a
             | page. 4 MB matches what a poster is allowed.
             */
            'design_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_design_image' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'A coupon code may only contain capital letters and digits, with no spaces.',
            'name.unique' => 'That coupon code is already in use.',
            'quantity.max' => 'A coupon can allow at most ' . number_format(self::MAX_QUANTITY) . ' uses.',
            'design_image.image' => 'The coupon design must be an image.',
            'design_image.max' => 'The coupon design must be 4 MB or smaller.',
        ];
    }

    protected function prepareForValidation(): void
    {
        /*
         | Folded to uppercase before validation rather than rejected for being typed
         | in lowercase. The operator is naming a thing, not satisfying a format, and
         | the stored shape is what the regex above then insists on.
         */
        $name = $this->input('name');

        $this->merge([
            'name' => is_string($name) ? Str::upper(preg_replace('/\s+/', '', trim($name)) ?? '') : $name,
            'quantity' => $this->input('quantity') === '' ? 0 : $this->input('quantity'),
            'remove_design_image' => $this->boolean('remove_design_image'),
        ]);
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $type = $this->input('discount_type');
                $value = (float) $this->input('discount_value');

                if ($type === Coupon::DISCOUNT_PERCENTAGE) {
                    // 1 to 100. Over 100 would mean handing money back, and 0 would be
                    // a coupon that does nothing while looking like it does.
                    if ($value < 1 || $value > 100) {
                        $validator->errors()->add('discount_value', 'A percentage discount must be between 1 and 100.');
                    }

                    return;
                }

                if ($type === Coupon::DISCOUNT_FIXED && $value <= 0) {
                    $validator->errors()->add('discount_value', 'A fixed discount must be more than RM 0.00.');
                }

                /*
                 | The owner asked that a fixed amount could not exceed the event's
                 | price. It deliberately is not checked here, because a batch is
                 | created before it is attached to anything and there is no price to
                 | compare against yet. It is enforced where a price exists instead:
                 | CouponDiscount caps the discount at the charge so a total floors at
                 | zero, and the picker on the event and product forms warns when a
                 | ticked batch is worth more than that item costs.
                 */
            },

            function (Validator $validator) {
                $expires = $this->input('expires_at');

                if (blank($expires)) {
                    return;
                }

                /*
                 | Tomorrow or later. A coupon expiring today would be refused by
                 | CouponRedeemer the moment somebody tried it — expiry runs to the end
                 | of the day, so today is technically still live, but creating a batch
                 | that dies at midnight is almost always a mistyped year.
                 */
                if (strtotime($expires) < strtotime(now()->toDateString())) {
                    $validator->errors()->add('expires_at', 'The expiry date cannot be in the past.');
                }
            },

            function (Validator $validator) {
                $name = (string) $this->input('name');

                if ($name === '' || $validator->errors()->has('name')) {
                    return;
                }

                $coupon = $this->route('coupon');

                /*
                 | The same check the unique rule above makes, kept because the name is
                 | folded and trimmed in prepareForValidation() and this is the one
                 | place that reads it the way a visitor would type it.
                 */
                if (Coupon::codeTaken($name, $coupon?->id)) {
                    $validator->errors()->add('name', 'That code is already in use by another coupon.');
                }
            },

            function (Validator $validator) {
                $coupon = $this->route('coupon');

                if (! $coupon instanceof Coupon) {
                    return;
                }

                $used = $coupon->redeemedCount();
                $wanted = (int) $this->input('quantity');

                /*
                 | RAISING the cap on a coupon people are already using is safe and
                 | allowed: it simply means the same code works a few more times.
                 | Unlimited (0) is the extreme of the same move.
                 |
                 | LOWERING it below what has already gone out is not. Those uses
                 | happened and moved money, and a cap under the count would make
                 | isExhausted() true on redemptions that were honoured — retroactively
                 | invalidating discounts that are already on registrations and orders.
                 */
                if ($wanted > 0 && $used > $wanted) {
                    $validator->errors()->add(
                        'quantity',
                        sprintf(
                            'This coupon has already been used %d %s, so the limit cannot be set below %d. Those uses have already been honoured. Raise the limit, set 0 for no limit, or create a new coupon.',
                            $used,
                            $used === 1 ? 'time' : 'times',
                            $used,
                        ),
                    );
                }

                // The kind is still fixed once used: it decides which forms offer the
                // coupon and which records its redemptions point at.
                if ($this->input('kind') !== $coupon->kind && $used > 0) {
                    $validator->errors()->add(
                        'kind',
                        'This coupon has already been used, so what it applies to cannot change.',
                    );
                }
            },
        ];
    }

    /**
     * Attributes to write onto the coupon. The design upload is handled separately.
     *
     * @return array<string, mixed>
     */
    public function couponAttributes(): array
    {
        return $this->safe()->only([
            'kind',
            'name',
            'quantity',
            'expires_at',
            'discount_type',
            'discount_value',
            'design',
        ]);
    }
}
