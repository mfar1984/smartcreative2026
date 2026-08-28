<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A batch of photographs being added to one project.
 *
 * The project is part of the upload rather than something set afterwards, so an
 * image can never exist untagged. Every file in the batch is tagged to the project
 * chosen once at the top of the form.
 */
class PortfolioGalleryUploadRequest extends FormRequest
{
    /** How many files one upload may carry. */
    public const MAX_FILES = 20;

    public function authorize(): bool
    {
        // The route already carries permission:portfolio.gallery.create
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

            'images' => ['required', 'array', 'min:1', 'max:' . self::MAX_FILES],

            // 6 MB per file: event photography off a phone lands around 3 to 5 MB,
            // and rejecting those would mean asking people to resize first.
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],

            /*
             | One caption applied to every file in the batch. Optional, and usually
             | left blank: captions are better written per image afterwards, which is
             | what the list view is for.
             */
            'caption' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'portfolio_project_id.required' => 'Choose which project these photographs belong to.',
            'portfolio_project_id.exists' => 'That project no longer exists.',
            'images.required' => 'Choose at least one image to upload.',
            'images.max' => 'Up to ' . self::MAX_FILES . ' images at a time. Upload the rest in a second batch.',
            'images.*.image' => 'Every file has to be an image.',
            'images.*.max' => 'Each image has to be under 6 MB.',
            'images.*.mimes' => 'Images have to be JPG, PNG or WebP.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'caption' => filled($this->input('caption')) ? trim((string) $this->input('caption')) : null,
        ]);
    }
}
