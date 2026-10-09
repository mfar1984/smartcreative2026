<?php

namespace App\Http\Requests\Admin;

use App\Support\SecuritySettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the General Config > Security tab.
 *
 * Part 1 covers the password policy and the session controls. The three toggles
 * are coerced to a strict 0/1 string before validation so a missing checkbox is
 * stored as '0' rather than vanishing. The numeric fields are bounded so a typo
 * cannot store a value that would weaken the policy below Laravel's floor or
 * start logging people out almost immediately.
 */
class UpdateSecurityConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route already carries permission:settings.security.update
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'password_min' => [
                'required', 'integer',
                'min:' . SecuritySettings::MIN_PASSWORD_MIN,
                'max:' . SecuritySettings::MAX_PASSWORD_MIN,
            ],
            'password_require_upper' => ['required', 'boolean'],
            'password_require_number' => ['required', 'boolean'],
            'password_require_symbol' => ['required', 'boolean'],
            'password_expiry_days' => [
                'required', 'integer',
                'min:0',
                'max:' . SecuritySettings::MAX_PASSWORD_EXPIRY_DAYS,
            ],

            'session_timeout_minutes' => [
                'required', 'integer',
                'min:' . SecuritySettings::MIN_SESSION_TIMEOUT_MINUTES,
                'max:' . SecuritySettings::MAX_SESSION_TIMEOUT_MINUTES,
            ],
            'single_session' => ['required', 'boolean'],
            'destroy_session_on_logout' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Unchecked checkboxes are absent from the post, so each toggle is forced
        // to a definite 0/1 here rather than being treated as missing.
        $this->merge([
            'password_require_upper' => $this->boolean('password_require_upper') ? '1' : '0',
            'password_require_number' => $this->boolean('password_require_number') ? '1' : '0',
            'password_require_symbol' => $this->boolean('password_require_symbol') ? '1' : '0',
            'single_session' => $this->boolean('single_session') ? '1' : '0',
            'destroy_session_on_logout' => $this->boolean('destroy_session_on_logout') ? '1' : '0',
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'session_timeout_minutes.min' => 'The inactivity timeout must be at least '
                . SecuritySettings::MIN_SESSION_TIMEOUT_MINUTES
                . ' minutes, so an admin is not logged out almost immediately.',
            'password_min.min' => 'The minimum password length cannot be lower than '
                . SecuritySettings::MIN_PASSWORD_MIN . '.',
        ];
    }
}
