<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShopCategoryRequest extends FormRequest
{
    /**
     * Icons offered for a category. Restricted to cases that exist in the admin
     * icon component, because a free text field here would silently fall through
     * to the default circle and look like a rendering fault.
     *
     * @var array<string, string>
     */
    public const ICONS = [
        'trophy' => 'Trophy',
        'shield' => 'Medal or badge',
        'users' => 'Apparel',
        'grid' => 'General',
        'clipboard' => 'Stationery',
        'credit-card' => 'Voucher',
        'photo' => 'Print',
        'archive' => 'Packaged set',
    ];

    public function authorize(): bool
    {
        // Routes already carry permission:shop.categories.create / .update
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $category = $this->route('category');

        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'nullable',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('shop_categories', 'slug')->ignore($category?->id),
            ],
            'icon' => ['nullable', Rule::in(array_keys(self::ICONS))],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The URL slug may only contain lowercase letters, numbers and single hyphens.',
            'slug.unique' => 'Another category already uses that slug.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'slug' => filled($this->input('slug')) ? str($this->input('slug'))->slug()->toString() : null,
            'is_active' => $this->boolean('is_active'),
            'sort_order' => $this->filled('sort_order') ? (int) $this->input('sort_order') : 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function categoryAttributes(): array
    {
        return $this->safe()->except(['slug']);
    }
}
