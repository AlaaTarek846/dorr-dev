<?php

namespace App\Models;

use App\Enums\TextDirection;
use App\Models\Concerns\HasTranslations;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Language extends Model
{
    use HasTranslations, SearchFilterTrait, SoftDeletes;

    protected $fillable = [
        'code',
        'direction',
        'is_default_website',
        'is_default_dashboard',
        'stores_translation',
        'status',
        'flag_id',
    ];

    protected function casts(): array
    {
        return [
            'direction' => TextDirection::class,
            'is_default_website' => 'boolean',
            'is_default_dashboard' => 'boolean',
            'stores_translation' => 'boolean',
            'status' => 'boolean',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(LanguageTranslation::class);
    }

    protected function translationModel(): string
    {
        return LanguageTranslation::class;
    }

    public function flag(): BelongsTo
    {
        return $this->belongsTo(Flag::class);
    }
}
