<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Models\EventParticipant;
use App\Support\AddonOrder;
use App\Support\ParticipantOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEventRegistrationRequest extends FormRequest
{
    private ?AddonOrder $addonOrder = null;

    public function authorize(): bool
    {
        return true;
    }

    public function event(): Event
    {
        return $this->route('event');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $event = $this->event();

        return [
            'team_name' => [$event->isManagerMode() ? 'required' : 'nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],

            // One image for the whole entry. Required from the event's setting
            // rather than a posted flag, so a tampered form cannot skip it.
            // SVG is allowed through mimetypes because it is not a raster image
            // and would fail the 'image' rule.
            'logo' => [
                $event->requiresLogo() ? 'required' : 'nullable',
                'file',
                'mimetypes:image/jpeg,image/png,image/webp,image/svg+xml',
                'max:2048',
            ],

            'participants' => ['required', 'array', 'min:1', 'max:200'],
            // The mode decides which roles exist, so anything outside that set
            // is a tampered payload rather than a user mistake.
            'participants.*.role' => ['required', Rule::in(array_keys($event->allowedParticipantRoles()))],
            'participants.*.full_name' => ['required', 'string', 'max:180'],
            // Accepts 12 digits with or without hyphens, or a passport style code.
            'participants.*.ic_number' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9-]+$/'],

            // Required from the event's setting, never from a posted flag, so a
            // tampered form cannot opt itself out of the requirement.
            'participants.*.ign_player_id' => [$event->requiresIgn() ? 'required' : 'nullable', 'string', 'max:60'],
            'participants.*.ign_server_id' => [$event->requiresIgn() ? 'required' : 'nullable', 'string', 'max:60'],

            'participants.*.date_of_birth' => ['nullable', 'date', 'before:today'],
            'participants.*.address_line_1' => ['required', 'string', 'max:180'],
            'participants.*.address_line_2' => ['nullable', 'string', 'max:180'],
            'participants.*.postcode' => ['nullable', 'string', 'max:12'],
            'participants.*.city' => ['required', 'string', 'max:100'],
            'participants.*.state' => ['required', 'string', 'max:100'],
            'participants.*.country' => ['required', 'string', 'max:100'],
            'participants.*.phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
            'participants.*.email' => ['required', 'string', 'email:rfc', 'max:190'],
            // Optional and defaulted to no. An entry is not an agreement to be
            // marketed at, so the absence of an answer is a refusal.
            'participants.*.marketing_consent' => ['nullable', 'boolean'],

            'participants.*.gender' => ['required', Rule::in(array_keys(ParticipantOptions::GENDERS))],
            'participants.*.race' => ['required', Rule::in(array_keys(ParticipantOptions::RACES))],
            'participants.*.emergency_contact_name' => ['nullable', 'string', 'max:180'],
            'participants.*.emergency_contact_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],

            // Shape only. What the quantities mean, and what they cost, is
            // settled against the database by AddonOrder.
            'addons' => ['nullable', 'array'],
            'addons.*' => ['array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'participants.required' => 'Add at least one person to the registration.',
            'participants.*.ic_number.regex' => 'The identity card number may only contain letters, numbers and hyphens.',
            'participants.*.ign_player_id.required' => 'This event needs a Player ID for everyone taking part.',
            'participants.*.ign_server_id.required' => 'This event needs a Server ID for everyone taking part.',
            'logo.required' => 'This event needs a logo with the registration.',
            'logo.mimetypes' => 'The logo must be a JPG, PNG, WebP or SVG image.',
            'logo.max' => 'The logo must be no larger than 2 MB.',
            'participants.*.phone.regex' => 'The telephone number may only contain digits, spaces and the characters + - ( ).',
            'participants.*.date_of_birth.before' => 'The date of birth must be in the past.',
            'team_name.required' => 'Enter the team or organisation name.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $labels = [];

        // Turns "participants.2.full_name" into "person 3 full name" so a
        // failed field is findable on a long form.
        // Fields whose column name does not read well once the underscores are
        // stripped, so they get a written label instead.
        $spelled = [
            'ic_number' => 'identity card',
            'ign_player_id' => 'Player ID',
            'ign_server_id' => 'Server ID',
        ];

        foreach (range(0, 199) as $index) {
            $person = 'person ' . ($index + 1) . ' ';

            foreach ([
                'role', 'full_name', 'ic_number', 'ign_player_id', 'ign_server_id',
                'date_of_birth', 'address_line_1',
                'address_line_2', 'postcode', 'city', 'state', 'country', 'phone',
                'email', 'gender', 'race', 'emergency_contact_name', 'emergency_contact_phone',
            ] as $field) {
                $labels["participants.{$index}.{$field}"] = $person
                    . ($spelled[$field] ?? str_replace('_', ' ', $field));
            }
        }

        return $labels;
    }

    protected function prepareForValidation(): void
    {
        $participants = $this->input('participants', []);

        if (! is_array($participants)) {
            return;
        }

        /*
        | Drop rows the visitor added then left completely blank, so an extra
        | empty player block does not fail the whole submission.
        |
        | role and marketing_consent are excluded from the emptiness test because
        | both always arrive: role is set by the markup, and the consent checkbox
        | is preceded by a hidden "0" so that an untick is recorded as a no. Since
        | filled('0') is true, counting it would make every blank block look filled
        | and fail the submission with errors about fields nobody touched.
        */
        $participants = array_values(array_filter(
            $participants,
            fn ($row) => is_array($row) && collect($row)
                ->except(['role', 'marketing_consent'])
                ->filter(fn ($value) => filled($value))
                ->isNotEmpty()
        ));

        $participants = array_map(function (array $row) {
            foreach (['full_name', 'ic_number', 'city', 'email', 'phone', 'ign_player_id', 'ign_server_id'] as $field) {
                if (isset($row[$field]) && is_string($row[$field])) {
                    $row[$field] = trim($row[$field]);
                }
            }

            // Normalised to a real boolean here rather than trusted from the
            // form. The hidden 0 that sits before the checkbox means something
            // always arrives, but it arrives as the string "0" or "1".
            $row['marketing_consent'] = filter_var(
                $row['marketing_consent'] ?? false,
                FILTER_VALIDATE_BOOLEAN,
            );

            // Identity cards are compared without punctuation, so they are
            // stored in one consistent shape.
            if (isset($row['ic_number']) && is_string($row['ic_number'])) {
                $row['ic_number'] = strtoupper(str_replace([' ', '-'], '', $row['ic_number']));
            }

            return $row;
        }, $participants);

        $this->merge(['participants' => $participants]);
    }

    /**
     * Rules that need the event and the whole participant list together.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkRegistrationStillOpen($validator),
            fn (Validator $validator) => $this->checkModeShape($validator),
            fn (Validator $validator) => $this->checkDuplicateIdentityCards($validator),
            fn (Validator $validator) => $this->checkSeatsAvailable($validator),
            fn (Validator $validator) => $this->checkAddons($validator),
        ];
    }

    /**
     * Add-on order for this submission, priced from the database.
     *
     * Memoised so validation and the controller work from one result.
     */
    public function addonOrder(): AddonOrder
    {
        return $this->addonOrder ??= AddonOrder::build(
            $this->event()->loadMissing('addons.variants'),
            $this->input('addons'),
        );
    }

    private function checkAddons(Validator $validator): void
    {
        foreach ($this->addonOrder()->errors as $path => $message) {
            $validator->errors()->add($path, $message);
        }
    }

    /**
     * The gate is re-checked here because the page may have been open for a
     * while before the form was submitted.
     */
    private function checkRegistrationStillOpen(Validator $validator): void
    {
        $reason = $this->event()->registrationBlockedReason();

        if ($reason !== null) {
            $validator->errors()->add('event', $reason);
        }
    }

    private function checkModeShape(Validator $validator): void
    {
        $event = $this->event();
        $participants = collect($this->input('participants', []));

        if ($participants->isEmpty()) {
            return;
        }

        $managers = $participants->where('role', ParticipantOptions::ROLE_MANAGER)->count();
        $players = $participants->where('role', ParticipantOptions::ROLE_PLAYER)->count();
        $plain = $participants->where('role', ParticipantOptions::ROLE_PARTICIPANT)->count();

        if (! $event->isManagerMode()) {
            if ($participants->count() > 1) {
                $validator->errors()->add(
                    'participants',
                    'This event takes one person per registration. Submit a separate registration for anyone else.'
                );
            }

            // An individual entry carries no manager or player distinction.
            if ($managers > 0 || $players > 0) {
                $validator->errors()->add(
                    'participants',
                    'This event does not use manager or player roles.'
                );
            }

            return;
        }

        if ($managers < 1) {
            $validator->errors()->add('participants', 'A manager registration needs exactly one manager.');
        }

        if ($managers > 1) {
            $validator->errors()->add('participants', 'Only one person can be the manager.');
        }

        // Guards against an individual payload being posted at a squad event.
        if ($plain > 0) {
            $validator->errors()->add(
                'participants',
                'This event registers a squad, so each person must be the manager or a player.'
            );
        }

        [$min, $max] = $event->playerBounds();

        if ($players < $min) {
            $validator->errors()->add(
                'participants',
                sprintf('Enter at least %d %s.', $min, $min === 1 ? 'player' : 'players')
            );
        }

        if ($max !== null && $players > $max) {
            $validator->errors()->add(
                'participants',
                sprintf('This event allows at most %d players per manager.', $max)
            );
        }
    }

    /**
     * The same identity card cannot appear twice in this submission, nor on an
     * earlier registration for the same event.
     */
    private function checkDuplicateIdentityCards(Validator $validator): void
    {
        $cards = collect($this->input('participants', []))
            ->pluck('ic_number')
            ->filter()
            ->values();

        $duplicates = $cards->duplicates();

        foreach ($duplicates as $index => $card) {
            $validator->errors()->add(
                "participants.{$index}.ic_number",
                'This identity card is already listed on this registration.'
            );
        }

        $alreadyRegistered = EventParticipant::query()
            ->whereIn('ic_number', $cards->unique()->all())
            ->whereHas('registration', fn ($query) => $query
                ->where('event_id', $this->event()->id)
                ->where('status', '!=', \App\Models\EventRegistration::STATUS_CANCELLED))
            ->pluck('ic_number')
            ->unique();

        foreach ($cards as $index => $card) {
            if ($alreadyRegistered->contains($card)) {
                $validator->errors()->add(
                    "participants.{$index}.ic_number",
                    'This identity card is already registered for this event.'
                );
            }
        }
    }

    private function checkSeatsAvailable(Validator $validator): void
    {
        $event = $this->event();

        if ($event->seats_total <= 0) {
            return;
        }

        $requested = count($this->input('participants', []));

        if ($requested > $event->seatsLeft()) {
            $validator->errors()->add(
                'participants',
                sprintf(
                    'Only %d %s left for this event, but %d %s named.',
                    $event->seatsLeft(),
                    $event->seatsLeft() === 1 ? 'place is' : 'places are',
                    $requested,
                    $requested === 1 ? 'person is' : 'people are',
                )
            );
        }
    }
}
