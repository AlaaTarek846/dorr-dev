<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends Model
{
    use HasTranslations, SearchFilterTrait;

    protected $fillable = [
        'code',
        'symbol',
        'decimal_places',
        'exchange_rate',
        'is_default',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
            'exchange_rate' => 'decimal:8',
            'is_default' => 'boolean',
            'status' => 'boolean',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(CurrencyTranslation::class);
    }

    protected function translationModel(): string
    {
        return CurrencyTranslation::class;
    }

    public function countries(): HasMany
    {
        return $this->hasMany(Country::class);
    }
}
