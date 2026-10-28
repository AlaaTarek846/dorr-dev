<?php

namespace Modules\AI\Models\World;

use Illuminate\Database\Eloquent\Model;

/** Per-country switch for each WORLD mode. A country with no row follows DEFAULTS. */
class AiWorldCountryMode extends Model
{
    public const MODE_SINGLE = 'single';

    public const MODE_MULTI = 'multi';

    public const MODE_AR_PORTAL = 'ar_portal';

    public const MODE_TOUR_3D = 'tour3d';

    /** AR portal and 3D tour are later phases, so they start off. */
    public const DEFAULTS = [
        self::MODE_SINGLE => true,
        self::MODE_MULTI => true,
        self::MODE_AR_PORTAL => false,
        self::MODE_TOUR_3D => false,
    ];

    protected $table = 'ai_world_country_modes';

    protected $fillable = ['country_id', 'mode', 'is_enabled'];

    protected function casts(): array
    {
        return ['country_id' => 'integer', 'is_enabled' => 'boolean'];
    }

    public static function isEnabled(?int $countryId, string $mode): bool
    {
        if ($countryId !== null) {
            $row = static::query()->where('country_id', $countryId)->where('mode', $mode)->first();

            if ($row !== null) {
                return $row->is_enabled;
            }
        }

        return self::DEFAULTS[$mode] ?? false;
    }

    /** @return array<string, bool> */
    public static function forCountry(?int $countryId): array
    {
        $modes = [];

        foreach (array_keys(self::DEFAULTS) as $mode) {
            $modes[$mode] = static::isEnabled($countryId, $mode);
        }

        return $modes;
    }
}
