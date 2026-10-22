<?php

namespace Modules\AI\Models\World;

use Illuminate\Database\Eloquent\Model;

/** Price per generated second (minor units) for a country and quality tier. */
class AiWorldPrice extends Model
{
    protected $table = 'ai_world_prices';

    protected $fillable = ['country_id', 'quality', 'price_per_second_minor', 'is_active'];

    protected function casts(): array
    {
        return ['country_id' => 'integer', 'price_per_second_minor' => 'integer', 'is_active' => 'boolean'];
    }
}
