<?php

namespace Modules\Rides\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A city's exact official name at the regulator (docs/rides-structure.md §7.2). Wasl rejects a
 * trip when one letter differs, so the name comes only from here — never a translation, never
 * Google — and is locked once a trip was accepted with it.
 */
class RideRegulatorCity extends Model
{
    public const WASL = 'wasl';

    protected $fillable = ['regulator', 'country_id', 'city_id', 'official_name', 'accepted_at', 'last_rejected_at', 'status'];

    protected function casts(): array
    {
        return [
            'city_id' => 'integer',
            'accepted_at' => 'datetime',
            'last_rejected_at' => 'datetime',
            'status' => 'boolean',
        ];
    }

    public function isLocked(): bool
    {
        return $this->accepted_at !== null;
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
