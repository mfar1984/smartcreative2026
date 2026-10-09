<?php

namespace App\Http\Requests\Admin;

use App\Support\IpAllowlist;
use App\Support\SecuritySettings;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the General Config > Security tab.
 *
 * Part 1 covers the password policy and the session controls. The three toggles
 * are coerced to a strict 0/1 string before validation so a missing checkbox is
 * stored as '0' rather than vanishing. The numeric fields are bounded so a typo
 * cannot store a value that would weaken the policy below Laravel's floor or
 * start logging people out almost immediately.
 *
 * Part 2 adds the sign-in ban, the rate limits and the IP allowlist. Those fields
 * are `sometimes`: the tab always posts them (each checkbox has a hidden 0 in
 * front of it), and a post that leaves one out keeps what is stored rather than
 * resetting it. Every number has a floor that keeps it clear of an instant
 * lockout, and every allowlist line must be a real IP or CIDR range.
 *
 * Part 3 adds the two logging switches and the new sign-in location warning,
 * following the same `sometimes` pattern.
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

            'ban_enabled' => ['sometimes', 'required', 'boolean'],
            'ban_after_failures' => [
                'sometimes', 'required', 'integer',
                'min:' . SecuritySettings::MIN_BAN_AFTER_FAILURES,
                'max:' . SecuritySettings::MAX_BAN_AFTER_FAILURES,
            ],
            'ban_window_minutes' => [
                'sometimes', 'required', 'integer',
                'min:' . SecuritySettings::MIN_BAN_WINDOW_MINUTES,
                'max:' . SecuritySettings::MAX_BAN_WINDOW_MINUTES,
            ],
            'ban_duration_minutes' => [
                'sometimes', 'required', 'integer',
                'min:' . SecuritySettings::MIN_BAN_DURATION_MINUTES,
                'max:' . SecuritySettings::MAX_BAN_DURATION_MINUTES,
            ],

            'login_attempts_per_minute' => [
                'sometimes', 'required', 'integer',
                'min:' . SecuritySettings::MIN_LOGIN_ATTEMPTS_PER_MINUTE,
                'max:' . SecuritySettings::MAX_LOGIN_ATTEMPTS_PER_MINUTE,
            ],
            'admin_requests_per_minute' => [
                'sometimes', 'bail', 'required', 'integer',
                'min:0',
                'max:' . SecuritySettings::MAX_ADMIN_REQUESTS_PER_MINUTE,
                function (string $attribute, mixed $value, Closure $fail): void {
                    $perMinute = (int) $value;

                    if ($perMinute !== 0 && $perMinute < SecuritySettings::MIN_ADMIN_REQUESTS_PER_MINUTE) {
                        $fail('Admin requests per minute must be 0 (no limit) or at least '
                            . SecuritySettings::MIN_ADMIN_REQUESTS_PER_MINUTE
                            . ', so a typo cannot stall the scoring desk.');
                    }
                },
            ],

            'activity_log_enabled' => ['sometimes', 'required', 'boolean'],
            'audit_log_enabled' => ['sometimes', 'required', 'boolean'],
            'new_location_warning' => ['sometimes', 'required', 'boolean'],

            'ip_allowlist' => [
                'sometimes', 'bail', 'nullable', 'string',
                'max:' . SecuritySettings::MAX_IP_ALLOWLIST_LENGTH,
                function (string $attribute, mixed $value, Closure $fail): void {
                    // Numbered as the owner sees them in the box, blank lines included,
                    // so the message points at the line to fix.
                    foreach (preg_split('/\R/', (string) $value) ?: [] as $index => $line) {
                        $line = trim($line);

                        if ($line !== '' && ! IpAllowlist::isValidEntry($line)) {
                            $fail(sprintf(
                                'Line %d of the IP allowlist, "%s", is not a valid IP address or CIDR range. Use one per line, for example 203.0.113.10 or 203.0.113.0/24.',
                                $index + 1,
                                $line,
                            ));

                            return;
                        }
                    }
                },
            ],
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

        // Only when posted: the form always sends these, behind a hidden 0, and a
        // post without one must not switch it off by accident.
        foreach (['ban_enabled', 'activity_log_enabled', 'audit_log_enabled', 'new_location_warning'] as $toggle) {
            if ($this->has($toggle)) {
                $this->merge([$toggle => $this->boolean($toggle) ? '1' : '0']);
            }
        }
    }

    /**
     * Everything that passed, with the allowlist in its stored shape: trimmed
     * lines, blanks dropped, one entry per line.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $validated = $this->validated();

        if (array_key_exists('ip_allowlist', $validated)) {
            $validated['ip_allowlist'] = IpAllowlist::normalise($validated['ip_allowlist']);
        }

        return $validated;
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
            'ban_after_failures.min' => 'At least ' . SecuritySettings::MIN_BAN_AFTER_FAILURES
                . ' failed attempts must be allowed before a ban, so one mistyped password cannot lock anybody out.',
            'login_attempts_per_minute.min' => 'At least ' . SecuritySettings::MIN_LOGIN_ATTEMPTS_PER_MINUTE
                . ' sign in attempts per minute must be allowed.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'ban_after_failures' => 'failures before ban',
            'ban_window_minutes' => 'counting window',
            'ban_duration_minutes' => 'ban duration',
            'login_attempts_per_minute' => 'sign in attempts per minute',
            'admin_requests_per_minute' => 'admin requests per minute',
            'ip_allowlist' => 'IP allowlist',
        ];
    }
}
