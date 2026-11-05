<?php

namespace Modules\Moving\Models;

use Illuminate\Database\Eloquent\Model;

/** One step of a request: who did what, from which status to which, and where. Rows are only ever added. */
class MovingRequestEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'moving_request_events';

    protected $fillable = ['request_id', 'actor_type', 'actor_id', 'action', 'from_status', 'to_status', 'data', 'lat', 'lng'];

    protected function casts(): array
    {
        return ['data' => 'array', 'lat' => 'float', 'lng' => 'float'];
    }
}
