<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LegalPage extends Model
{
    use HasTranslations, SearchFilterTrait, SoftDeletes;

    /**
     * @return list<string>
     */
    protected function translationSearchColumns(): array
    {
        return ['content'];
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'service_id',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service_id' => 'integer',
            'status' => 'boolean',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(LegalPageTranslation::class);
    }

    protected function translationModel(): string
    {
        return LegalPageTranslation::class;
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_id');
    }
}