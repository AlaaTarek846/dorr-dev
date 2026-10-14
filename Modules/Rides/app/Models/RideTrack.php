<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The ride's path, compressed — kept after the raw points are purged. */
class RideTrack extends Model
{
    public const UPDATED_AT = null;

    protected $primaryKey = 'ride_id';

    public $incrementing = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['points_count' => 'integer', 'track_distance_m' => 'integer', 'device_distance_m' => 'integer', 'gaps' => 'array'];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }
}
