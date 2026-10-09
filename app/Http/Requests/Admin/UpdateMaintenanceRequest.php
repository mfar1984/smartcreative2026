<?php

namespace App\Http\Requests\Admin;

use App\Support\IpAllowlist;
use App\Support\MaintenanceSettings;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the General Config > Maintenance tab.
 *
 * The switch, the holding page copy, the addresses exempt from it, the expected
 * return time and the scheduled window.
 *
 * The three times are wall-clock values: whatever the operator typed on the
 * office clock, kept as typed. The only rules about them are that they can be
 * read, and that a window makes sense — an end with no start, or an end that is
 * not after its start, is a window that could never open, so it is refused here
 * rather than stored and quietly ignored.
 */
class UpdateMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route already carries permission:settings.maintenance.update
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['nullable', 'boolean'],
            'heading' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:1000'],

            'exempt_ips' => [
                'nullable', 'bail', 'string',
                'max:' . MaintenanceSettings::MAX_EXEMPT_IPS_LENGTH,
                function (string $attribute, mixed $value, Closure $fail): void {
                    // Numbered as the owner sees them in the box, blank lines
                    // included, so the message points at the line to fix.
                    foreach (preg_split('/\R/', (string) $value) ?: [] as $index => $line) {
                        $line = trim($line);

                        if ($line !== '' && ! IpAllowlist::isValidEntry($line)) {
                            $fail(sprintf(
                                'Line %d of the exempt addresses, "%s", is not a valid IP address or CIDR range. Use one per line, for example 203.0.113.10 or 203.0.113.0/24.',
                                $index + 1,
                                $line,
                            ));

                            return;
                        }
                    }
                },
            ],

            'expected_return_at' => ['nullable', 'bail', 'string', $this->readableTime()],
            'window_start' => ['nullable', 'bail', 'string', $this->readableTime()],

            'window_end' => [
                'nullable', 'bail', 'string', $this->readableTime(),
                function (string $attribute, mixed $value, Closure $fail): void {
                    $start = MaintenanceSettings::normaliseWallClock($this->input('window_start'));

                    if ($start === '') {
                        $fail('An end time needs a start time. Set when the window begins, or clear the end time.');

                        return;
                    }

                    // Compared as the stored "Y-m-d H:i" text, which sorts
                    // chronologically, so no instant has to be built to answer it.
                    if (MaintenanceSettings::normaliseWallClock($value) <= $start) {
                        $fail('The window must end after it starts.');
                    }
                },
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        // An unchecked checkbox is absent from the payload.
        $this->merge([
            'enabled' => $this->boolean('enabled'),
        ]);

        // An empty datetime-local field posts an empty string. Nulled here so the
        // optional fields read as "nothing set" rather than as a value to parse.
        foreach (['expected_return_at', 'window_start', 'window_end'] as $field) {
            if (trim((string) $this->input($field)) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    /**
     * Everything that passed, in the shape it is stored in: the switch as 0/1, the
     * exempt list one trimmed entry per line, the times as bare local timestamps.
     *
     * @return array<string, string>
     */
    public function settings(): array
    {
        $validated = $this->validated();

        return [
            'enabled' => $validated['enabled'] ? '1' : '0',
            'heading' => $validated['heading'],
            'message' => $validated['message'],
            'exempt_ips' => IpAllowlist::normalise($validated['exempt_ips'] ?? ''),
            'expected_return_at' => MaintenanceSettings::normaliseWallClock($validated['expected_return_at'] ?? null),
            'window_start' => MaintenanceSettings::normaliseWallClock($validated['window_start'] ?? null),
            'window_end' => MaintenanceSettings::normaliseWallClock($validated['window_end'] ?? null),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'exempt_ips' => 'exempt addresses',
            'expected_return_at' => 'expected return time',
            'window_start' => 'window start',
            'window_end' => 'window end',
        ];
    }

    /**
     * A time the system can read back: either what the picker posts or what is
     * already stored. Anything else is a typo, and a stored typo would silently
     * mean "no window".
     */
    private function readableTime(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! MaintenanceSettings::isValidWallClock((string) $value)) {
                $fail('The :attribute is not a date and time this can read. Use the picker, or type it as 2026-10-10 09:00.');
            }
        };
    }
}
