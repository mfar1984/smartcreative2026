<?php

namespace App\Http\Requests\Admin;

use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating a monitoring account from the Monitoring tab.
 *
 * There is deliberately no role_id rule, for the reason StoreHandlerRequest and
 * StoreSponsorRequest have none: the role is not the operator's choice here. The
 * controller assigns the monitor role by its fixed slug, so a role_id posted to this
 * endpoint is never validated and never read. Allowing one would let a role granted
 * only monitors.create mint a super admin.
 *
 * `events` is the one extra field, and it is the whole of what a monitoring account
 * can see. Every id is checked to be a real event, so a crafted value is refused by
 * validation rather than attached — and an account created with none sees nothing,
 * which is the safe direction for a default.
 */
class StoreMonitorRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route already carries permission:monitors.create
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            // Free text, not an email: a monitor signs in with this at /admin/login.
            'username' => ['required', 'string', 'max:120', 'unique:users,username'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
            'is_active' => ['nullable', 'boolean'],

            'events' => ['nullable', 'array'],
            'events.*' => ['integer', 'exists:events,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'events.*.exists' => 'One of the events ticked no longer exists.',
        ];
    }

    /**
     * The event ids to assign, de-duplicated.
     *
     * Read by the controller rather than passed to create(), because `events` is a
     * pivot and not a column: leaving it in the attributes would be a mass-assignment
     * attempt against a field that does not exist.
     *
     * @return array<int, int>
     */
    public function assignedEvents(): array
    {
        return collect($this->validated()['events'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The account's own fields, with the pivot taken out.
     *
     * @return array<string, mixed>
     */
    public function accountAttributes(): array
    {
        return collect($this->validated())->except('events')->all();
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
