<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ValidatesCouponHolder;
use App\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Issuing another block of codes on a unique batch.
 *
 * The owner's own workflow: a hundred codes handled by one representative now, another
 * hundred handled by somebody else later. Each ask is its own block with its own
 * holder, never a top-up of the first, because "whose codes ran out" is only
 * answerable if the blocks stay separate.
 */
class CouponAllocationRequest extends FormRequest
{
    use ValidatesCouponHolder;

    public function authorize(): bool
    {
        // The route already carries permission:coupons.update.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // At least one, because a block of nothing is nothing. The ceiling is the
            // same one the coupon form uses; a bigger giveaway is more blocks.
            'quantity' => ['required', 'integer', 'min:1', 'max:'.CouponRequest::MAX_QUANTITY],

            ...$this->holderRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.min' => 'Say how many codes to generate.',
            'quantity.max' => 'At most '.number_format(CouponRequest::MAX_QUANTITY).' codes in one block. Issue another block for more.',
            ...$this->holderMessages(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->prepareHolderFields();
    }

    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateHolder($validator),

            function (Validator $validator) {
                $coupon = $this->route('coupon');

                /*
                 | A shared batch has one code — its own name — so there is nothing to
                 | generate. Refused here as well as hidden in the UI, because a form
                 | posted at the wrong batch must not quietly mint codes nobody can
                 | redeem.
                 */
                if ($coupon instanceof Coupon && ! $coupon->isUnique()) {
                    $validator->errors()->add(
                        'quantity',
                        'This coupon uses one shared code, so there are no individual codes to generate.',
                    );
                }
            },
        ];
    }
}
