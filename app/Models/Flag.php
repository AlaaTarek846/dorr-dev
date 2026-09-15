<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Flag extends Model
{
    use HasTranslations, SearchFilterTrait;

    protected $fillable = ['code', 'status'];

    protected function casts(): array
    {
        return ['status' => 'boolean'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(FlagTranslation::class);
    }

    protected function translationModel(): string
    {
        return FlagTranslation::class;
    }

    public function languages(): HasMany
    {
        return $this->hasMany(Language::class);
    }

    public function countries(): HasMany
    {
        return $this->hasMany(Country::class);
    }
}
