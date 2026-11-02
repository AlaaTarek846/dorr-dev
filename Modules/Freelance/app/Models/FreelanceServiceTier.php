<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One level of a ready-made service (FL-L04): basic, standard or premium — its price, days, revisions and what it includes. */
class FreelanceServiceTier extends Model
{
    protected $fillable = ['service_id', 'level', 'name', 'description', 'price_minor', 'delivery_days', 'revisions', 'features'];

    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'delivery_days' => 'integer', 'revisions' => 'integer', 'features' => 'array'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(FreelanceService::class, 'service_id')->withTrashed();
    }
}
