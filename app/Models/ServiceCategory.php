<?php

namespace App\Models;

use App\Enums\ServiceAudience;
use App\Models\Concerns\HasTranslations;
use App\Traits\HasMediaTrait;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;

class ServiceCategory extends Model implements HasMedia
{
    use HasMediaTrait, HasTranslations, SearchFilterTrait, SoftDeletes {
        SearchFilterTrait::scopeSearchAndFilter as protected scopeApplyCatalogSearchAndFilter;
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'parent_id',
        'module_name',
        'audiences',
        'is_login_dashboard',
        'is_auto_assign',
        'requires_provider',
        'status',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'module_name' => 'string',
            'audiences' => 'array',
            'is_login_dashboard' => 'boolean',
            'is_auto_assign' => 'boolean',
            'requires_provider' => 'boolean',
            'status' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Catalog modules that belong to the admin dashboard, not a country marketplace.
     *
     * @var list<string>
     */
    public const ADMIN_ONLY_MODULES = [
        'general_services',
        'system_users',
        'admin',
        'admin_permission',
    ];

    /**
     * Active leaf categories an admin can attach to a country.
     */
    public static function assignableForCountries(): Builder
    {
        return static::query()
            ->where('status', true)
            ->whereDoesntHave('children')
            ->where(function (Builder $query) {
                $query->whereNull('module_name')
                    ->orWhereNotIn('module_name', self::ADMIN_ONLY_MODULES);
            });
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ServiceCategoryTranslation::class);
    }

    protected function translationModel(): string
    {
        return ServiceCategoryTranslation::class;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function countries(): BelongsToMany
    {
        return $this->belongsToMany(Country::class, 'country_service_category')
            ->withTimestamps();
    }

    public function translatedDescription(): ?string
    {
        if ($this->relationLoaded('translation') && $this->translation) {
            return $this->translation->description;
        }

        if ($this->relationLoaded('translations')) {
            return $this->translations->firstWhere('locale', app()->getLocale())?->description
                ?? $this->translations->first()?->description;
        }

        return null;
    }

    /**
     * @param  list<string>|null  $audiences
     */
    public function hasAudience(string $audience, ?array $audiences = null): bool
    {
        $list = $audiences ?? $this->audiences ?? [];

        return in_array($audience, $list, true);
    }

    public function scopeSearchAndFilter(Builder $query): Builder
    {
        $query = $this->scopeApplyCatalogSearchAndFilter($query);

        $raw = request()->input('audiences');

        if ($raw === null || $raw === '' || $raw === []) {
            return $query;
        }

        $values = is_array($raw) ? $raw : [$raw];
        $allowed = array_values(array_unique(array_intersect(
            array_map(static fn ($value) => strtolower(trim((string) $value)), $values),
            ServiceAudience::values(),
        )));

        if ($allowed === []) {
            return $query;
        }

        return $query->where(function (Builder $nested) use ($allowed) {
            foreach ($allowed as $index => $audience) {
                if ($index === 0) {
                    $nested->whereJsonContains('audiences', $audience);
                } else {
                    $nested->orWhereJsonContains('audiences', $audience);
                }
            }
        });
    }

    public function isLeaf(): bool
    {
        if ($this->relationLoaded('children')) {
            return $this->children->isEmpty();
        }

        return $this->children()->doesntExist();
    }
}
