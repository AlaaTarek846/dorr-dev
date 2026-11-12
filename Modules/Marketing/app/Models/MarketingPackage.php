<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The marketing package (D5): sold on its own, priced per country, lasting `period_days`. `limits` caps what one
 * subscriber does per period: `projects` (active projects at once), `research_runs`, `plan_generations`, `members`
 * (per project, the owner aside). A missing or null limit means no limit.
 */
class MarketingPackage extends Model
{
    use SoftDeletes;

    public const LIMITS = ['projects', 'research_runs', 'plan_generations', 'members', 'content_generations'];

    protected $fillable = ['code', 'name', 'description', 'period_days', 'limits', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'limits' => 'array',
            'is_active' => 'boolean',
            'period_days' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(MarketingPackagePrice::class, 'package_id');
    }

    public function priceIn(int $countryId): ?MarketingPackagePrice
    {
        return $this->prices->first(fn (MarketingPackagePrice $p) => $p->country_id === $countryId && $p->status);
    }

    /** null = no limit. */
    public function limit(string $key): ?int
    {
        $value = ($this->limits ?? [])[$key] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function localized(string $field): ?string
    {
        $values = $this->{$field} ?? [];

        return $values[app()->getLocale()] ?? $values['en'] ?? $values['ar'] ?? null;
    }
}
