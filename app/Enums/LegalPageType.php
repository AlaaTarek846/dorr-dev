<?php

namespace App\Enums;

enum LegalPageType: string
{
    case Privacy = 'privacy';
    case Term = 'term';

    public function label(): string
    {
        return match ($this) {
            self::Privacy => 'Privacy Policy',
            self::Term => 'Terms',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Privacy => 'سياسة الخصوصية',
            self::Term => 'الشروط والأحكام',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, string>
     */
    public static function options(?string $locale = null): array
    {
        $locale ??= app()->getLocale();

        return collect(self::cases())->mapWithKeys(
            fn (self $type) => [
                $type->value => $locale === 'ar' ? $type->labelAr() : $type->label(),
            ]
        )->all();
    }
}