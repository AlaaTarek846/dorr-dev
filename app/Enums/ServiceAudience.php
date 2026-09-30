<?php

namespace App\Enums;

enum ServiceAudience: string
{
    case Admin = 'admin';
    case User = 'user';
    case Provider = 'provider';
    case Driver = 'driver';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::User => 'User app',
            self::Provider => 'Provider',
            self::Driver => 'Driver',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Admin => 'لوحة الإدارة',
            self::User => 'تطبيق المستخدم',
            self::Provider => 'مزود الخدمة',
            self::Driver => 'السائق',
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
     * @param  list<string>  $values
     * @return list<self>
     */
    public static function tryFromMany(array $values): array
    {
        $cases = [];

        foreach ($values as $value) {
            $case = self::tryFrom((string) $value);

            if ($case !== null) {
                $cases[] = $case;
            }
        }

        return $cases;
    }

    /**
     * @return array<string, string>
     */
    public static function options(?string $locale = null): array
    {
        $locale ??= app()->getLocale();

        return collect(self::cases())->mapWithKeys(
            fn (self $audience) => [
                $audience->value => $locale === 'ar' ? $audience->labelAr() : $audience->label(),
            ]
        )->all();
    }

    /**
     * @param  list<string>  $audiences
     * @return array{is_login_dashboard: bool, requires_provider: bool}
     */
    public static function legacyFlagsFromAudiences(array $audiences): array
    {
        return [
            'is_login_dashboard' => in_array(self::User->value, $audiences, true),
            'requires_provider' => in_array(self::Provider->value, $audiences, true),
        ];
    }

    /**
     * Backfill helper from legacy booleans and module_name.
     *
     * @return list<string>
     */
    public static function inferFromLegacy(bool $requiresProvider, bool $isLoginDashboard, ?string $moduleName = null): array
    {
        if (in_array($moduleName, ['general_services', 'system_users', 'admin', 'admin_permission'], true)) {
            return [self::Admin->value];
        }

        $audiences = [];

        if ($isLoginDashboard) {
            $audiences[] = self::User->value;
        }

        if ($requiresProvider) {
            $audiences[] = self::Provider->value;
        }

        if ($moduleName === 'driver_without_vehicle') {
            $audiences[] = self::Driver->value;
        }

        if ($audiences === []) {
            $audiences[] = self::User->value;
        }

        return array_values(array_unique($audiences));
    }
}
