<?php

namespace App\Http\Requests\Admin;

use App\Models\Event;
use App\Models\EventParticipant;
use App\Support\ParticipantOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Correcting the details of one person already on an entry.
 *
 * This is not a substitution. A swap replaces who is in a place and deliberately
 * wipes the fields that described the outgoing player; this fixes a misspelt name,
 * a mistyped card number or a changed phone number for the same person.
 *
 * The rules deliberately match the public form rather than being looser. An entry
 * corrected here has to end up in a state the public form would have accepted,
 * otherwise the admin screen becomes a way to store records the event itself
 * considers invalid.
 *
 * One exception: the columns an event stopped asking for. Someone entered at a
 * counter can legitimately be missing an address, and requiring one here would
 * make their row impossible to correct at all. Those fields are required only
 * when the row already carries them.
 */
class UpdateParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route carries permission:participants.update.
        return true;
    }

    public function participant(): EventParticipant
    {
        /** @var EventParticipant $participant */
        $participant = $this->route('participant');

        return $participant;
    }

    private function event(): ?Event
    {
        return $this->participant()->registration?->event;
    }

    public function rules(): array
    {
        $event = $this->event();
        $participant = $this->participant();

        return [
            'full_name' => ['required', 'string', 'max:180'],
            'ic_number' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9-]+$/'],

            ...$this->ignRules($event),

            'date_of_birth' => ['nullable', 'date', 'before:today'],

            /*
             | Required only when the row already has one. A person entered at the
             | counter carries a card number and a phone and nothing else, and
             | demanding a full address before their misspelt name can be fixed
             | would lock their record shut.
             */
            'address_line_1' => [$this->wasGiven($participant, 'address_line_1'), 'string', 'max:180'],
            'address_line_2' => ['nullable', 'string', 'max:180'],
            'postcode' => ['nullable', 'string', 'max:12'],
            'city' => [$this->wasGiven($participant, 'city'), 'string', 'max:100'],
            'state' => [$this->wasGiven($participant, 'state'), 'string', 'max:100'],
            'country' => [$this->wasGiven($participant, 'country'), 'string', 'max:100'],

            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
            'email' => ['required', 'string', 'email:rfc', 'max:190'],

            'gender' => [
                $this->wasGiven($participant, 'gender'),
                Rule::in(array_keys(ParticipantOptions::GENDERS)),
            ],
            'race' => [
                $this->wasGiven($participant, 'race'),
                Rule::in(array_keys(ParticipantOptions::RACES)),
            ],

            'emergency_contact_name' => ['nullable', 'string', 'max:180'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
        ];
    }

    /**
     * "required" when the stored row already carries this field, "nullable" when
     * it never did.
     */
    private function wasGiven(EventParticipant $participant, string $field): string
    {
        return filled($participant->getOriginal($field)) ? 'required' : 'nullable';
    }

    /**
     * Only the game account fields this event asks for, required only where it
     * insists. Built from the event's own map so a field nobody was asked for is
     * not validated and cannot be written.
     *
     * @return array<string, array<int, string>>
     */
    private function ignRules(?Event $event): array
    {
        if ($event === null) {
            return [];
        }

        $rules = [];

        foreach ($event->ignFieldsAsked() as $field => $label) {
            $rules[$field] = [
                $event->requiresIgnField($field) ? 'required' : 'nullable',
                'string',
                'max:60',
            ];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        foreach (['full_name', 'ic_number', 'city', 'email', 'phone', 'ign_player_id', 'ign_server_id', 'ign_name'] as $field) {
            if (is_string($this->input($field))) {
                $data[$field] = trim($this->input($field));
            }
        }

        // Stored in the same punctuation-free shape the public form uses, or the
        // duplicate check below would compare 901010-11-1111 against 901010111111
        // and call them different people.
        if (isset($data['ic_number'])) {
            $data['ic_number'] = strtoupper(str_replace([' ', '-'], '', $data['ic_number']));
        }

        $this->merge($data);
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkParticipantBelongsToRegistration($validator),
            fn (Validator $validator) => $this->checkIdentityCardIsNotSomebodyElses($validator),
        ];
    }

    /**
     * The route binds the registration and the person separately, so a person id
     * from one entry could be posted against another's URL. The registration is
     * what the permission and the screen were resolved against, so it is the
     * authority here.
     */
    private function checkParticipantBelongsToRegistration(Validator $validator): void
    {
        $registration = $this->route('registration');
        $participant = $this->participant();

        if ((int) $participant->event_registration_id !== (int) $registration->id) {
            $validator->errors()->add('participant', 'That person is not on this registration.');
        }
    }

    /**
     * The same card cannot end up on two people, whether on this entry or on
     * another entry for the same event. This person is excluded, or saving a row
     * without touching the card number would fail against itself.
     */
    private function checkIdentityCardIsNotSomebodyElses(Validator $validator): void
    {
        $card = (string) $this->input('ic_number');

        if ($card === '') {
            return;
        }

        $participant = $this->participant();
        $eventId = $participant->registration?->event_id;

        $taken = EventParticipant::query()
            ->where('ic_number', $card)
            ->whereKeyNot($participant->id)
            ->when(
                $eventId !== null,
                fn ($query) => $query->whereHas(
                    'registration',
                    fn ($inner) => $inner
                        ->where('event_id', $eventId)
                        ->where('status', '!=', \App\Models\EventRegistration::STATUS_CANCELLED),
                ),
                // No event to compare against, so fall back to this entry alone.
                fn ($query) => $query->where('event_registration_id', $participant->event_registration_id),
            )
            ->exists();

        if ($taken) {
            $validator->errors()->add(
                'ic_number',
                'This identity card is already recorded against somebody else on this event.',
            );
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'ic_number.regex' => 'The identity card number may only contain letters, numbers and hyphens.',
            'phone.regex' => 'The telephone number may only contain digits, spaces and the characters + - ( ).',
            'emergency_contact_phone.regex' => 'The emergency contact number may only contain digits, spaces and the characters + - ( ).',
            'date_of_birth.before' => 'The date of birth must be in the past.',
        ];

        foreach ($this->event()?->ignFieldsAsked() ?? [] as $field => $label) {
            $messages["{$field}.required"] = sprintf('This event needs a %s.', $label);
        }

        return $messages;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'ic_number' => 'identity card',
            'ign_player_id' => 'Player ID',
            'ign_server_id' => 'Server ID',
            'ign_name' => 'in-game name',
            'address_line_1' => 'address',
            'emergency_contact_name' => 'emergency contact name',
            'emergency_contact_phone' => 'emergency contact number',
        ];
    }

    /**
     * Failures go back to the detail screen with the dialog reopened for this
     * person, so the messages arrive attached to the form that produced them.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.event.participants.show', $this->route('registration'))
            . '#person-' . $this->participant()->id;
    }
}
