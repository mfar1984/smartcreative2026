<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route already carries permission:attendance.update
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Required rather than defaulted: the counter has to say either way,
            // because "we saw the card" and "we did not" are both meaningful and
            // a silent default would put words in their mouth.
            'ic_verified' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ic_verified.required' => 'Say whether the identity card was checked.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'ic_verified' => $this->input('ic_verified') === null
                ? null
                : $this->boolean('ic_verified'),
        ]);
    }

    public function icVerified(): bool
    {
        return (bool) $this->validated()['ic_verified'];
    }

    public function notes(): ?string
    {
        return $this->validated()['notes'] ?? null;
    }
}
