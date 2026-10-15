<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

/** Someone the rider / the captain trusts: gets the ride's link (each ride, at night, or by hand) and "arrived safely" (R-220, D-143). */
class RideTrustedContact extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['owner_id' => 'integer', 'notify_arrival' => 'boolean'];
    }
}
