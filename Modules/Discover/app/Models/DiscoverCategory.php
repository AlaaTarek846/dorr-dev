<?php

namespace Modules\Discover\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A kind of event — concerts, exhibitions, courses… (admin catalog). */
class DiscoverCategory extends Model
{
    use HasTranslations;

    protected $fillable = ['key', 'emoji', 'color', 'status', 'sort_order'];

    protected function casts(): array
    {
        return ['status' => 'boolean', 'sort_order' => 'integer'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(DiscoverCategoryTranslation::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DiscoverEvent::class, 'category_id');
    }

    protected function translationModel(): string
    {
        return DiscoverCategoryTranslation::class;
    }
}
