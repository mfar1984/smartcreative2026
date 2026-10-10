<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
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
            'team_name' => [$event->usesGroupName() ? 'required' : 'nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],

            /*
             | A coupon code, when the visitor had one.
             |
             | Deliberately only a shape check. Whether the code exists, is for this
             | event, has expired or has already gone is decided at claim time under a
             | lock, and a validation failure here would throw the whole entry out
             | over a coupon. A bad code must cost the normal price, not the place.
             */
            'voucher_code' => ['nullable', 'string', 'max:64'],

            /*
             | One image for the whole entry. Required from the event's setting
             | rather than a posted flag, so a tampered form cannot skip it.
             |
             | mimetypes reads the file's own bytes rather than its extension, so a
             | script called crest.png is refused here.
             |
             | Raster only, and SVG is refused on purpose. An SVG is a document, not a
             | picture: it may carry <script>, and the stored file sits on the public
             | disk under a URL on this domain. The admin screen links it with
             | target="_blank" so an operator can see the full size image, and opening
             | one as a top level document is all it would take to run the uploader's
             | script with the operator's session. This form is open to anybody on the
             | internet, so that is not a risk worth a vector crest.
             */
            'logo' => [
                $event->requiresLogo() ? 'required' : 'nullable',
                'file',
                'mimetypes:image/jpeg,image/png,image/webp',
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
            /*
             | Required from the event's own setting, never from a posted flag.
             |
             | Both have been unconditionally required here for as long as this form has
             | existed, and the defaults on those columns are true, so an event that has
             | never been touched behaves exactly as it always did. The setting exists to
             | let one be relaxed where it does not apply, not to weaken the norm.
             */
            'participants.*.phone' => [
                $event->requiresPhone() ? 'required' : 'nullable',
                'string',
                'max:30',
                'regex:/^[0-9+\-\s()]+$/',
            ],
            'participants.*.email' => [
                $event->requiresEmail() ? 'required' : 'nullable',
                'string',
                'email:rfc',
                'max:190',
            ],

            ...$this->icRules($event),
            // Optional and defaulted to no. An entry is not an agreement to be
            // marketed at, so the absence of an answer is a refusal.
            'participants.*.marketing_consent' => ['nullable', 'boolean'],

            /*
             | The organiser's own questions, keyed by question id. Shape only here;
             | which of them had to be ticked is settled in checkAnswers() against
             | the database, because a posted "this one was optional" would be
             | worth nothing.
             */
            'participants.*.answers' => ['nullable', 'array'],
            'participants.*.answers.*' => ['nullable', 'boolean'],

            /*
             | Per-person item input may be either a radio variant id or a
             | [variantId => quantity] map. AddonOrder validates the configured
             | shape, catalogue ownership, stock and limits against the database.
             */
            'participants.*.addons' => ['nullable', 'array'],
            'participants.*.addons.*' => ['nullable'],

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
     * Rules for the two identity card photographs, when the event asks for them.
     *
     * No rule at all when it does not, matching how the game account fields are handled: a
     * file posted for something the form never drew is simply not validated and never
     * reaches storage.
     *
     * mimetypes rather than the `image` rule, because it is checked against what the file
     * actually contains rather than what it is called. A scan of a card is a photograph, so
     * PDF is not accepted: it would be a second format to render, and it is the one people
     * use to attach a whole document rather than one side.
     *
     * @return array<string, array<int, string>>
     */
    private function icRules(Event $event): array
    {
        if (! $event->requiresIcAttachment()) {
            return [];
        }

        /*
         | Two megabytes, which is generous for a photograph of a card and deliberately
         | not more.
         |
         | A squad of six sends twelve of these in one request, plus the logo. At four
         | megabytes each that is fifty megabytes in a single POST, which exceeds
         | post_max_size on most shared hosting — and when that limit is passed PHP
         | discards the entire body, so the form comes back with every field empty and no
         | explanation. A smaller cap per file is what keeps the total inside a limit this
         | application cannot see.
         */
        $rules = ['file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:2048'];

        return [
            'participants.*.ic_front' => ['required', ...$rules],
            'participants.*.ic_back' => ['required', ...$rules],
        ];
    }

    /**
     * Refuse before PHP silently drops half the uploads.
     *
     * max_file_uploads caps how many files arrive in one request, and the ones over the
     * limit are not rejected: they are simply absent. Validation then reports a missing
     * identity card for whoever happened to fall off the end, which sends the registrant
     * hunting for a file they did attach.
     *
     * Saying so up front turns an inexplicable failure into a limit with a number on it.
     * The only real fix is on the server, so the message points there rather than
     * pretending the entry could be corrected.
     */
    private function checkUploadCapacity(Validator $validator): void
    {
        if (! $this->event()->requiresIcAttachment()) {
            return;
        }

        $people = count((array) $this->input('participants', []));

        // Two photographs each, and the logo shares the same allowance.
        $needed = ($people * 2) + 1;
        $allowed = (int) ini_get('max_file_uploads');

        if ($allowed <= 0 || $needed <= $allowed) {
            return;
        }

        $validator->errors()->add('participants', sprintf(
            'This entry needs %d file uploads and this server accepts %d in one submission. Register fewer people at a time, or ask the organiser to raise max_file_uploads.',
            $needed,
            $allowed,
        ));
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
            // Says what the rule above now accepts. It used to offer SVG, which is
            // refused now and for good reason, and a message offering the one format
            // the form will not take is how somebody tries the same file three times.
            'logo.mimetypes' => 'The logo must be a JPG, PNG or WebP image.',
            'logo.max' => 'The logo must be no larger than 2 MB.',
            'participants.*.phone.regex' => 'The telephone number may only contain digits, spaces and the characters + - ( ).',
            'participants.*.date_of_birth.before' => 'The date of birth must be in the past.',
            'team_name.required' => 'Enter the team or organisation name.',

            'participants.*.ic_front.required' => 'Attach the front of this person\'s identity card.',
            'participants.*.ic_back.required' => 'Attach the back of this person\'s identity card.',
            'participants.*.ic_front.mimetypes' => 'The identity card photograph must be a JPG, PNG or WebP image.',
            'participants.*.ic_back.mimetypes' => 'The identity card photograph must be a JPG, PNG or WebP image.',
            'participants.*.ic_front.max' => 'Each identity card photograph must be no larger than 4 MB.',
            'participants.*.ic_back.max' => 'Each identity card photograph must be no larger than 4 MB.',
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
            $person = 'person '.($index + 1).' ';

            foreach ([
                'role', 'full_name', 'ic_number',
                'ign_player_id', 'ign_server_id', 'ign_name',
                'date_of_birth', 'address_line_1',
                'address_line_2', 'postcode', 'city', 'state', 'country', 'phone',
                'email', 'gender', 'race', 'emergency_contact_name', 'emergency_contact_phone',
            ] as $field) {
                $labels["participants.{$index}.{$field}"] = $person
                    .($spelled[$field] ?? str_replace('_', ' ', $field));
            }
        }

        return $labels;
    }

    /**
     * Renumber the participant file uploads to match the rows that survived filtering.
     *
     * Called from prepareForValidation, which is the last moment before the validator
     * reads all() and merges files over the input. Anything later is too late: by then the
     * mismatch has already become a validation error against the wrong person.
     *
     * Rows whose data was dropped lose their files with them. That is correct — a block the
     * registrant blanked out is not a person, and keeping its attachments would leave
     * identity documents on disk belonging to nobody on the entry.
     *
     * @param  array<int, array-key>  $keys  the form's own row keys, in order, that survived
     */
    private function realignParticipantFiles(array $keys): void
    {
        $files = $this->files->all();

        if (! isset($files['participants']) || ! is_array($files['participants'])) {
            return;
        }

        $original = $files['participants'];
        $aligned = [];

        foreach (array_values($keys) as $position => $key) {
            if (isset($original[$key])) {
                $aligned[$position] = $original[$key];
            }
        }

        $files['participants'] = $aligned;

        $this->files->replace($files);
    }

    protected function prepareForValidation(): void
    {
        $event = $this->event();

        /*
        | An event that does not ask for a group name must not be given one.
        |
        | The form never draws the field when the setting is off, so anything arriving
        | under that name was put there by hand. Cleared rather than rejected, because it
        | is not the registrant's mistake and there is nothing for them to correct.
        |
        | Gated on the mode so an individual entry, which has never carried a name, is not
        | touched at all.
        */
        if ($event->allowsMultipleParticipants() && ! $event->usesGroupName()) {
            $this->merge(['team_name' => null]);
        }

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
        $kept = array_filter(
            $participants,
            // also_plays joins role and marketing_consent in being ignored here:
            // it too has a hidden 0 in front of it, so counting it would make an
            // untouched block look filled in.
            fn ($row) => is_array($row) && collect($row)
                ->except(['role', 'also_plays', 'marketing_consent'])
                ->filter(fn ($value) => filled($value))
                ->isNotEmpty()
        );

        /*
        | Move the uploaded files onto the same row numbers as the data.
        |
        | Re-indexing the rows below would otherwise part each person from their own
        | attachments, because $_FILES is not touched by merge() and keeps the numbering
        | the browser sent. Leave a blank block in the middle of a squad and the result is
        | that one competitor is told to attach an identity card they did attach, a row
        | with files and no name appears out of nowhere, and — if the two were ever paired
        | by position instead of by key — one person's identity document is filed under
        | another person's name. That last one is the reason this is done here rather than
        | worked around in the controller.
        */
        $this->realignParticipantFiles(array_keys($kept));

        $participants = array_values($kept);

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
            fn (Validator $validator) => $this->checkUniqueContact($validator),
            fn (Validator $validator) => $this->checkUploadCapacity($validator),
            fn (Validator $validator) => $this->checkSeatsAvailable($validator),
            fn (Validator $validator) => $this->checkAddons($validator),
            fn (Validator $validator) => $this->checkAnswers($validator),
        ];
    }

    /**
     * Refuse the form until every compulsory question is ticked, by everybody.
     *
     * Checked here rather than trusted from the markup. The required attribute on
     * the box is a convenience for whoever is filling the form in, and anybody can
     * delete it from their own browser in seconds, so it cannot be the thing that
     * enforces a term.
     *
     * Read from the event's own questions, not from the posted keys, so a
     * submission that simply omits a question it did not like is refused rather
     * than passing by absence.
     */
    private function checkAnswers(Validator $validator): void
    {
        $required = $this->event()->questions->where('is_required', true);

        if ($required->isEmpty()) {
            return;
        }

        $participants = (array) $this->input('participants', []);

        foreach ($participants as $index => $person) {
            $answers = (array) ($person['answers'] ?? []);

            foreach ($required as $question) {
                if (filter_var($answers[$question->id] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }

                /*
                 | Named per person and per question, so the message lands on the box
                 | that needs ticking rather than at the top of a long form where
                 | nobody can tell which of seven people it refers to.
                 */
                $validator->errors()->add(
                    "participants.{$index}.answers.{$question->id}",
                    sprintf('"%s" has to be ticked to continue.', $question->title),
                );
            }
        }
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
            // Per person choices arrive inside each person's block, so the people
            // have to be handed over as well as the shared quantities.
            (array) $this->input('participants', []),
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

        if (! $event->allowsMultipleParticipants()) {
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

        if ($event->isGroupingMode()) {
            // A grouping entry contains ordinary participants only. The first row
            // is the group contact as well as Participant 1, not a squad manager.
            if ($managers > 0 || $players > 0 || $plain !== $participants->count()) {
                $validator->errors()->add(
                    'participants',
                    'A grouping registration uses participant roles only.'
                );
            }

            [$min, $max] = $event->playerBounds();
            $count = $participants->count();

            if ($count < $min) {
                $validator->errors()->add(
                    'participants',
                    sprintf('Enter at least %d %s for this group.', $min, $min === 1 ? 'participant' : 'participants')
                );
            }

            if ($max !== null && $count > $max) {
                $validator->errors()->add(
                    'participants',
                    sprintf('This event allows at most %d participants per group.', $max)
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
    /**
     * One email address and one telephone number per person, across the whole event.
     *
     * Only when the event asks for it, because it refuses entries that the rules as they
     * stand would accept, and that is a decision for an organiser rather than something
     * that should arrive with an upgrade.
     *
     * The rule exists because of what contact detail is now used for. It was harmless for a
     * manager to put his own address on all five of his players while it was only ever used
     * to reach whoever registered. It stopped being harmless once every competitor gets
     * their own Wi-Fi login sent to the address on their row: five logins arrive in the
     * manager's inbox and the five players get nothing.
     *
     * Checked in two directions, and both are needed. Within the submission, because a
     * squad is entered in one go and the database cannot see a clash that has not been
     * saved yet. Against the event, because the second team to register would otherwise sail
     * past a number the first one already used.
     *
     * The wording never says who holds the value. Whoever is filling this form in is not
     * necessarily entitled to know that somebody else on the event used this address, and
     * "already used by Ahmad" would be telling them.
     */
    private function checkUniqueContact(Validator $validator): void
    {
        if (! $this->event()->requiresUniqueContact()) {
            return;
        }

        $participants = (array) $this->input('participants', []);

        foreach ([
            'email' => 'email address',
            'phone' => 'telephone number',
        ] as $field => $label) {
            /*
             | Compared case-insensitively for email and with punctuation stripped for
             | phone numbers.
             |
             | Otherwise the rule is trivially defeated by typing the same address in
             | different case, or the same number with and without hyphens, which is not
             | somebody being clever — it is what happens naturally when six rows are
             | filled in by one person from memory.
             */
            $values = collect($participants)
                ->map(fn ($person) => $this->normaliseContact($field, is_array($person) ? ($person[$field] ?? null) : null))
                ->filter()
                ->values();

            foreach ($values->duplicates() as $index => $value) {
                $validator->errors()->add(
                    "participants.{$index}.{$field}",
                    sprintf('This event needs a different %s for each person, and this one is already used above.', $label),
                );
            }

            if ($values->isEmpty()) {
                continue;
            }

            /*
             | Normalised on both sides, which means the comparison happens in PHP rather
             | than in SQL.
             |
             | A LOWER() or REPLACE() in the where clause would not use an index, and this
             | runs while somebody is waiting on a form. Pulling one column for one event is
             | a few hundred rows at worst.
             */
            $taken = EventParticipant::query()
                ->whereHas('registration', fn ($query) => $query
                    ->where('event_id', $this->event()->id)
                    ->where('status', '!=', EventRegistration::STATUS_CANCELLED))
                ->pluck($field)
                ->map(fn ($value) => $this->normaliseContact($field, $value))
                ->filter()
                ->unique();

            if ($taken->isEmpty()) {
                continue;
            }

            foreach ($values as $index => $value) {
                if ($taken->contains($value)) {
                    $validator->errors()->add(
                        "participants.{$index}.{$field}",
                        sprintf('This %s is already registered for this event. Each person needs their own.', $label),
                    );
                }
            }
        }
    }

    /**
     * The comparable form of a contact value.
     *
     * Email lower-cased, because addresses are not case sensitive in practice and nobody
     * intends Ali@x.com and ali@x.com to be two people. Telephone reduced to digits, so
     * 012-345 6789 and 0123456789 are recognised as the one number they are.
     */
    private function normaliseContact(string $field, mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        if ($field === 'phone') {
            $digits = preg_replace('/\D+/', '', $value) ?? '';

            return $digits === '' ? null : $digits;
        }

        return mb_strtolower(trim($value));
    }

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
