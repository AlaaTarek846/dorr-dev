<?php

namespace Modules\Moving\Models;

use Illuminate\Database\Eloquent\Model;

/** The customer's stars for the establishment and the driver of one move (REQ-034), on the platform's own scale. */
class MovingRating extends Model
{
    protected $table = 'moving_ratings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['establishment_stars' => 'integer', 'driver_stars' => 'integer'];
    }
}
