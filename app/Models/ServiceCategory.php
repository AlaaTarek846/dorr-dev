<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use App\Traits\HasMediaTrait;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;

class ServiceCategory extends Model implements HasMedia
{
    use HasMediaTrait, HasTranslations, SearchFilterTrait;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'parent_id',
        'module_name',
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
            'is_login_dashboard' => 'boolean',
            'is_auto_assign' => 'boolean',
            'requires_provider' => 'boolean',
            'status' => 'boolean',
            'sort_order' => 'integer',
        ];
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

    public function isLeaf(): bool
    {
        if ($this->relationLoaded('children')) {
            return $this->children->isEmpty();
        }

        return $this->children()->doesntExist();
    }
}
