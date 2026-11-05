<?php

namespace Modules\Moving\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A change to an agreed move that counts only once the other side approves it (REQ-036 → REQ-040, REQ-046): a price
 * difference for more load, trips or workers; the waiting fee; or a new time. The values before and after are kept.
 */
class MovingChangeOrder extends Model
{
    protected $table = 'moving_change_orders';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price_delta_minor' => 'integer',
            'workers_count' => 'integer',
            'trips_count' => 'integer',
            'new_scheduled_at' => 'datetime',
            'before' => 'array',
            'after' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $c) => $c->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MovingRequest::class, 'request_id');
    }
}
