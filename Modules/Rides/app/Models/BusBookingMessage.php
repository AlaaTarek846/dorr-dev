<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

/** A chat message between a bus passenger and the captain (O-68). */
class BusBookingMessage extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
