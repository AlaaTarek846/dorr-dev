<?php

namespace App\Models;

use App\Enums\Status;
use App\Models\Concerns\HasTranslations;
use App\Traits\HasMediaTrait;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;

class DashboardTheme extends Model implements HasMedia
{
    use HasMediaTrait, HasTranslations, SearchFilterTrait, SoftDeletes;

    public const PREVIEW_IMAGE_COLLECTION = 'preview_image';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'path',
        'status',
        'is_default',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => Status::class,
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(DashboardThemeTranslation::class);
    }

    protected function translationModel(): string
    {
        return DashboardThemeTranslation::class;
    }

    public function preferences(): HasMany
    {
        return $this->hasMany(DashboardThemePreference::class);
    }
}
