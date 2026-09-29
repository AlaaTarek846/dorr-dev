<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use App\Traits\HasMediaTrait;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;

class MobileAppFont extends Model implements HasMedia
{
    use HasMediaTrait, HasTranslations, SearchFilterTrait, SoftDeletes;

    public const FONT_FILES_COLLECTION = 'font_files';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'name',
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
            'status' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(MobileAppFontTranslation::class);
    }

    protected function translationModel(): string
    {
        return MobileAppFontTranslation::class;
    }

    public function userAppearances(): HasMany
    {
        return $this->hasMany(\Modules\User\Models\UserMobileAppearance::class);
    }
}
