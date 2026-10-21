<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Points spent on a reward (R-152, R-153). */
class LoyaltyRedemption extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (LoyaltyRedemption $r) => $r->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['points' => 'integer', 'result' => 'array', 'created_at' => 'datetime'];
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(LoyaltyReward::class);
    }
}
