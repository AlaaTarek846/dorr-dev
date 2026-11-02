<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A paid extra on a ready-made service (FL-L04): faster delivery, the source files… — its price and the days it adds. */
class FreelanceServiceAddon extends Model
{
    protected $fillable = ['service_id', 'title', 'price_minor', 'extra_days', 'sort_order'];

    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'extra_days' => 'integer', 'sort_order' => 'integer'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(FreelanceService::class, 'service_id')->withTrashed();
    }
}
