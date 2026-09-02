<?php

namespace App\Http\Requests\Admin;

use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Support\ParticipantOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Substituting a different person into a squad place at the counter.
 *
 * Only the name, identity card and phone are required. A card handed over at a
 * queue gives those three; nobody types a postal address into a form with people
 * waiting behind them, and copying the outgoing player's address onto their
 * replacement would be worse than leaving it blank.
 */
class SwapPlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route already carries permission:attendance.update
        return true;
    }

    public function participant(): EventParticipant
    {
        return $this->route('participant');
    }

    /**
     * Where a rejected submission lands.
     *
     * Named explicitly rather than left to back(), which follows the Referer
     * header. A counter whose browser withholds that header would otherwise be
     * dropped onto an empty desk with the squad closed and their typing gone,
     * which is the worst possible moment for it.
     */
    protected function getRedirectUrl(): string
    {
        $registrationId = $this->participant()
            ->loadMissing('registration')
            ->registration?->id;

        return $this->redirector->getUrlGenerator()->route('admin.event.attendance', array_filter([
            'tab' => 'attendance',
            'registration' => $registrationId,
        ]));
    }

    /**
     * The event behind this participant, or null when it cannot be reached.
     */
    private function swapEvent(): ?\App\Models\Event
    {
        return $this->participant()
            ->loadMissing('registration.event')
            ->registration?->event;
    }

    /**
     * Whether this event asks each person for any game account field.
     */
    public function eventAsksIgn(): bool
    {
        return (bool) $this->swapEvent()?->asksIgn();
    }

    /**
     * Rules for the game account fields this event asks for.
     *
     * @return array<string, array<int, string>>
     */
    private function ignRules(): array
    {
        $event = $this->swapEvent();
        $rules = [];

        foreach ($event?->ignFieldsAsked() ?? [] as $field => $label) {
            $rules[$field] = [
                $event->requiresIgnField($field) ? 'required' : 'nullable',
                'string',
                'max:60',
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:180'],

            // Same shape the public form accepts: 12 digits with or without
            // hyphens, or a passport style code.
            'ic_number' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9-]+$/'],

            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],

            // A tournament needs to know which account is playing, and the
            // outgoing player's account is not it. Each field follows the event's
            // own setting, so a substitute is asked for exactly what the public
            // form asked the person they are replacing.
            ...$this->ignRules(),

            'email' => ['nullable', 'string', 'email:rfc', 'max:190'],
            'gender' => ['nullable', Rule::in(array_keys(ParticipantOptions::GENDERS))],
            'race' => ['nullable', Rule::in(array_keys(ParticipantOptions::RACES))],
            'date_of_birth' => ['nullable', 'date', 'before:today'],

            'reason' => ['nullable', 'string', 'max:255'],

            // Only meaningful when the card turns out to belong to another team.
            // Validated as a boolean so a tampered value cannot slip through as
            // a truthy string.
            'confirm_transfer' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'full_name.required' => 'Enter the name on the identity card.',
            'ic_number.required' => 'Enter the identity card number.',
            'ic_number.regex' => 'The identity card number may only contain letters, numbers and hyphens.',
            'phone.required' => 'Enter a contact number for the person taking this place.',
            'phone.regex' => 'The telephone number may only contain digits, spaces and the characters + - ( ).',
            'date_of_birth.before' => 'The date of birth must be in the past.',
        ];

        foreach ($this->swapEvent()?->ignFieldsAsked() ?? [] as $field => $label) {
            $messages["{$field}.required"] = sprintf(
                'This event needs the %s of whoever is taking the place.',
                $label,
            );
        }

        return $messages;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'full_name' => is_string($this->full_name) ? trim($this->full_name) : $this->full_name,

            // Stored in one consistent shape so comparisons against existing
            // cards do not miss on punctuation alone.
            'ic_number' => is_string($this->ic_number)
                ? strtoupper(str_replace([' ', '-'], '', trim($this->ic_number)))
                : $this->ic_number,

            'phone' => is_string($this->phone) ? trim($this->phone) : $this->phone,
            'email' => filled($this->email) ? trim((string) $this->email) : null,
            'ign_player_id' => filled($this->ign_player_id) ? trim((string) $this->ign_player_id) : null,
            'ign_server_id' => filled($this->ign_server_id) ? trim((string) $this->ign_server_id) : null,
            'ign_name' => filled($this->ign_name) ? trim((string) $this->ign_name) : null,
        ]);
    }

    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkSlotIsOpen($validator),
            fn (Validator $validator) => $this->checkCardNotAlreadyEntered($validator),
        ];
    }

    /**
     * Re-checked here because the button was drawn before this request ran, and
     * another counter may have checked the player in since.
     */
    private function checkSlotIsOpen(Validator $validator): void
    {
        $reason = $this->participant()->swapBlockedReason();

        if ($reason !== null) {
            $validator->errors()->add('participant', $reason);
        }
    }

    /**
     * Whoever already holds this card elsewhere in the event, if anyone.
     *
     * Cached because both the validator and the controller ask for it, and it is
     * the same answer within one request.
     */
    private ?EventParticipant $existing = null;
    private bool $existingResolved = false;

    public function existingEntry(): ?EventParticipant
    {
        if ($this->existingResolved) {
            return $this->existing;
        }

        $this->existingResolved = true;

        $participant = $this->participant();
        $card = $this->input('ic_number');

        if (blank($card)) {
            return $this->existing = null;
        }

        $eventId = $participant->loadMissing('registration')->registration?->event_id;

        if ($eventId === null) {
            return $this->existing = null;
        }

        return $this->existing = EventParticipant::query()
            ->where('ic_number', $card)
            // The place being filled is excluded, so re-entering the same card
            // is a no-op rather than a clash with itself.
            ->whereKeyNot($participant->id)
            ->whereHas('registration', fn ($query) => $query
                ->where('event_id', $eventId)
                ->where('status', '!=', EventRegistration::STATUS_CANCELLED))
            ->with(['registration', 'attendance'])
            ->first();
    }

    /** Whether this submission is moving somebody off another team. */
    public function isTransfer(): bool
    {
        return $this->existingEntry() !== null;
    }

    public function transferConfirmed(): bool
    {
        return $this->boolean('confirm_transfer');
    }

    /**
     * A card already in this event is a transfer, not a mistake.
     *
     * The person can only be in one place, so filling this slot with them means
     * vacating the one they hold. That is a real consequence for a team that is
     * not standing at the desk, so it is never done on a typed card alone: the
     * counter is told whose place it would empty and has to say yes.
     */
    private function checkCardNotAlreadyEntered(Validator $validator): void
    {
        $existing = $this->existingEntry();

        if ($existing === null) {
            return;
        }

        // Already through the door as their old team. Letting them move now would
        // leave an arrival recorded against a place they no longer hold.
        if ($existing->attendance !== null) {
            $validator->errors()->add('ic_number', sprintf(
                '%s has already checked in with %s, so they cannot be moved. Undo that check-in first if this is right.',
                $existing->full_name,
                $existing->registration?->displayName() ?? 'another entry',
            ));

            return;
        }

        // The manager of another entry is its account holder, not a squad member
        // to be moved out from under it.
        if ($existing->isManager()) {
            $validator->errors()->add('ic_number', sprintf(
                'That card belongs to %s, who is the manager of %s. A manager cannot be transferred out of their own entry.',
                $existing->full_name,
                $existing->registration?->displayName() ?? 'another entry',
            ));

            return;
        }

        if (! $this->transferConfirmed()) {
            $validator->errors()->add('confirm_transfer', sprintf(
                '%s is already entered for this event with %s (%s). Tick the box to move them, which leaves that team a player short.',
                $existing->full_name,
                $existing->registration?->displayName() ?? 'another entry',
                $existing->registration?->reference ?? '—',
            ));
        }
    }

    /**
     * The columns to write onto the participant row.
     *
     * Everything the counter cannot collect is set to null rather than left as
     * it was, because those values described the person being replaced.
     *
     * @return array<string, mixed>
     */
    public function participantAttributes(): array
    {
        $data = $this->validated();

        return [
            'full_name' => $data['full_name'],
            'ic_number' => $data['ic_number'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,

            // Overwritten rather than left alone: the account that was here
            // belonged to the player being replaced. All three are written even
            // when the event stopped asking for one, so nothing of the outgoing
            // player's account survives on the row.
            'ign_player_id' => $data['ign_player_id'] ?? null,
            'ign_server_id' => $data['ign_server_id'] ?? null,
            'ign_name' => $data['ign_name'] ?? null,
            'gender' => $data['gender'] ?? null,
            'race' => $data['race'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,

            'address_line_1' => null,
            'address_line_2' => null,
            'postcode' => null,
            'city' => null,
            'state' => null,
            'emergency_contact_name' => null,
            'emergency_contact_phone' => null,
        ];
    }

    public function reason(): ?string
    {
        return $this->validated()['reason'] ?? null;
    }
}
