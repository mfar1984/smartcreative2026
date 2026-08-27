<?php

namespace App\Models;

use App\Support\EventTemplates;
use Illuminate\Database\Eloquent\Model;

class EventTemplate extends Model
{
    protected $fillable = [
        'key',
        'channel',
        'subject',
        'body',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * The stored template for a moment and channel, or null when none is saved.
     */
    public static function lookup(string $key, string $channel): ?self
    {
        return static::query()
            ->where('key', $key)
            ->where('channel', $channel)
            ->first();
    }

    /**
     * Templates for one channel, keyed by template key.
     *
     * @return \Illuminate\Support\Collection<string, self>
     */
    public static function forChannel(string $channel)
    {
        return static::query()
            ->where('channel', $channel)
            ->get()
            ->keyBy('key');
    }

    public function label(): string
    {
        return EventTemplates::definition($this->key)['label'] ?? $this->key;
    }

    public function isEmail(): bool
    {
        return $this->channel === EventTemplates::CHANNEL_EMAIL;
    }

    /**
     * Whether this template is addressed to one named participant rather than
     * to the registration as a whole.
     */
    public function isPerParticipant(): bool
    {
        return EventTemplates::isPerParticipant($this->key);
    }
}
