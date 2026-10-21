<?php

namespace Modules\Freelance\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A specialty a freelancer works in, or a sub-specialty under one (FL-101). Two levels at most. */
class FreelanceSpecialty extends Model
{
    use HasTranslations;

    protected $fillable = ['parent_id', 'status', 'sort_order'];

    protected function casts(): array
    {
        return ['status' => 'boolean', 'sort_order' => 'integer', 'parent_id' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(FreelanceSpecialtyTranslation::class);
    }

    protected function translationModel(): string
    {
        return FreelanceSpecialtyTranslation::class;
    }
}
