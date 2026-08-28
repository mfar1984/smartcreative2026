<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing one photograph already in a gallery: its caption, its position, and which
 * project it belongs to.
 *
 * Moving an image between projects is allowed because the alternative is deleting
 * and re-uploading it, which loses the file and the caption to fix a mistake in a
 * dropdown.
 */
class PortfolioImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route already carries permission:portfolio.gallery.update
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'portfolio_project_id' => [
                'required',
                'integer',
                Rule::exists('portfolio_projects', 'id'),
            ],
            'caption' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'portfolio_project_id.exists' => 'That project no longer exists.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'caption' => filled($this->input('caption')) ? trim((string) $this->input('caption')) : null,
            'sort_order' => $this->filled('sort_order') ? (int) $this->input('sort_order') : 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function imageAttributes(): array
    {
        return $this->safe()->only(['portfolio_project_id', 'caption', 'sort_order']);
    }
}
