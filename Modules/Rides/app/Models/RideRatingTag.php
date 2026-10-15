<?php

namespace Modules\Rides\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Rides\Enums\RatingTarget;

/** A chip under the stars ("clean car", "late"…) for rating the driver or the rider (R-142, D-186). */
class RideRatingTag extends Model
{
    use HasTranslations, SoftDeletes;

    protected $fillable = ['target', 'sentiment', 'status', 'sort_order'];

    protected function casts(): array
    {
        return [
            'target' => RatingTarget::class,
            'status' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(RideRatingTagTranslation::class);
    }

    protected function translationModel(): string
    {
        return RideRatingTagTranslation::class;
    }
}
