<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Rides\Services\Drivers\DriverServices;

/** O-89: in this country, this kind of vehicle may (or may not) work in this service. */
class DriverServiceRule extends Model
{
    protected $fillable = ['driver_service_id', 'country_id', 'vehicle_kind', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => DriverServices::forget());
        static::deleted(fn () => DriverServices::forget());
    }
}
