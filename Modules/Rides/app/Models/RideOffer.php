<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Driver\Models\Driver;
use Modules\Rides\Enums\OfferStatus;

/** A ride offered to one driver in a dispatch round, with what they'd earn (D-56) and a deadline. */
class RideOffer extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (RideOffer $offer): void {
            $offer->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => OfferStatus::class,
            'round' => 'integer',
            'distance_m' => 'integer',
            'eta_s' => 'integer',
            'driver_net_minor' => 'integer',
            'auto_accepted' => 'boolean',
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === OfferStatus::Sent && $this->expires_at->isFuture();
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
