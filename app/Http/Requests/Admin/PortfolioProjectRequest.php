<?php

namespace App\Http\Requests\Admin;

use App\Models\PortfolioProject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PortfolioProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Routes already carry permission:portfolio.create / portfolio.update
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $project = $this->route('project');

        return [
            'title' => ['required', 'string', 'max:180'],
            'slug' => [
                'nullable',
                'string',
                'max:180',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('portfolio_projects', 'slug')->ignore($project?->id),
            ],

            'client' => ['nullable', 'string', 'max:180'],
            'service' => ['required', Rule::in(array_keys(PortfolioProject::SERVICES))],
            'category' => ['required', 'string', 'max:100'],

            /*
             | Capped low on purpose. The summary is the card, and a card that runs
             | to a paragraph stops being scannable, which is the only thing a
             | portfolio grid is for.
             */
            'summary' => ['required', 'string', 'max:280'],
            'description' => ['nullable', 'string', 'max:5000'],

            'location' => ['nullable', 'string', 'max:180'],

            // Cannot be in the future: a portfolio records work already delivered.
            'delivered_on' => ['required', 'date', 'before_or_equal:today'],

            'highlights' => ['nullable', 'string', 'max:2000'],

            // Matches the event poster rule. 4 MB is enough for a photograph
            // without letting a camera original through untouched.
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_image' => ['nullable', 'boolean'],

            'status' => ['required', Rule::in(array_keys(PortfolioProject::STATUSES))],
            'is_featured' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The URL slug may only contain lowercase letters, numbers and single hyphens.',
            'summary.max' => 'Keep the summary to 280 characters. It has to fit on a card.',
            'delivered_on.before_or_equal' => 'A portfolio entry records work already delivered, so the date cannot be in the future.',
            'service.in' => 'Choose which service this work belongs to.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => trim((string) $this->input('title')),
            'slug' => filled($this->input('slug')) ? str($this->input('slug'))->slug()->toString() : null,
            'client' => filled($this->input('client')) ? trim((string) $this->input('client')) : null,
            'category' => trim((string) $this->input('category')),
            'location' => filled($this->input('location')) ? trim((string) $this->input('location')) : null,

            // Unchecked boxes are absent from the payload rather than false.
            'is_featured' => $this->boolean('is_featured'),
            'remove_image' => $this->boolean('remove_image'),

            'sort_order' => $this->filled('sort_order') ? (int) $this->input('sort_order') : 0,
        ]);
    }

    /**
     * Attributes ready to fill the model. The image and the slug are handled by
     * the controller, so they are kept out.
     *
     * @return array<string, mixed>
     */
    public function projectAttributes(): array
    {
        return $this->safe()->except(['image', 'remove_image', 'slug']);
    }
}
