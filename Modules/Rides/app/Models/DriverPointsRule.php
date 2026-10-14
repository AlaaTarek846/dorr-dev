<?php

namespace Modules\Rides\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * How many points a driver earns (or loses) for an event — a completed trip, an on-time pickup
 * (LeeTaxi's distance_points idea, D-NEW-17), five stars… A null country applies everywhere.
 */
class DriverPointsRule extends Model
{
    use SoftDeletes;

    protected $fillable = ['country_id', 'event', 'points', 'conditions', 'status'];

    protected function casts(): array
    {
        return ['points' => 'integer', 'conditions' => 'array', 'status' => 'boolean'];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
