<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route already carries permission:settings.maintenance.update
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['nullable', 'boolean'],
            'heading' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // An unchecked checkbox is absent from the payload.
        $this->merge([
            'enabled' => $this->boolean('enabled'),
        ]);
    }
}
