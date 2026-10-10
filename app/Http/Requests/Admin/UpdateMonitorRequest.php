<?php

namespace App\Http\Requests\Admin;

use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a monitoring account from the Monitoring tab.
 *
 * No role_id rule, for the same reason StoreMonitorRequest has none: the role belongs
 * to the monitoring account by definition, so it is neither offered on the form nor
 * read from the request. An edit here therefore cannot turn a monitor into anything
 * else, and cannot be used to widen what it holds.
 */
class UpdateMonitorRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route already carries permission:monitors.update
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
     * Nothing sent means nothing assigned, which is how the last event is taken off
     * an account: the alternative — treating an absent field as "leave it alone" —
     * would make unticking every box impossible from a form that sends only what is
     * ticked.
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
