<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A link to follow a ride live, sent by the rider or the captain to someone they trust (R-86, R-111, D-142). */
class RideShare extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['last_viewed_at' => 'datetime', 'revoked_at' => 'datetime', 'views' => 'integer', 'sharer_id' => 'integer'];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function url(): string
    {
        return url('/ride/'.$this->token);
    }
}
