<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One seat on one leg, held by one ticket — its QR is what the captain scans at boarding. */
class BusBookingSeat extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['seat_no' => 'integer', 'boarded_at' => 'datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(BusBooking::class, 'bus_booking_id');
    }
}
