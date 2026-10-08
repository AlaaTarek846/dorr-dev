<?php

namespace Modules\Discover\Models;

use Illuminate\Database\Eloquent\Model;

/** My interests and "don't miss it" alerts (spec 171, 172). */
class DiscoverPreference extends Model
{
    protected $fillable = ['owner_type', 'owner_id', 'categories', 'alerts', 'alert_days', 'family_only'];

    protected $attributes = ['alerts' => true, 'alert_days' => 14, 'family_only' => false];

    protected function casts(): array
    {
        return ['categories' => 'array', 'alerts' => 'boolean', 'alert_days' => 'integer', 'family_only' => 'boolean'];
    }

    /** @return list<int> */
    public function categoryIds(): array
    {
        return array_values(array_map('intval', $this->categories ?? []));
    }
}
