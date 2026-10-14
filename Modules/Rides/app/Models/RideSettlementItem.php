<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Driver\Models\Driver;
use Modules\Rides\Enums\SettlementItemType;
use Modules\Rides\Enums\SettlementStatus;

/**
 * One money item between DORR and the driver for a ride (+ for the driver, − against them). An
 * online trip's earning stays `pending` until `release_at` — the driver's "pending balance" (D-209)
 * — then `posted` to their wallet; a dispute turns it `held` (D-404).
 */
class RideSettlementItem extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (RideSettlementItem $item): void {
            $item->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'type' => SettlementItemType::class,
            'status' => SettlementStatus::class,
            'amount_minor' => 'integer',
            'release_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
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
