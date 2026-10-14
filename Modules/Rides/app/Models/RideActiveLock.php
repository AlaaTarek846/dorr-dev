<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A busy marker — `driver:{id}` (a driver's active ride), `rider:{id}` (a rider's active ride).
 * Its primary key is what makes a second accept impossible, on MySQL and SQLite alike.
 */
class RideActiveLock extends Model
{
    public const UPDATED_AT = null;

    protected $primaryKey = 'lock_key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['lock_key', 'ride_id'];

    public static function forDriver(int $driverId): string
    {
        return 'driver:'.$driverId;
    }

    public static function forRider(int $userId): string
    {
        return 'rider:'.$userId;
    }

    /** One live bid per driver at a time (docs/rides-plan.md §9.3). */
    public static function forDriverBid(int $driverId): string
    {
        return 'driver_bid:'.$driverId;
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }
}
