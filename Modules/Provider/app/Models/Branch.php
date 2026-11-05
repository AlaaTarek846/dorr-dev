<?php

namespace Modules\Provider\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** A place an establishment works from, with the area around it whose requests reach it (§5). */
class Branch extends Model
{
    use SoftDeletes;

    protected $table = 'provider_branches';

    protected $fillable = ['uuid', 'establishment_id', 'name', 'is_main', 'lat', 'lng', 'radius_km', 'address', 'status'];

    protected function casts(): array
    {
        return ['is_main' => 'boolean', 'lat' => 'float', 'lng' => 'float', 'radius_km' => 'float', 'status' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $b) => $b->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class, 'establishment_id');
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'branch_id');
    }
}
