<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Blocked => 'Blocked',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Active => 'نشط',
            self::Inactive => 'غير نشط',
            self::Blocked => 'محظور',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    /**
     * @return array<string, string>
     */
    public static function options(?string $locale = null): array
    {
        $locale ??= app()->getLocale();

        return collect(self::cases())->mapWithKeys(
            fn (self $status) => [
                $status->value => $locale === 'ar' ? $status->labelAr() : $status->label(),
            ]
        )->all();
    }
}
