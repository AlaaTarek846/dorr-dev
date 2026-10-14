<?php

namespace Modules\Rides\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Rides\Enums\CancelReasonActor;

/** A reason to cancel, for the rider, the driver or both; a safety reason goes to its own review (D-398). */
class RideCancelReason extends Model
{
    use HasTranslations, SoftDeletes;

    protected $fillable = ['actor', 'stages', 'is_safety', 'requires_note', 'status', 'sort_order'];

    protected function casts(): array
    {
        return [
            'actor' => CancelReasonActor::class,
            'stages' => 'array',
            'is_safety' => 'boolean',
            'requires_note' => 'boolean',
            'status' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(RideCancelReasonTranslation::class);
    }

    protected function translationModel(): string
    {
        return RideCancelReasonTranslation::class;
    }
}
