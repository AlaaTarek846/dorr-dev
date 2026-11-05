<?php

namespace Modules\Moving\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Moving\Enums\OfferStatus;
use Modules\Provider\Models\Branch;
use Modules\Provider\Models\Establishment;
use Modules\Provider\Models\Vehicle;

/** An establishment's price for a request (REQ-021): one per establishment, with what it includes and how long it holds. */
class MovingOffer extends Model
{
    protected $table = 'moving_offers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => OfferStatus::class,
            'includes' => 'array',
            'price_minor' => 'integer',
            'workers_count' => 'integer',
            'trips_count' => 'integer',
            'arrival_at' => 'datetime',
            'valid_until' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $o) => $o->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MovingRequest::class, 'request_id');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function isLive(): bool
    {
        return $this->status === OfferStatus::Sent && $this->valid_until->isFuture();
    }
}
