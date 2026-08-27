<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Support\PaymentSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class ChipCredentialSeeder extends Seeder
{
    /**
     * Load CHIP credentials from a JSON file outside version control.
     *
     * The file lives on the private disk, so no key is ever written into a
     * source file. It is read once and then deleted by this seeder, leaving the
     * only copy in the settings table where the API key is stored encrypted.
     */
    private const SOURCE = 'chip-credentials.json';

    /**
     * Which keys hold secrets, and therefore get encrypted at rest.
     */
    private const SECRETS = ['chip_api_key'];

    public function run(): void
    {
        $disk = Storage::disk('local');

        if (! $disk->exists(self::SOURCE)) {
            $this->command?->warn(sprintf('ChipCredentialSeeder skipped: %s not found.', self::SOURCE));

            return;
        }

        $payload = json_decode((string) $disk->get(self::SOURCE), true);

        if (! is_array($payload)) {
            $this->command?->error(sprintf('ChipCredentialSeeder failed: %s is not valid JSON.', self::SOURCE));

            return;
        }

        foreach ($payload as $key => $value) {
            if (! is_string($key) || $value === null) {
                continue;
            }

            Setting::write(
                'integration.payments.' . $key,
                (string) $value,
                'integration.payments',
                in_array($key, self::SECRETS, true),
            );
        }

        // Remove the plaintext copy now that it is stored encrypted.
        $disk->delete(self::SOURCE);

        $this->command?->info(sprintf(
            'CHIP settings stored. Provider: %s, mode: %s, webhooks verifiable: %s. Source file removed.',
            PaymentSettings::providerLabel(),
            PaymentSettings::mode(),
            PaymentSettings::canVerifyWebhooks() ? 'yes' : 'no',
        ));
    }
}
