<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    use HasTranslations, SearchFilterTrait;

    protected $fillable = [
        'code',
        'code_alpha3',
        'dial_code',
        'phone_starts_with',
        'phone_length',
        'is_default',
        'flag_id',
        'currency_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'phone_length' => 'integer',
            'is_default' => 'boolean',
            'status' => 'boolean',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(CountryTranslation::class);
    }

    protected function translationModel(): string
    {
        return CountryTranslation::class;
    }

    public function flag(): BelongsTo
    {
        return $this->belongsTo(Flag::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function admins(): HasMany
    {
        return $this->hasMany(\Modules\Admin\Models\Admin::class);
    }
}
