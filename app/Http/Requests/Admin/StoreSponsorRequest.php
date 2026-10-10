<?php

namespace App\Http\Requests\Admin;

use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating a sponsorship account from the Sponsorship tab.
 *
 * There is deliberately no role_id rule, for the reason StoreHandlerRequest has
 * none: the role is not the operator's choice here. The controller assigns the
 * sponsor role by its fixed slug, so a role_id posted to this endpoint is never
 * validated and never read. Allowing one would let a role granted only
 * sponsors.create mint a super admin.
 *
 * sponsor_committed_amount is the one extra field, and it is a PROMISE somebody
 * made rather than anything the system can work out — so it is optional, typed by
 * hand, and never derived from a coupon.
 */
class StoreSponsorRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route already carries permission:sponsors.create
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            // Free text, not an email: a sponsor signs in with this at /admin/login.
            'username' => ['required', 'string', 'max:120', 'unique:users,username'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
            'sponsor_committed_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
            'username' => is_string($this->username) ? trim($this->username) : $this->username,
            'email' => is_string($this->email) ? trim($this->email) : $this->email,

            // Blank means "no pledge recorded", which is a different answer from zero.
            'sponsor_committed_amount' => blank($this->sponsor_committed_amount)
                ? null
                : $this->sponsor_committed_amount,

            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
