<?php

namespace App\Http\Requests\Admin;

use App\Support\BackupSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the Retention panel on the General Config > Backup & Restore tab.
 *
 * All three fields accept 0, because 0 is how a limit is switched off, and that
 * is the shipped default for the age and the size. The ceilings are there so a
 * typo cannot store an absurd value; the readers clamp anything stored outside
 * them anyway, since a settings row can be edited by other tools.
 *
 * Nothing here needs to guard against a limit that would empty the folder. The
 * pruner cannot do that: the newest automatic archive is taken out of the
 * candidate list before any rule is consulted, so a 1 MB budget leaves it alone
 * and the tab says the budget could not be honoured.
 */
class UpdateBackupRetentionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route already carries permission:settings.backup.update
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'keep_count' => ['required', 'integer', 'min:0', 'max:' . BackupSettings::MAX_KEEP_COUNT],
            'keep_days' => ['required', 'integer', 'min:0', 'max:' . BackupSettings::MAX_KEEP_DAYS],
            'keep_mb' => ['required', 'integer', 'min:0', 'max:' . BackupSettings::MAX_KEEP_MB],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'keep_count' => 'number of automatic backups kept',
            'keep_days' => 'age limit in days',
            'keep_mb' => 'total size limit in MB',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'keep_count.min' => 'Use 0 to keep every automatic backup. A negative number is not a limit.',
            'keep_days.min' => 'Use 0 to switch the age limit off.',
            'keep_mb.min' => 'Use 0 to switch the size limit off.',
        ];
    }
}
