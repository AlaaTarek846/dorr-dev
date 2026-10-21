<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Something left in the car (D-294..D-297). */
class RideLostItem extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (RideLostItem $i) => $i->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['answered_at' => 'datetime', 'returned_at' => 'datetime'];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(RideLostItemMessage::class);
    }
}
