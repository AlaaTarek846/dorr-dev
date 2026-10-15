<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money held in the rider's wallet for a ride (one per bucket, plus increases). */
class RideHold extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }
    public function walletHold(): BelongsTo
    {
        return $this->belongsTo(\Modules\Wallet\Models\WalletHold::class);
    }
}
