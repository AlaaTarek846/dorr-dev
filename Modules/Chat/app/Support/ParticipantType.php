<?php

namespace Modules\Chat\Support;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Maps chat participants ('user' / 'provider' / later 'driver') to their model class and back —
 * the chat's own copy of Modules\Wallet\Support\OwnerType, for the same reason (never
 * Relation::morphMap(), which is global and rewrites unrelated relations).
 */
class ParticipantType
{
    public static function aliasFor(Model $participant): string
    {
        foreach (self::map() as $alias => $class) {
            if ($participant instanceof $class) {
                return $alias;
            }
        }

        throw new RuntimeException('No chat participant alias registered for '.$participant::class.'.');
    }

    /**
     * @return class-string<Model>
     */
    public static function modelClassFor(string $alias): string
    {
        return self::map()[$alias]
            ?? throw new RuntimeException("Unknown chat participant alias: {$alias}.");
    }

    /**
     * Whether this kind of account may chat today (config `chat.enabled_participants`).
     */
    public static function isEnabled(string $alias): bool
    {
        return in_array($alias, config('chat.enabled_participants', []), true);
    }

    /**
     * "user:7" — used for direct_key and cache keys.
     */
    public static function key(Model $participant): string
    {
        return self::aliasFor($participant).':'.$participant->getKey();
    }

    /**
     * @return array<string, class-string<Model>>
     */
    private static function map(): array
    {
        return config('chat.participants', []);
    }
}
