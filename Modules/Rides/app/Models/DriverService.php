<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Rides\Models\Concerns\LocalizedJson;
use Modules\Rides\Services\Drivers\DriverServices;

/** O-89: one service a captain can work in (rides, hourly hire, bus trips…), named and drawn from the dashboard. */
class DriverService extends Model
{
    use LocalizedJson;

    protected $fillable = ['key', 'name', 'description', 'icon', 'color', 'sort_order', 'coming_soon', 'status'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'sort_order' => 'integer',
            'coming_soon' => 'boolean',
            'status' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => DriverServices::forget());
        static::deleted(fn () => DriverServices::forget());
    }

    public function rules(): HasMany
    {
        return $this->hasMany(DriverServiceRule::class);
    }
}
