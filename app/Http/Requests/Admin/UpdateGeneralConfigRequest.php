<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGeneralConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route already carries permission:settings.general.update
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'site_name' => ['required', 'string', 'max:150'],
            'tagline' => ['nullable', 'string', 'max:200'],
            'contact_email' => ['required', 'string', 'email:rfc', 'max:190'],
            'contact_phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
            'whatsapp' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
            'registration_no' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'timezone' => ['required', 'string', 'timezone'],

            /*
             | Branding uploads. Every one is optional: saving the form without
             | picking a file must leave the current image alone rather than clear it.
             |
             | mimetypes rather than the `image` rule, because `image` rejects SVG,
             | and a logo is exactly the thing somebody will want as an SVG so it
             | stays sharp on a high density screen.
             */
            'sidebar_logo' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/svg+xml', 'max:2048'],
            'login_logo' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/svg+xml', 'max:2048'],

            // A favicon may also be a .ico, which no other upload here accepts.
            'favicon' => ['nullable', 'file', 'mimetypes:image/png,image/x-icon,image/vnd.microsoft.icon,image/svg+xml,image/webp', 'max:512'],

            'remove_sidebar_logo' => ['nullable', 'boolean'],
            'remove_login_logo' => ['nullable', 'boolean'],
            'remove_favicon' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contact_phone.regex' => 'The contact phone may only contain digits, spaces and the characters + - ( ).',
            'whatsapp.regex' => 'The WhatsApp number may only contain digits, spaces and the characters + - ( ).',

            'sidebar_logo.mimetypes' => 'The sidebar logo must be a JPG, PNG, WebP or SVG file.',
            'login_logo.mimetypes' => 'The login logo must be a JPG, PNG, WebP or SVG file.',
            'favicon.mimetypes' => 'The favicon must be an ICO, PNG, WebP or SVG file.',
            'favicon.max' => 'The favicon must be 512 KB or smaller. A browser tab icon needs very little.',
        ];
    }
}
