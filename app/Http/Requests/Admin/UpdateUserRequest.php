<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route already carries permission:users.update
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
            'password' => ['nullable', 'confirmed', Password::min(10)->letters()->numbers()->symbols()],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
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
