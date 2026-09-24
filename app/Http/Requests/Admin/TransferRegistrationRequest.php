<?php

namespace App\Http\Requests\Admin;

use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Support\ParticipantOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Moving a whole entry to a different event.
 *
 * The hard part is not the move, it is that the two events rarely want the same
 * shape of entry. An event taking four players plus two optional extras will not
 * accept a squad of six from an event that allowed eight, and will not accept a
 * squad of three from an event that allowed two. So the move carries two lists:
 * who is being left behind, and who is being brought in.
 *
 * Counted in players, never in rows. A manager who does not play occupies no
 * playing place and is not part of the arithmetic; a manager who ticked "and
 * Player" is one of the players. Getting this wrong is what would report a squad
 * of six as five and refuse a move that should have gone through.
 *
 * Three things are still refused outright rather than worked around:
 *
 * Money already taken. The fee becomes the target's, and this cannot collect a
 * difference or send one back. Refund at the gateway and enter it again.
 *
 * A different shape. A squad entry has a manager and players; an individual entry
 * has neither. Moving between them would leave roles the target does not use.
 *
 * Too few players and nobody added to make up the difference. The minimum is the
 * organiser's floor, not a suggestion.
 */
class TransferRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route carries permission:participants.transfer.
        return true;
    }

    public function registration(): EventRegistration
    {
        /** @var EventRegistration $registration */
        $registration = $this->route('registration');

        return $registration->loadMissing(['event', 'participants']);
    }

    private ?Event $targetEvent = null;

    private bool $targetResolved = false;

    /**
     * The event being moved to, or null while the posted id is not a real event.
     *
     * Memoised on the instance rather than in a static local: a static inside a
     * method belongs to the method, not the object, so under a persistent worker
     * the second move of the process would be handed the first one's target.
     *
     * The rules, the cross-field checks and the messages all need it, and it
     * carries the questions and the bounds they are all measured against.
     */
    public function target(): ?Event
    {
        if ($this->targetResolved) {
            return $this->targetEvent;
        }

        $this->targetResolved = true;

        $id = $this->input('event_id');

        if (! is_numeric($id)) {
            return null;
        }

        $this->targetEvent = Event::query()->with('questions')->find((int) $id);

        return $this->targetEvent;
    }

    public function rules(): array
    {
        return [
            'event_id' => ['required', 'integer', 'exists:events,id'],

            // Which of the people already on the entry are not going.
            'drop' => ['nullable', 'array'],
            'drop.*' => ['integer'],

            /*
             | People being brought in to make up a shortfall. Deliberately lighter
             | than the public form: full name, card, phone and email, plus whatever
             | game account the target event insists on. The address and demographic
             | columns were made nullable for counter entry precisely because
             | somebody standing in at short notice is not going to produce a
             | postcode, and demanding one would block a move that has to happen.
             */
            'add' => ['nullable', 'array', 'max:50'],
            'add.*.full_name' => ['required', 'string', 'max:180'],
            'add.*.ic_number' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9-]+$/'],
            'add.*.phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
            'add.*.email' => ['required', 'string', 'email:rfc', 'max:190'],
            'add.*.date_of_birth' => ['nullable', 'date', 'before:today'],
            'add.*.gender' => ['nullable', 'string', 'max:20'],
            'add.*.race' => ['nullable', 'string', 'max:40'],
            'add.*.address_line_1' => ['nullable', 'string', 'max:180'],
            'add.*.city' => ['nullable', 'string', 'max:100'],
            'add.*.state' => ['nullable', 'string', 'max:100'],
            'add.*.country' => ['nullable', 'string', 'max:100'],

            ...$this->addedIgnRules(),

            /*
             | The target event's own questions. Shape only here; which of them had
             | to be ticked is settled against the database in checkAnswers(),
             | because a posted "this one was optional" would be worth nothing.
             |
             | Keyed by participant id for people already on the entry, and by the
             | index in the add list for people arriving, because they have no id
             | until the move is written.
             */
            'answers' => ['nullable', 'array'],
            'answers.*' => ['nullable', 'array'],
            'answers.*.*' => ['nullable', 'boolean'],
            'add.*.answers' => ['nullable', 'array'],
            'add.*.answers.*' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Game account rules for people being brought in, from the target's own map.
     *
     * A field the target does not ask for gets no rule at all rather than a
     * nullable one, so a value posted for a field the form never drew is not
     * validated and never reaches the model.
     *
     * @return array<string, array<int, string>>
     */
    private function addedIgnRules(): array
    {
        $target = $this->target();

        if ($target === null) {
            return [];
        }

        $rules = [];

        foreach ($target->ignFieldsAsked() as $field => $label) {
            $rules["add.*.{$field}"] = [
                $target->requiresIgnField($field) ? 'required' : 'nullable',
                'string',
                'max:60',
            ];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $added = $this->input('add');

        if (! is_array($added)) {
            return;
        }

        /*
         | Drop rows the operator opened then left completely blank. The page draws
         | a form for every optional place as well as every compulsory one, so an
         | untouched extra place must not fail the whole move. answers is excluded
         | from the emptiness test because each tick box has a hidden 0 in front of
         | it, and filled('0') is true, which would make every blank block look
         | filled in.
         */
        $added = array_values(array_filter(
            $added,
            fn ($row) => is_array($row) && collect($row)
                ->except(['answers'])
                ->filter(fn ($value) => filled($value))
                ->isNotEmpty(),
        ));

        $added = array_map(function (array $row) {
            foreach (['full_name', 'ic_number', 'city', 'email', 'phone', 'ign_player_id', 'ign_server_id', 'ign_name'] as $field) {
                if (isset($row[$field]) && is_string($row[$field])) {
                    $row[$field] = trim($row[$field]);
                }
            }

            // Compared without punctuation, or 901010-11-1111 and 901010111111
            // would pass as two different people.
            if (isset($row['ic_number']) && is_string($row['ic_number'])) {
                $row['ic_number'] = strtoupper(str_replace([' ', '-'], '', $row['ic_number']));
            }

            return $row;
        }, $added);

        $this->merge(['add' => $added]);
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkNoMoneyHasMoved($validator),
            fn (Validator $validator) => $this->checkItIsGoingSomewhereElse($validator),
            fn (Validator $validator) => $this->checkShapesMatch($validator),
            fn (Validator $validator) => $this->checkEveryoneDroppedMayBeDropped($validator),
            fn (Validator $validator) => $this->checkPlayerCountFitsTheTarget($validator),
            fn (Validator $validator) => $this->checkIdentityCardsAreNotAlreadyThere($validator),
            fn (Validator $validator) => $this->checkAnswers($validator),
        ];
    }

    /* ------------------------------------------------------------- refusals */

    private function checkNoMoneyHasMoved(Validator $validator): void
    {
        $registration = $this->registration();

        if (! $registration->hasMoneyOnRecord()) {
            return;
        }

        $validator->errors()->add('event_id', sprintf(
            '%s cannot be moved because %s has been taken for it. Refund it at the gateway, then enter it on the other event.',
            $registration->reference,
            $registration->amountLabel(),
        ));
    }

    private function checkItIsGoingSomewhereElse(Validator $validator): void
    {
        $target = $this->target();

        if ($target === null) {
            return;
        }

        if ((int) $target->id === (int) $this->registration()->event_id) {
            $validator->errors()->add('event_id', 'That is the event it is already on.');
        }
    }

    private function checkShapesMatch(Validator $validator): void
    {
        $target = $this->target();
        $registration = $this->registration();

        if ($target === null) {
            return;
        }

        if ($target->registration_mode !== $registration->mode) {
            $validator->errors()->add('event_id', sprintf(
                '%s takes %s entries and this one is %s. The two shapes are not interchangeable.',
                $target->title,
                $target->isManagerMode() ? 'squad' : 'individual',
                $registration->mode === Event::MODE_MANAGER ? 'a squad' : 'individual',
            ));

            return;
        }

        // An individual entry is one named person. There is nobody to leave behind
        // and no second place to fill.
        if (! $target->isManagerMode() && ($this->dropped()->isNotEmpty() || $this->added() !== [])) {
            $validator->errors()->add(
                'event_id',
                'This event takes one person per entry, so nobody can be added or left behind.',
            );
        }
    }

    /**
     * Asked of the model, so this and the counter cannot drift apart on who may
     * be taken off an entry.
     */
    private function checkEveryoneDroppedMayBeDropped(Validator $validator): void
    {
        $registration = $this->registration();
        $ids = collect($this->input('drop', []))->map(fn ($id) => (int) $id);

        foreach ($ids as $id) {
            /** @var EventParticipant|null $person */
            $person = $registration->participants->firstWhere('id', $id);

            if ($person === null) {
                $validator->errors()->add('drop', 'One of the people chosen is not on this entry.');

                continue;
            }

            $person->setRelation('registration', $registration);

            $blocked = $person->removalBlockedReason();

            if ($blocked !== null) {
                $validator->errors()->add('drop', sprintf('%s cannot be left behind. %s', $person->full_name, $blocked));
            }
        }
    }

    /**
     * The whole point of the screen: does the entry fit the target once the two
     * lists have been applied.
     */
    private function checkPlayerCountFitsTheTarget(Validator $validator): void
    {
        $target = $this->target();

        if ($target === null || ! $target->isManagerMode()) {
            return;
        }

        [$min, $max] = $target->playerBounds();
        $players = $this->playersAfterChanges();

        if ($players < $min) {
            $short = $min - $players;

            $validator->errors()->add('add', sprintf(
                '%s needs at least %d players and this entry would have %d. Add %d more %s.',
                $target->title,
                $min,
                $players,
                $short,
                $short === 1 ? 'person' : 'people',
            ));
        }

        if ($max !== null && $players > $max) {
            $over = $players - $max;

            $validator->errors()->add('drop', sprintf(
                '%s takes at most %d players and this entry would have %d. Choose %d more to leave behind.',
                $target->title,
                $max,
                $players,
                $over,
            ));
        }
    }

    /**
     * Nobody arriving may carry a card that is already on the target event, nor
     * one already held by somebody staying on this entry.
     */
    private function checkIdentityCardsAreNotAlreadyThere(Validator $validator): void
    {
        $target = $this->target();
        $added = $this->added();

        if ($added === []) {
            return;
        }

        $staying = $this->staying()->pluck('ic_number')->filter()->all();

        $onTarget = $target === null ? [] : EventParticipant::query()
            ->whereHas('registration', fn ($query) => $query
                ->where('event_id', $target->id)
                ->where('status', '!=', EventRegistration::STATUS_CANCELLED))
            ->pluck('ic_number')
            ->filter()
            ->all();

        $seen = [];

        foreach ($added as $index => $person) {
            $card = (string) ($person['ic_number'] ?? '');

            if ($card === '') {
                continue;
            }

            if (in_array($card, $seen, true)) {
                $validator->errors()->add("add.{$index}.ic_number", 'This identity card is listed twice in the people being added.');
            } elseif (in_array($card, $staying, true)) {
                $validator->errors()->add("add.{$index}.ic_number", 'This identity card already belongs to somebody staying on this entry.');
            } elseif (in_array($card, $onTarget, true)) {
                $validator->errors()->add("add.{$index}.ic_number", sprintf(
                    'This identity card is already registered on %s.',
                    $target->title,
                ));
            }

            $seen[] = $card;
        }
    }

    /**
     * Every compulsory question the target event asks, answered by everybody who
     * will be on the entry once it has moved.
     *
     * Read from the target's own questions rather than from the posted keys, so a
     * submission that simply omits an inconvenient question is refused rather
     * than passing by absence.
     */
    private function checkAnswers(Validator $validator): void
    {
        $target = $this->target();

        if ($target === null) {
            return;
        }

        $required = $target->questions->where('is_required', true);

        if ($required->isEmpty()) {
            return;
        }

        $answers = (array) $this->input('answers', []);

        foreach ($this->staying() as $person) {
            $given = (array) ($answers[$person->id] ?? []);

            foreach ($required as $question) {
                if (filter_var($given[$question->id] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }

                $validator->errors()->add(
                    "answers.{$person->id}.{$question->id}",
                    sprintf('"%s" has to be recorded for %s.', $question->title, $person->full_name),
                );
            }
        }

        foreach ($this->added() as $index => $person) {
            $given = (array) ($person['answers'] ?? []);

            foreach ($required as $question) {
                if (filter_var($given[$question->id] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }

                $validator->errors()->add(
                    "add.{$index}.answers.{$question->id}",
                    sprintf('"%s" has to be recorded for the person being added.', $question->title),
                );
            }
        }
    }

    /* --------------------------------------------------------------- shared */

    /**
     * People on the entry who are being left behind.
     *
     * @return \Illuminate\Support\Collection<int, EventParticipant>
     */
    public function dropped()
    {
        $ids = collect($this->input('drop', []))->map(fn ($id) => (int) $id)->all();

        return $this->registration()->participants->whereIn('id', $ids)->values();
    }

    /**
     * People on the entry who are going with it.
     *
     * @return \Illuminate\Support\Collection<int, EventParticipant>
     */
    public function staying()
    {
        $ids = collect($this->input('drop', []))->map(fn ($id) => (int) $id)->all();

        return $this->registration()->participants->whereNotIn('id', $ids)->values();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function added(): array
    {
        $added = $this->input('add', []);

        return is_array($added) ? $added : [];
    }

    /**
     * How many playing places the entry would fill on the target.
     *
     * Everybody arriving is a player: a squad has at most one manager and it
     * already has theirs, so there is no second manager to bring in.
     */
    public function playersAfterChanges(): int
    {
        $staying = $this->staying()
            ->filter(fn (EventParticipant $person) => $person->isPlaying())
            ->count();

        return $staying + count($this->added());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'event_id.required' => 'Choose the event to move this entry to.',
            'add.*.ic_number.regex' => 'The identity card number may only contain letters, numbers and hyphens.',
            'add.*.phone.regex' => 'The telephone number may only contain digits, spaces and the characters + - ( ).',
            'add.*.date_of_birth.before' => 'The date of birth must be in the past.',
        ];

        foreach ($this->target()?->ignFieldsAsked() ?? [] as $field => $label) {
            $messages["add.*.{$field}.required"] = sprintf('%s needs a %s for everyone taking part.', $this->target()->title, $label);
        }

        return $messages;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $labels = [
            'event_id' => 'event',
            'drop' => 'people being left behind',
            'add' => 'people being added',
        ];

        // Turns "add.1.full_name" into "added person 2 full name", so a failed
        // field is findable when three people are being brought in at once.
        $spelled = [
            'ic_number' => 'identity card',
            'ign_player_id' => 'Player ID',
            'ign_server_id' => 'Server ID',
            'ign_name' => 'in-game name',
            'address_line_1' => 'address',
        ];

        foreach (range(0, 49) as $index) {
            foreach ([
                'full_name', 'ic_number', 'phone', 'email', 'date_of_birth',
                'gender', 'race', 'address_line_1', 'city', 'state', 'country',
                'ign_player_id', 'ign_server_id', 'ign_name',
            ] as $field) {
                $labels["add.{$index}.{$field}"] = sprintf(
                    'added person %d %s',
                    $index + 1,
                    $spelled[$field] ?? str_replace('_', ' ', $field),
                );
            }
        }

        return $labels;
    }

    /**
     * Failures go back to the move page with the target still chosen, so the
     * bounds, the questions and the typed details are all still on screen.
     */
    protected function getRedirectUrl(): string
    {
        $registration = $this->route('registration');

        return route('admin.event.participants.transfer', array_filter([
            'registration' => $registration->id,
            'event' => $this->input('event_id'),
        ]));
    }

    /**
     * Roles for people being brought in. Always a player, never a manager.
     */
    public function addedRole(): string
    {
        return ParticipantOptions::ROLE_PLAYER;
    }
}
