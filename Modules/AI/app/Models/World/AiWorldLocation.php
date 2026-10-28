<?php

namespace Modules\AI\Models\World;

use Illuminate\Database\Eloquent\Model;

/**
 * A place a scene can be set in. "Curated" rows are the built-in landmark catalog; "google" rows are places cached from
 * the maps provider so the same search is never paid for twice. fidelity_tier is what the user is told to expect.
 */
class AiWorldLocation extends Model
{
    public const KIND_COUNTRY = 'country';

    public const KIND_CITY = 'city';

    public const KIND_DISTRICT = 'district';

    public const KIND_STREET = 'street';

    public const KIND_LANDMARK = 'landmark';

    public const TIER_CINEMATIC = 'cinematic';

    public const TIER_REFERENCE_BASED = 'reference_based';

    public const TIER_INTERACTIVE_3D = 'interactive_3d';

    protected $table = 'ai_world_locations';

    protected $fillable = [
        'source', 'provider_place_id', 'name_en', 'name_ar', 'kind', 'country_code', 'city', 'lat', 'lng',
        'formatted_address', 'search_text', 'is_curated', 'tiles_available', 'fidelity_tier', 'tier_reason', 'references', 'cached_at',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'is_curated' => 'boolean',
            'tiles_available' => 'boolean',
            'references' => 'array',
            'cached_at' => 'datetime',
        ];
    }

    public function displayName(): string
    {
        return app()->getLocale() === 'ar' ? ($this->name_ar ?: $this->name_en) : $this->name_en;
    }

    /**
     * Lower-cases, strips Arabic diacritics and unifies alef/ya/ta-marbuta forms, so "ايفل" finds "إيفل".
     */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text) ?? $text;
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }
}
