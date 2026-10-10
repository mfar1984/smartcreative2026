<?php

namespace App\Http\Requests\Admin;

use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Correcting the entry itself: the team's name, its logo, and the note on it.
 *
 * Only those three. The rest of what the Entry panel shows is either derived or
 * has its own act:
 *
 * The reference is how this entry is quoted in every email, receipt and gateway
 * record already sent. Renaming it would orphan all of them.
 *
 * The event has its own screen, because moving one changes the seat count on two
 * events, rewrites the fee, and may need people added or left behind.
 *
 * The mode is the event's, not the entry's. People, and when it was submitted,
 * are facts about what happened.
 */
class UpdateRegistrationEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route carries permission:participants.update.
        return true;
    }

    public function registration(): EventRegistration
    {
        /** @var EventRegistration $registration */
        $registration = $this->route('registration');

        return $registration;
    }

    private function event(): ?Event
    {
        return $this->registration()->event;
    }

    public function rules(): array
    {
        $event = $this->event();
        $registration = $this->registration();

        return [
            /*
             | Compulsory on a squad entry for the same reason the public form
             | insists: it is the name everything from the draw to the counter
             | calls this entry by. An individual entry has no team.
             |
             | Unless the event stopped asking for one, in which case the public form
             | accepted the entry without a name and this screen cannot start demanding
             | it. The stored mode is tested first because it is what the screen displays;
             | an entry whose event has gone falls back to insisting, as it always did.
             */
            'team_name' => [
                in_array($registration->mode, [Event::MODE_MANAGER, Event::MODE_GROUPING], true)
                && ($event?->usesGroupName() ?? true)
                    ? 'required'
                    : 'nullable',
                'string',
                'max:150',
            ],

            /*
             | Optional even on an event that demands one, as long as there already
             | is one: leaving the field empty means "keep what is there".
             |
             | The same raster-only set as the public form, and for the same reason.
             | Both write into registration-logos and both are linked from the admin
             | screen for an operator to open full size, so accepting an SVG here
             | would leave that door open to anybody holding registrations.update.
             */
            'logo' => [
                'nullable',
                'file',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:2048',
            ],

            'remove_logo' => ['nullable', 'boolean'],

            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'team_name' => is_string($this->input('team_name')) ? trim($this->input('team_name')) : $this->input('team_name'),
            'notes' => is_string($this->input('notes')) ? trim($this->input('notes')) : $this->input('notes'),
            'remove_logo' => filter_var($this->input('remove_logo', false), FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkTheEventStillHasItsLogo($validator),
        ];
    }

    /**
     * An event that insists on a logo cannot be left without one.
     *
     * Two ways to end up there: ticking remove with nothing replacing it, or
     * removing and uploading in the same press, which is a replacement and is
     * fine. Only the first is refused.
     */
    private function checkTheEventStillHasItsLogo(Validator $validator): void
    {
        $event = $this->event();

        if ($event === null || ! $event->requiresLogo()) {
            return;
        }

        if (! $this->boolean('remove_logo') || $this->hasFile('logo')) {
            return;
        }

        $validator->errors()->add('remove_logo', sprintf(
            '%s needs a %s, so it cannot be taken away. Upload a replacement instead.',
            $event->title,
            strtolower($event->logoLabel()),
        ));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'team_name.required' => 'Enter the team, group or organisation name.',
            // Matches the rule, which no longer takes an SVG. See the note on it.
            'logo.mimetypes' => 'The image must be a JPG, PNG or WebP.',
            'logo.max' => 'The image must be no larger than 2 MB.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'team_name' => 'team name',
            'logo' => strtolower($this->event()?->logoLabel() ?? 'logo'),
        ];
    }

    /**
     * Failures go back to the detail screen with the dialog reopened, so the
     * messages arrive attached to the form that produced them.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.event.participants.show', $this->registration()).'#entry';
    }
}
