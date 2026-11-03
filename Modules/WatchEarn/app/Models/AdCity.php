<?php

namespace Modules\WatchEarn\Models;

use Illuminate\Database\Eloquent\Model;

/** A city an ad targets (discover_cities.id) — matched by id, never by name (D10). */
class AdCity extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'we_ad_cities';

    protected $fillable = ['ad_id', 'city_id'];

    protected $primaryKey = 'city_id';

    protected function casts(): array
    {
        return ['city_id' => 'integer'];
    }
}
