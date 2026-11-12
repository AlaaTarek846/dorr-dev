<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One paid period of the package. Buying again before the end adds a period after it. */
class MarketingSubscription extends Model
{
    public const ACTIVE = 'active';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['owner_type', 'owner_id', 'package_id', 'country_id', 'status', 'starts_at', 'ends_at', 'price_minor', 'currency_code', 'checkout_id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'price_minor' => 'integer'];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(MarketingPackage::class, 'package_id')->withTrashed();
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE)->where('starts_at', '<=', now())->where('ends_at', '>', now());
    }
}
