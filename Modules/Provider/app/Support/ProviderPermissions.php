<?php

namespace Modules\Provider\Support;

/** The permission keys a member can hold (config `provider.permissions`) and the ready-made templates (§17). */
final class ProviderPermissions
{
    /** @return list<string> */
    public static function all(): array
    {
        return array_values(array_merge(...array_values((array) config('provider.permissions', []))));
    }

    /** @return array<string, list<string>> */
    public static function groups(): array
    {
        return (array) config('provider.permissions', []);
    }

    /** @return array<string, list<string>> */
    public static function templates(): array
    {
        return (array) config('provider.permission_templates', []);
    }

    /**
     * Known keys only, each once.
     *
     * @param  array<int, mixed>  $keys
     * @return list<string>
     */
    public static function clean(array $keys): array
    {
        return array_values(array_unique(array_intersect(array_map('strval', $keys), self::all())));
    }
}
