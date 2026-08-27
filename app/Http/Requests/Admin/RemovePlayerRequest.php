<?php

namespace App\Http\Requests\Admin;

use App\Models\EventParticipant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Taking a player off an entry at the counter.
 *
 * A reason is asked for rather than required: the counter is being told
 * something by a manager standing in front of them, and holding up a queue over
 * a text box would only get it filled with a full stop.
 */
class RemovePlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route already carries permission:attendance.remove-player
        return true;
    }

    public function participant(): EventParticipant
    {
        return $this->route('participant');
    }

    /**
     * Back to the desk with the squad still open, rather than wherever the
     * Referer header happens to point.
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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => filled($this->reason) ? trim((string) $this->reason) : null,
        ]);
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // Re-checked server side because the button was drawn before this
                // request ran: another counter may have checked them in since, or
                // taken the rest of the squad off.
                $reason = $this->participant()->removalBlockedReason();

                if ($reason !== null) {
                    $validator->errors()->add('participant', $reason);
                }
            },
        ];
    }

    public function reason(): ?string
    {
        return $this->validated()['reason'] ?? null;
    }
}
