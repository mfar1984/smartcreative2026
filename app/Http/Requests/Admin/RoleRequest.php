<?php

namespace App\Http\Requests\Admin;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Routes already carry permission:roles.create / roles.update
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $role = $this->route('role');

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('roles', 'name')->ignore($role?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A role with this name already exists.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /**
     * Permission ids to grant, filtered down to ids that actually exist.
     *
     * @return array<int, int>
     */
    public function permissionIds(): array
    {
        return collect($this->validated()['permissions'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Attributes saved onto the role record itself.
     *
     * @return array<string, mixed>
     */
    public function roleAttributes(): array
    {
        $validated = $this->validated();

        return [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'],
        ];
    }

    /**
     * Slug derived from the name, only used when creating.
     */
    public function slug(): string
    {
        $base = str($this->validated()['name'])->slug()->toString();
        $slug = $base !== '' ? $base : 'role';
        $suffix = 1;

        while (Role::query()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }
}
