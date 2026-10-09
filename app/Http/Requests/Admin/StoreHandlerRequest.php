<?php

namespace App\Http\Requests\Admin;

use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating a handler account from the Handler tab.
 *
 * There is deliberately no role_id rule. The role is not the operator's choice
 * here: the controller assigns the handler role by its fixed slug, so a role_id
 * posted to this endpoint is never validated and never read. Allowing one would
 * let a role granted only handlers.create mint a super admin.
 */
class StoreHandlerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route already carries permission:handlers.create
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            // Free text, not an email: a handler signs in with this at /admin/login.
            'username' => ['required', 'string', 'max:120', 'unique:users,username'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
            'username' => is_string($this->username) ? trim($this->username) : $this->username,
            'email' => is_string($this->email) ? trim($this->email) : $this->email,
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
