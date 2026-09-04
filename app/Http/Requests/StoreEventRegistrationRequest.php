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

            // Only meaningful on the manager, and forced to false elsewhere in
            // prepareForValidation, so this only has to reject a non boolean.
            'participants.*.also_plays' => ['nullable', 'boolean'],
            'participants.*.full_name' => ['required', 'string', 'max:180'],
            // Accepts 12 digits with or without hyphens, or a passport style code.
            'participants.*.ic_number' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9-]+$/'],

            // Required from the event's setting, never from a posted flag, so a
            // tampered form cannot opt itself out of the requirement. Each of the
            // three is asked and required on its own, so they are built from the
            // event's map rather than sharing one flag.
            ...$this->ignRules($event),

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
     * Rules for the game account fields this event actually asks for.
     *
     * A field that is not asked for gets no rule at all rather than a nullable
     * one, so a value posted for a field the form never drew is simply not
     * validated and never reaches the model, which only accepts what is fillable.
     *
     * @return array<string, array<int, string>>
     */
    private function ignRules(Event $event): array
    {
        $rules = [];

        foreach ($event->ignFieldsAsked() as $field => $label) {
            $rules["participants.*.{$field}"] = [
                $event->requiresIgnField($field) ? 'required' : 'nullable',
                'string',
                'max:60',
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'participants.required' => 'Add at least one person to the registration.',
            'participants.*.ic_number.regex' => 'The identity card number may only contain letters, numbers and hyphens.',
            'logo.required' => 'This event needs a logo with the registration.',
            'logo.mimetypes' => 'The logo must be a JPG, PNG, WebP or SVG image.',
            'logo.max' => 'The logo must be no larger than 2 MB.',
            'participants.*.phone.regex' => 'The telephone number may only contain digits, spaces and the characters + - ( ).',
            'participants.*.date_of_birth.before' => 'The date of birth must be in the past.',
            'team_name.required' => 'Enter the team or organisation name.',
        ];

        // Named per field so somebody who left the Server ID blank is told that,
        // rather than being sent to hunt through a squad of six for "a game
        // account". Only the asked fields can fail, so only they get a message.
        foreach ($this->event()->ignFieldsAsked() as $field => $label) {
            $messages["participants.*.{$field}.required"] = sprintf(
                'This event needs a %s for everyone taking part.',
                $label,
            );
        }

        return $messages;
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
            'ign_name' => 'in-game name',
        ];

        foreach (range(0, 199) as $index) {
            $person = 'person ' . ($index + 1) . ' ';

            foreach ([
                'role', 'full_name', 'ic_number',
                'ign_player_id', 'ign_server_id', 'ign_name',
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
            // also_plays joins role and marketing_consent in being ignored here:
            // it too has a hidden 0 in front of it, so counting it would make an
            // untouched block look filled in.
            fn ($row) => is_array($row) && collect($row)
                ->except(['role', 'also_plays', 'marketing_consent'])
                ->filter(fn ($value) => filled($value))
                ->isNotEmpty()
        ));

        $participants = array_map(function (array $row) {
            foreach ([
                'full_name', 'ic_number', 'city', 'email', 'phone',
                'ign_player_id', 'ign_server_id', 'ign_name',
            ] as $field) {
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

            /*
            | "Also plays" belongs to the manager alone, so it is forced off for
            | anybody else. A player who is already playing does not need the flag,
            | and letting it through on their row would put a second reading of the
            | same fact into the database, where the two could disagree.
            */
            $row['also_plays'] = ($row['role'] ?? null) === ParticipantOptions::ROLE_MANAGER
                && filter_var($row['also_plays'] ?? false, FILTER_VALIDATE_BOOLEAN);

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
        $plain = $participants->where('role', ParticipantOptions::ROLE_PARTICIPANT)->count();

        /*
        | Players are counted by who holds a playing place, not by the role string.
        | A manager who chose "Manager and Player" is one row that fills one place,
        | which is the whole point of the flag: before it, the only way to put the
        | manager on the roster was a second row carrying their identity card twice.
        */
        $players = $participants
            ->filter(fn ($row) => is_array($row) && (
                ($row['role'] ?? null) === ParticipantOptions::ROLE_PLAYER
                || (
                    ($row['role'] ?? null) === ParticipantOptions::ROLE_MANAGER
                    && filter_var($row['also_plays'] ?? false, FILTER_VALIDATE_BOOLEAN)
                )
            ))
            ->count();

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

        /*
        | Zero managers is allowed. The person registering may choose "Player only",
        | which is a squad entered by one of its players rather than by a separate
        | manager. Nothing downstream breaks: every place that looks the manager up
        | already falls back to the first person on the entry, so notifications and
        | the counter still have somebody to address.
        */
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
                sprintf(
                    'Enter at least %d %s. The manager counts as one if they are playing too.',
                    $min,
                    $min === 1 ? 'player' : 'players',
                )
            );
        }

        if ($max !== null && $players > $max) {
            $validator->errors()->add(
                'participants',
                sprintf('This event allows at most %d players per entry.', $max)
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

    /**
     * Refuse an entry the event has no room for.
     *
     * Measured in places, not in people. A squad wants one place however many
     * players it names, so an event offering thirty two places to teams has room
     * for thirty two squads rather than thirty two players. Counting heads here
     * is what turned a thirty two team event away after four entries.
     */
    private function checkSeatsAvailable(Validator $validator): void
    {
        $event = $this->event();

        if ($event->seats_total <= 0) {
            return;
        }

        $named = count($this->input('participants', []));
        $wanted = $event->seatsForEntry($named);
        $left = $event->seatsLeft();

        if ($wanted <= $left) {
            return;
        }

        // A squad is one place or nothing, so naming fewer players would not help
        // and the message must not imply it would.
        if ($event->isManagerMode()) {
            $validator->errors()->add('participants', 'This event is fully booked.');

            return;
        }

        $validator->errors()->add(
            'participants',
            sprintf(
                'Only %d %s left for this event, but %d %s named.',
                $left,
                $left === 1 ? 'place is' : 'places are',
                $named,
                $named === 1 ? 'person is' : 'people are',
            )
        );
    }
}
