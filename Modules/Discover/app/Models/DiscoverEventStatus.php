<?php

namespace Modules\Discover\Models;

use Illuminate\Database\Eloquent\Model;

/** One change of an event's status or time (spec 175). */
class DiscoverEventStatus extends Model
{
    protected $table = 'discover_event_status_history';

    protected $fillable = ['event_id', 'from_status', 'to_status', 'note', 'old_starts_at', 'changed_by'];

    protected function casts(): array
    {
        return ['old_starts_at' => 'datetime'];
    }
}
