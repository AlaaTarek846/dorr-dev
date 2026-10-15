<?php

namespace Modules\Rides\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Rides\Enums\PickupPointKind;

/**
 * An approved place to meet or drop off inside a zone — an airport gate, a mall entrance, a hospital
 * parking (R-16, D-111). The rider picks one; the captain is sent to exactly it.
 */
class RidePickupPoint extends Model
{
    use HasTranslations, SoftDeletes;

    protected $table = 'ride_pickup_points';

    protected $fillable = ['zone_id', 'lat', 'lng', 'kind', 'for_pickup', 'for_dropoff', 'status', 'sort_order'];

    protected function casts(): array
    {
        return [
            'kind' => PickupPointKind::class,
            'lat' => 'float',
            'lng' => 'float',
            'for_pickup' => 'boolean',
            'for_dropoff' => 'boolean',
            'status' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(RideZone::class, 'zone_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(RidePickupPointTranslation::class);
    }

    protected function translationModel(): string
    {
        return RidePickupPointTranslation::class;
    }
}
