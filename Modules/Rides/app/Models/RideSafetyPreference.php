<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

/** A person's safety choices (R-229): the ride code always / at night, a check-in after arriving, the seatbelt reminder. */
class RideSafetyPreference extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['owner_id' => 'integer', 'checkin_after_min' => 'integer', 'seatbelt_reminder' => 'boolean'];
    }
}
