<?php

namespace App\Enums;

enum Status: int
{
    case Inactive = 0;
    case Active = 1;

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Active => 'نشط',
            self::Inactive => 'غير نشط',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    /**
     * @return array<int, string>
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
