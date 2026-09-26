<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A message written on a competitor's public profile.
 *
 * Mirrors StoreContactMessageRequest, minus the service list: somebody writing to a
 * player is not choosing a product. The message floor of ten characters is kept,
 * because "hi" gives the office nothing to act on.
 */
class StorePlayerMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
            'message' => ['required', 'string', 'min:10', 'max:3000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.min' => 'Please write a little more so we know what to pass on.',
            'phone.regex' => 'A telephone number may only contain digits, spaces and + - ( ).',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'your name',
            'email' => 'your email',
            'phone' => 'your telephone',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'email' => trim((string) $this->input('email')),
            'phone' => trim((string) $this->input('phone')),
            'message' => trim((string) $this->input('message')),
        ]);
    }
}
