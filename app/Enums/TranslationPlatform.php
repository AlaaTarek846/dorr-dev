<?php

namespace App\Enums;

enum TranslationPlatform: string
{
    case Backend = 'backend';
    case Vue = 'vue';
    case Android = 'android';

    /**
     * @return list<string>
     */
    public function groups(): array
    {
        return match ($this) {
            self::Backend => ['api', 'validation', 'notifications', 'chat', 'wallet', 'sms', 'ai', 'provider'],
            self::Vue => ['messages'],
            self::Android => ['strings', 'chat_strings', 'wallet_strings'],
        };
    }

    public function hasGroup(string $group): bool
    {
        return in_array($group, $this->groups(), true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
