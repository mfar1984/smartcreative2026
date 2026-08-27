<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'group',
        'value',
        'is_secret',
    ];

    protected function casts(): array
    {
        return [
            'is_secret' => 'boolean',
        ];
    }

    /**
     * Read a single setting, decrypting it when it is marked as secret.
     *
     * Encryption is handled here rather than through an Eloquent cast because
     * whether a value is encrypted depends on a sibling column, and casts run
     * before that column is guaranteed to be populated.
     */
    public static function read(string $key, ?string $default = null): ?string
    {
        $setting = static::query()->where('key', $key)->first();

        if ($setting === null) {
            return $default;
        }

        return $setting->plainValue() ?? $default;
    }

    /**
     * Read every setting in a group as a key => value map.
     *
     * @return array<string, string|null>
     */
    public static function readGroup(string $group): array
    {
        return static::query()
            ->where('group', $group)
            ->get()
            ->mapWithKeys(fn (self $setting) => [$setting->key => $setting->plainValue()])
            ->all();
    }

    /**
     * Create or update a setting, encrypting it when marked as secret.
     */
    public static function write(string $key, ?string $value, string $group, bool $isSecret = false): self
    {
        return static::updateOrCreate(
            ['key' => $key],
            [
                'group' => $group,
                'value' => $isSecret && $value !== null && $value !== ''
                    ? Crypt::encryptString($value)
                    : $value,
                'is_secret' => $isSecret,
            ],
        );
    }

    /**
     * The usable value, decrypted when necessary.
     */
    public function plainValue(): ?string
    {
        if (! $this->is_secret || $this->value === null || $this->value === '') {
            return $this->value;
        }

        try {
            return Crypt::decryptString($this->value);
        } catch (DecryptException) {
            // Most likely cause is a changed APP_KEY. Returning null keeps the
            // settings screen usable instead of throwing on every page load.
            Log::warning('Setting could not be decrypted.', ['key' => $this->key]);

            return null;
        }
    }
}
