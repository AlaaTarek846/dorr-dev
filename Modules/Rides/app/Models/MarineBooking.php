<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\User\Models\User;

/** A guest's place on a sea trip, or a yacht hire (O-25) — with its QR for the captain at the marina. */
class MarineBooking extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (MarineBooking $b) {
            $b->uuid ??= (string) Str::uuid();
            $b->qr_token ??= 'DM-'.Str::upper(Str::random(20));
        });
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'canceled_at' => 'datetime', 'payment_expires_at' => 'datetime', 'discount_minor' => 'integer', 'guests' => 'integer',
            'amount_minor' => 'integer', 'extension_minor' => 'integer', 'extension' => 'array', 'wallet_transaction_ids' => 'array',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(MarineListing::class, 'marine_listing_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
