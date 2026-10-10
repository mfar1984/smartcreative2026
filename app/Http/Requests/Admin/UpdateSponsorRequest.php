<?php

namespace App\Http\Requests\Admin;

use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a sponsorship account from the Sponsorship tab.
 *
 * No role_id rule, for the same reason StoreSponsorRequest has none: the role
 * belongs to the sponsorship account by definition, so it is neither offered on the
 * form nor read from the request.
 */
class UpdateSponsorRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route already carries permission:sponsors.update
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $userId = $this->route('user')->id;

        return [
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'max:120', Rule::unique('users', 'username')->ignore($userId)],
            'email' => ['required', 'string', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($userId)],
            // Blank means "leave the current password alone".
            'password' => ['nullable', 'confirmed', PasswordPolicy::rule()],
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
            'sponsor_committed_amount' => blank($this->sponsor_committed_amount)
                ? null
                : $this->sponsor_committed_amount,
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
